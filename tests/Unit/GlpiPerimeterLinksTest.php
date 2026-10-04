<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Glpi\Mappers\WorkstationMapper;
use App\Services\Glpi\VmLinkSyncService;
use App\Services\Mercator\Contracts\MercatorClientInterface;

// ── Périmètre Mercator : liens, bâtiments et sites restreints au périmètre cible ──
//
// Scénario type : deux instances GLPI alimentent Mercator avec un même compte, chacune
// dans son périmètre. Leurs ids GLPI se recoupent, donc les tags {GLPI}<id> aussi : avec
// --perimeter, aucun lien ne doit être résolu ni écrit sur un objet d'un autre périmètre.

/**
 * Mercator simulé : collections par endpoint, enregistre les update.
 */
function linksMercator(array $collections, array $buildings = [], array $sites = []): object
{
    $recorder = new class
    {
        public array $updated = [];

        public array $created = [];

        public MercatorClientInterface $client;
    };

    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getAll')->andReturnUsing(fn (string $ep) => $collections[$ep] ?? []);
    $mock->shouldReceive('getBuildings')->andReturn($buildings);
    $mock->shouldReceive('getSites')->andReturn($sites);
    $mock->shouldReceive('update')->andReturnUsing(function ($ep, $id, $payload) use ($recorder) {
        $recorder->updated[$ep][$id] = $payload;

        return $payload;
    });
    $mock->shouldReceive('create')->andReturnUsing(function ($ep, $payload) use ($recorder) {
        $recorder->created[$ep][$payload['name']] = $payload;

        return ['id' => 999];
    });
    $recorder->client = $mock;

    return $recorder;
}

// ── links (workstation ↔ application) ────────────────────────────────────────

function linksGlpi(): GlpiClientInterface
{
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Computer', Mockery::any())->andReturn([['id' => 1, 'name' => 'PC-1']]);
    $glpi->shouldReceive('getItem')->with('Computer', 1, Mockery::any())->andReturn(['_softwares' => [['softwares_id' => 9]]]);

    return $glpi;
}

it('links : ne lie que le poste et les applications du périmètre cible', function () {
    $mercator = linksMercator([
        'workstations' => [
            ['id' => 10, 'name' => 'PC-1', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 5],
            ['id' => 11, 'name' => 'PC-AUTRE', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 1],
        ],
        'applications' => [
            ['id' => 40, 'name' => 'Firefox', 'ext_refs' => '{GLPI}9', 'perimeter_id' => 5],
            ['id' => 30, 'name' => 'Logiciel-autre', 'ext_refs' => '{GLPI}9', 'perimeter_id' => 1],
        ],
    ]);

    (new GlpiSyncService)->syncLinks(linksGlpi(), $mercator->client, false, 5);

    expect($mercator->updated['workstations'])->toBe([10 => ['name' => 'PC-1', 'applications' => [40]]]);
});

it('links : ignore un poste présent seulement dans un autre périmètre', function () {
    $mercator = linksMercator([
        'workstations' => [['id' => 11, 'name' => 'PC-1', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 1]],
        'applications' => [['id' => 40, 'name' => 'Firefox', 'ext_refs' => '{GLPI}9', 'perimeter_id' => 5]],
    ]);

    $stats = (new GlpiSyncService)->syncLinks(linksGlpi(), $mercator->client, false, 5);

    expect($stats['updated'])->toBe(0)
        ->and($mercator->updated)->toBe([]);
});

it('links : sans périmètre, tous les périmètres restent candidats (comportement historique)', function () {
    $mercator = linksMercator([
        'workstations' => [['id' => 11, 'name' => 'PC-1', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 1]],
        'applications' => [['id' => 40, 'name' => 'Firefox', 'ext_refs' => '{GLPI}9', 'perimeter_id' => 5]],
    ]);

    (new GlpiSyncService)->syncLinks(linksGlpi(), $mercator->client, false, null);

    expect($mercator->updated['workstations'][11]['applications'])->toBe([40]);
});

// ── activity_links (activité ↔ application) ──────────────────────────────────

it('activity_links : ignore une activité d\'un autre périmètre', function () {
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Appliance', Mockery::any())->andReturn([['id' => 2, 'name' => 'ERP']]);
    $glpi->shouldReceive('getSubItems')->andReturn([['id' => 1, 'appliances_id' => 2, 'items_id' => 9, 'itemtype' => 'Software']]);
    $glpi->shouldReceive('getItem')->andReturn(['name' => 'PostgreSQL']);

    $mercator = linksMercator([
        'activities' => [['id' => 31, 'name' => 'ERP', 'ext_refs' => '{GLPI}2', 'perimeter_id' => 1]],
        'applications' => [['id' => 40, 'name' => 'PostgreSQL', 'ext_refs' => '{GLPI}9', 'perimeter_id' => 5]],
    ]);

    $stats = (new GlpiSyncService)->syncActivityLinks($glpi, $mercator->client, false, 5);

    expect($stats['updated'])->toBe(0)
        ->and($mercator->updated)->toBe([]);
});

// ── appliance_links (application ↔ serveur logique) ──────────────────────────

it('appliance_links : ne lie que les serveurs logiques du périmètre cible', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Appliance', Mockery::any())->andReturn([['id' => 2, 'name' => 'ERP']]);
    $glpi->shouldReceive('getSubItems')->andReturn([['id' => 1, 'appliances_id' => 2, 'items_id' => 5, 'itemtype' => 'Computer']]);
    $glpi->shouldReceive('getItem')->andReturn(['name' => 'VM-APP']);

    $mercator = linksMercator([
        'applications' => [['id' => 70, 'name' => 'ERP', 'ext_refs' => '{GLPI-Appliance}2', 'perimeter_id' => 5]],
        'logical-servers' => [
            ['id' => 62, 'name' => 'VM-APP', 'ext_refs' => '{GLPI}5', 'perimeter_id' => 5],
            ['id' => 61, 'name' => 'VM-AUTRE', 'ext_refs' => '{GLPI}5', 'perimeter_id' => 1],
        ],
    ]);

    (new GlpiSyncService)->syncApplianceLinks($glpi, $mercator->client, false, 5);

    expect($mercator->updated['applications'][70]['logical_servers'])->toBe([62]);
});

// ── database_links (base de données ↔ serveur logique) ───────────────────────

it('database_links : ne lie pas une base à un serveur logique d\'un autre périmètre', function () {
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Database', Mockery::any())->andReturn([['id' => 1, 'name' => 'erp_db', 'databaseinstances_id' => 1]]);
    $glpi->shouldReceive('getItem')->with('DatabaseInstance', 1, Mockery::any())->andReturn(['itemtype' => 'Computer', 'items_id' => 5]);
    $glpi->shouldReceive('getItem')->with('Computer', 5)->andReturn(['name' => 'VM-APP']);

    $mercator = linksMercator([
        'databases' => [['id' => 51, 'name' => 'erp_db', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 5]],
        'logical-servers' => [['id' => 61, 'name' => 'VM-APP', 'ext_refs' => '{GLPI}5', 'perimeter_id' => 1]],
    ]);

    $stats = (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator->client, false, 5);

    expect($stats['updated'])->toBe(0)
        ->and($mercator->updated)->toBe([]);
});

// ── vm_links (serveur logique ↔ serveur physique) ────────────────────────────

it('vm_links : ne lie ni ne nettoie les serveurs logiques d\'un autre périmètre', function () {
    config([
        'glpi.computer_types.physical_servers' => ['Serveur physique'],
        'glpi.computer_types.logical_servers' => ['Machine virtuelle'],
    ]);

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Computer', Mockery::any())->andReturn([
        ['id' => 4, 'name' => 'HOST', 'computertypes_id' => 'Serveur physique'],
        ['id' => 5, 'name' => 'VM-1', 'computertypes_id' => 'Machine virtuelle', 'uuid' => 'abc'],
    ]);
    $glpi->shouldReceive('getItems')->with('ItemVirtualMachine', Mockery::any())->andReturn([
        ['itemtype' => 'Computer', 'items_id' => 4, 'name' => 'VM-1', 'uuid' => 'abc', 'is_deleted' => 0],
    ]);
    $glpi->shouldReceive('withoutEntityRestriction')->andReturnUsing(fn (callable $cb) => $cb());

    $mercator = linksMercator([
        'logical-servers' => [
            ['id' => 61, 'name' => 'VM-1', 'ext_refs' => '{GLPI}5', 'perimeter_id' => 5],
            // Autre instance : serveur logique tagué sans hôte résolu ici — sans
            // restriction, il recevrait un PUT physical_servers=[] (nettoyage)
            ['id' => 63, 'name' => 'VM-AUTRE', 'ext_refs' => '{GLPI}8', 'perimeter_id' => 1],
        ],
        'physical-servers' => [
            ['id' => 71, 'name' => 'HOST', 'ext_refs' => '{GLPI}4', 'perimeter_id' => 5],
            ['id' => 72, 'name' => 'HOST-AUTRE', 'ext_refs' => '{GLPI}4', 'perimeter_id' => 1],
        ],
    ]);

    (new VmLinkSyncService)->sync($glpi, $mercator->client, false, 5);

    expect($mercator->updated['logical-servers'])->toBe([61 => ['name' => 'VM-1', 'physical_servers' => [71]]]);
});

// ── Bâtiments et sites ───────────────────────────────────────────────────────

function perimeterLocationGlpi(string $location): GlpiClientInterface
{
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->andReturn([['id' => 2, 'name' => 'PC-2', 'locations_id' => $location]]);
    $glpi->shouldReceive('getItem')->andReturn([]);
    $glpi->shouldReceive('getSubItems')->andReturn([]);

    return $glpi;
}

it('ne rattache pas un objet à un bâtiment ou un site d\'un autre périmètre', function () {
    config(['glpi.computer_types.workstations' => []]);

    $mercator = linksMercator(
        [],
        [['id' => 10, 'name' => 'Salle 101', 'site_id' => 1, 'perimeter_id' => 1]],
        [['id' => 1, 'name' => 'Siège', 'perimeter_id' => 1]],
    );

    $handler = new WorkstationSyncHandler(new WorkstationMapper);

    // Bâtiment homonyme dans le périmètre 1 uniquement
    (new GlpiSyncService)->sync(perimeterLocationGlpi('Siège > Salle 101'), $mercator->client, $handler, false, 5);
    expect($mercator->created['workstations']['PC-2'])->toMatchArray(['building_id' => null, 'site_id' => null]);

    // Site homonyme dans le périmètre 1 uniquement
    (new GlpiSyncService)->sync(perimeterLocationGlpi('Siège'), $mercator->client, $handler, false, 5);
    expect($mercator->created['workstations']['PC-2'])->toMatchArray(['building_id' => null, 'site_id' => null]);

    // Sans périmètre : résolution globale (comportement historique)
    (new GlpiSyncService)->sync(perimeterLocationGlpi('Siège > Salle 101'), $mercator->client, $handler, false, null);
    expect($mercator->created['workstations']['PC-2'])->toMatchArray(['building_id' => 10, 'site_id' => 1]);
});
