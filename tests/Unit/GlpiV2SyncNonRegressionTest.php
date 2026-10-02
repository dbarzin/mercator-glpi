<?php

use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\GlpiV2Client;
use App\Services\Glpi\Handlers\DomainSyncHandler;
use App\Services\Glpi\Handlers\LocationSyncHandler;
use App\Services\Glpi\Handlers\PhysicalServerSyncHandler;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Glpi\Mappers\DomainMapper;
use App\Services\Glpi\Mappers\LocationMapper;
use App\Services\Glpi\Mappers\PhysicalServerMapper;
use App\Services\Glpi\Mappers\WorkstationMapper;
use App\Services\Glpi\VmLinkSyncService;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// ── Non-régression bout en bout : GLPI API v2 → GlpiSyncService → payloads Mercator ──
//
// tests/Fixtures/glpi_v2_api.json contient des réponses RÉELLES d'un GLPI 11.0.8 (API
// 2.3), y compris le défaut de completename/level à null en collection pour les nœuds
// parents. Les payloads attendus sont ceux produits par le connecteur en API v1 sur le
// même jeu de données (dry-run comparatif v1/v2), aux écarts documentés près :
// address_ip lue dans l'inventaire du GLPI Agent (absente pour un poste sans agent, l'API
// v2 n'exposant pas les IP), disk désormais calculé (le format _disks v1 n'était pas
// exploité par le mapper), champs de bruit v1 (ticket_tco "0.0000", ancestors_cache)
// absents de la description.

beforeEach(function () {
    date_default_timezone_set('UTC');

    config([
        'glpi.computer_types.workstations' => ['Poste de travail'],
        'glpi.computer_types.logical_servers' => ['Serveur virtuel'],
        'glpi.computer_types.physical_servers' => ['Serveur physique'],
        'glpi.allowed_states' => ['default' => []],
    ]);

    $GLOBALS['glpiV2Routes'] = json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_v2_api.json'), true);

    Http::fake(function (Request $request) {
        $routes = $GLOBALS['glpiV2Routes'];

        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($path === '/api.php/token') {
            return Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'tok']);
        }

        $path = substr($path, strlen('/api.php/v2/'));

        if ($path === 'GraphQL/') {
            preg_match('/^\{ (\w+)\((?:filter: "([^"]*)", )?/', $request['query'], $m);
            $key = 'GraphQL '.$m[1].(($m[2] ?? '') !== '' ? '|'.$m[2] : '');
        } else {
            $key = 'GET '.$path;
        }

        $route = $routes[$key] ?? ['status' => 404, 'body' => ['status' => 'ERROR_ITEM_NOT_FOUND'], 'headers' => []];

        return Http::response($route['body'], $route['status'], $route['headers']);
    });
});

function v2Glpi(?int $entityId = null): GlpiV2Client
{
    $client = new GlpiV2Client([
        'url' => 'http://glpi.test',
        'client_id' => 'CID', 'client_secret' => 'SECRET', 'username' => 'glpi', 'password' => 'glpi',
        'entity_id' => $entityId,
    ]);
    $client->authenticate();

    return $client;
}

/**
 * Mercator simulé : collections par endpoint, enregistre les create/update.
 */
function v2Mercator(array $collections = [], array $buildings = [], array $sites = []): object
{
    $recorder = new class
    {
        public array $created = [];

        public array $updated = [];
    };

    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getBuildings')->andReturn($buildings);
    $mock->shouldReceive('getSites')->andReturn($sites);
    $mock->shouldReceive('getAll')->andReturnUsing(fn (string $ep) => $collections[$ep] ?? []);
    $mock->shouldReceive('create')->andReturnUsing(function (string $ep, array $payload) use ($recorder) {
        $recorder->created[$ep][$payload['name']] = $payload;

        return ['id' => 100 + count($recorder->created[$ep])];
    });
    $mock->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use ($recorder) {
        $recorder->updated[$ep][$id] = $payload;

        return $payload;
    });
    $recorder->client = $mock;

    return $recorder;
}

it('produit les mêmes payloads workstation qu\'en API v1 (hors address_ip)', function () {
    $mercator = v2Mercator([], [['id' => 7, 'name' => 'Salle 101', 'site_id' => 1], ['id' => 8, 'name' => 'Bâtiment A', 'site_id' => 1]]);

    $stats = (new GlpiSyncService)->sync(v2Glpi(), $mercator->client, new WorkstationSyncHandler(new WorkstationMapper));

    expect($stats)->toMatchArray(['created' => 3, 'errors' => 0])
        ->and($mercator->created['workstations']['PC-001'])->toBe([
            'name' => 'PC-001',
            'description' => 'Poste accueil',
            'type' => 'Poste de travail',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex 7090',
            'serial_number' => 'SN-PC-001',
            'operating_system' => 'Windows 11 — 23H2',
            'status' => 'En production',
            'other_user' => 'glpi',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'network_port_type' => 'Ethernet',
            'cpu' => 'Intel Core i7-11700 — 2500 MHz — 8 cœurs',
            'disk' => 512000,
            'purchase_date' => '2024-01-15',
            'warranty_start_date' => '2024-01-02',
            'warranty_period' => '36 mois',
            'fin_value' => 1200.0,
            'update_source' => 'GLPI',
            'building_id' => 7,
            'site_id' => 1,
            'ext_refs' => '{GLPI}2',
        ])
        ->and($mercator->created['workstations']['PC-002'])->toMatchArray([
            'type' => 'Poste de travail',
            'manufacturer' => 'HP',
            'serial_number' => 'SN-PC-002',
            'status' => 'En stock',
            'building_id' => 8,
            'ext_refs' => '{GLPI}3',
        ]);
});

it('renseigne address_ip depuis le dernier inventaire du GLPI Agent', function () {
    $mercator = v2Mercator();

    (new GlpiSyncService)->sync(v2Glpi(), $mercator->client, new WorkstationSyncHandler(new WorkstationMapper));

    expect($mercator->created['workstations']['PC-AGENT-01'])->toMatchArray([
        'serial_number' => 'SN-AG-01',
        'address_ip' => '192.168.10.21',
        'mac_address' => 'AA:BB:CC:11:22:33',
        'network_port_type' => 'Ethernet',
        'ext_refs' => '{GLPI}6',
    ])
        // IP saisie manuellement sur un poste sans agent : non exposée par l'API v2
        ->and($mercator->created['workstations']['PC-001'])->not->toHaveKey('address_ip');
});

it('résout le bay_id d\'un serveur physique via les items des Rack v2', function () {
    $mercator = v2Mercator(['bays' => [['id' => 42, 'name' => 'RACK-01', 'ext_refs' => '{GLPI}1']]]);

    (new GlpiSyncService)->sync(v2Glpi(), $mercator->client, new PhysicalServerSyncHandler(new PhysicalServerMapper(new WorkstationMapper)));

    expect($mercator->created['physical-servers']['SRV-PHY-01'])->toMatchArray([
        'type' => 'Serveur physique',
        'operating_system' => 'Debian — 12',
        'mac_address' => 'AA:BB:CC:DD:EE:02',
        'disk' => 2100000,
        'bay_id' => 42,
        'ext_refs' => '{GLPI}4',
    ]);
});

it('crée les Building parents avant leurs enfants malgré level=null renvoyé par GLPI 11', function () {
    // Arbre Siège > Bâtiment A > Étage 1 > Salle 101, l'étage (id 2) étant listé AVANT
    // son parent Bâtiment A (id 6). GLPI 11.0.8 renvoie level=null pour les deux nœuds
    // intermédiaires : sans recalcul, ils sont triés à égalité (level 0) dans l'ordre de
    // l'API, et Étage 1 est créé avant Bâtiment A, donc sans building_id parent.
    $GLOBALS['glpiV2Routes']['GET Dropdowns/Location'] = ['status' => 200, 'headers' => ['Content-Range' => '0-3/4'], 'body' => [
        ['id' => 1, 'name' => 'Siège', 'completename' => null, 'level' => null, 'parent' => null, 'entity' => ['id' => 0, 'name' => 'Entité racine']],
        ['id' => 2, 'name' => 'Étage 1', 'completename' => null, 'level' => null, 'parent' => ['id' => 6, 'name' => 'Bâtiment A'], 'entity' => ['id' => 0, 'name' => 'Entité racine']],
        ['id' => 3, 'name' => 'Salle 101', 'completename' => 'Siège > Bâtiment A > Étage 1 > Salle 101', 'level' => 4, 'parent' => ['id' => 2, 'name' => 'Étage 1'], 'entity' => ['id' => 0, 'name' => 'Entité racine']],
        ['id' => 6, 'name' => 'Bâtiment A', 'completename' => null, 'level' => null, 'parent' => ['id' => 1, 'name' => 'Siège'], 'entity' => ['id' => 0, 'name' => 'Entité racine']],
    ]];

    $mercator = v2Mercator([], [], [['id' => 1, 'name' => 'Siège']]);

    (new GlpiSyncService)->sync(v2Glpi(), $mercator->client, new LocationSyncHandler(new LocationMapper));

    $buildings = $mercator->created['buildings'];

    expect(array_keys($buildings))->toBe(['Bâtiment A', 'Étage 1', 'Salle 101'])
        ->and($buildings['Bâtiment A'])->toMatchArray(['site_id' => 1, 'building_id' => null])
        ->and($buildings['Étage 1'])->toMatchArray(['building_id' => 101, 'site_id' => 1])
        ->and($buildings['Salle 101'])->toMatchArray(['building_id' => 102, 'site_id' => 1]);
});

it('filtre les Domain sur l\'entité configurée (chemin complet résolu malgré le défaut GLPI)', function () {
    $mercator = v2Mercator();

    (new GlpiSyncService)->sync(v2Glpi(entityId: 1), $mercator->client, new DomainSyncHandler(new DomainMapper));

    expect(array_keys($mercator->created['domains']))->toBe(['filiale.example']);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'Management/Domain') && $r->hasHeader('GLPI-Entity', '1'));
});

it('lie les postes à leurs applications (with_softwares via GraphQL)', function () {
    $mercator = v2Mercator([
        'workstations' => [['id' => 11, 'name' => 'PC-001', 'ext_refs' => '{GLPI}2']],
        'applications' => [['id' => 21, 'name' => 'Firefox', 'ext_refs' => '{GLPI}2'], ['id' => 22, 'name' => 'PostgreSQL', 'ext_refs' => '{GLPI}3']],
    ]);

    $stats = (new GlpiSyncService)->syncLinks(v2Glpi(), $mercator->client);

    expect($stats['updated'])->toBe(1)
        ->and($mercator->updated['workstations'][11])->toBe(['name' => 'PC-001', 'applications' => [21]]);
});

it('lie les applications aux activités via Appliance_Item (GraphQL)', function () {
    $mercator = v2Mercator([
        'activities' => [['id' => 31, 'name' => 'ERP', 'ext_refs' => '{GLPI}2']],
        'applications' => [['id' => 22, 'name' => 'PostgreSQL', 'ext_refs' => '{GLPI}3']],
    ]);

    $stats = (new GlpiSyncService)->syncActivityLinks(v2Glpi(), $mercator->client);

    expect($stats['updated'])->toBe(1)
        ->and($mercator->updated['applications'][22])->toBe(['name' => 'PostgreSQL', 'activities' => [31]]);
});

it('lie les bases de données à leur serveur logique hôte via DatabaseInstance', function () {
    $mercator = v2Mercator([
        'databases' => [['id' => 51, 'name' => 'erp_db', 'ext_refs' => '{GLPI}1']],
        'logical-servers' => [['id' => 61, 'name' => 'VM-APP-01', 'ext_refs' => '{GLPI}5']],
    ]);

    (new GlpiSyncService)->syncDatabaseLinks(v2Glpi(), $mercator->client);

    expect($mercator->updated['databases'][51])->toBe(['name' => 'erp_db', 'logical_servers' => [61]]);
});

it('lie les VM à leur hyperviseur via VirtualMachine (GraphQL)', function () {
    $mercator = v2Mercator([
        'logical-servers' => [['id' => 61, 'name' => 'VM-APP-01', 'ext_refs' => '{GLPI}5']],
        'physical-servers' => [['id' => 71, 'name' => 'SRV-PHY-01', 'ext_refs' => '{GLPI}4']],
    ]);

    $stats = (new VmLinkSyncService)->sync(v2Glpi(), $mercator->client);

    expect($stats['updated'])->toBe(1)
        ->and($mercator->updated['logical-servers'][61])->toBe(['name' => 'VM-APP-01', 'physical_servers' => [71]]);
});
