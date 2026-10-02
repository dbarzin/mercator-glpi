<?php

use App\Services\Glpi\GlpiV2Client;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// ── GlpiV2Client : API GLPI v2 (High-Level API, OAuth2) exposée sous l'interface v1 ──

function makeGlpiV2Client(array $overrides = []): GlpiV2Client
{
    return new GlpiV2Client(array_merge([
        'url' => 'http://glpi.test',
        'client_id' => 'CID',
        'client_secret' => 'SECRET',
        'username' => 'api-user',
        'password' => 'pwd',
        'scope' => 'api graphql',
        'entity_id' => null,
    ], $overrides));
}

function glpiV2Token(int $expiresIn = 3600, string $token = 'tok'): array
{
    return ['token_type' => 'Bearer', 'expires_in' => $expiresIn, 'access_token' => $token, 'refresh_token' => 'refresh'];
}

/**
 * Faux serveur GLPI v2 : $routes associe "METHODE chemin" (sans /api.php/v2/) à une
 * réponse ; les requêtes GraphQL sont routées par nom de type ("GraphQL NetworkPort").
 */
function fakeGlpiV2(array $routes): void
{
    Http::fake(function (Request $request) use ($routes) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($path === '/api.php/token') {
            return $routes['token'] ?? Http::response(glpiV2Token());
        }

        $path = substr($path, strlen('/api.php/v2/'));

        if ($path === 'GraphQL/') {
            preg_match('/^\{ (\w+)\(/', $request['query'], $m);
            $key = 'GraphQL '.$m[1];
        } else {
            $key = $request->method().' '.$path;
        }

        $response = $routes[$key] ?? Http::response(['status' => 'ERROR_ITEM_NOT_FOUND', 'title' => 'Not found'], 404);

        return is_callable($response) ? $response($request) : $response;
    });
}

function graphqlResponse(string $type, array $rows): PromiseInterface
{
    return Http::response([
        'data' => [$type => $rows],
        'extensions' => ['pagination' => [$type => ['start' => 0, 'limit' => 1000, 'total_count' => count($rows)]]],
    ]);
}

// ── Authentification ─────────────────────────────────────────────────────────

it('s\'authentifie en OAuth2 (grant password) puis envoie le jeton Bearer', function () {
    fakeGlpiV2(['GET Assets/Phone' => Http::response([])]);

    $client = makeGlpiV2Client();
    $client->authenticate();
    $client->getItems('Phone');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://glpi.test/api.php/token'
        && $r['grant_type'] === 'password'
        && $r['client_id'] === 'CID'
        && $r['client_secret'] === 'SECRET'
        && $r['username'] === 'api-user'
        && $r['password'] === 'pwd'
        && $r['scope'] === 'api graphql');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api.php/v2/Assets/Phone')
        && $r->hasHeader('Authorization', 'Bearer tok'));
});

it('signale clairement une configuration OAuth incomplète', function () {
    $client = makeGlpiV2Client(['client_id' => '']);

    expect(fn () => $client->authenticate())
        ->toThrow(RuntimeException::class, 'GLPI_CLIENT_ID manquant');
});

it('remonte le statut et le message OAuth en cas d\'échec d\'authentification', function () {
    fakeGlpiV2(['token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'The user credentials were incorrect.'], 400)]);

    expect(fn () => makeGlpiV2Client()->authenticate())
        ->toThrow(RuntimeException::class, 'Authentification GLPI (OAuth2) échouée : 400 — The user credentials were incorrect.');
});

it('refuse toute requête avant authenticate()', function () {
    expect(fn () => makeGlpiV2Client()->getItems('Phone'))
        ->toThrow(RuntimeException::class, 'non authentifié');
});

it('renouvelle le jeton (refresh_token) quand il arrive à expiration', function () {
    $tokenCalls = 0;
    fakeGlpiV2([
        'token' => function () use (&$tokenCalls) {
            $tokenCalls++;

            return Http::response(glpiV2Token($tokenCalls === 1 ? 30 : 3600, 'tok'.$tokenCalls));
        },
        'GET Assets/Phone' => Http::response([]),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();
    $client->getItems('Phone');

    expect($tokenCalls)->toBe(2);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api.php/token') && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'Assets/Phone') && $r->hasHeader('Authorization', 'Bearer tok2'));
});

it('rejoue une fois la requête après un 401 en renouvelant le jeton', function () {
    $phoneCalls = 0;
    fakeGlpiV2([
        'GET Assets/Phone' => function () use (&$phoneCalls) {
            return ++$phoneCalls === 1 ? Http::response(['status' => 'ERROR_UNAUTHENTICATED'], 401) : Http::response([['id' => 1, 'name' => 'TEL']]);
        },
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItems('Phone'))->toHaveCount(1)
        ->and($phoneCalls)->toBe(2);
});

it('tolère une GLPI_URL saisie avec /apirest.php (héritage v1)', function () {
    fakeGlpiV2(['GET Assets/Phone' => Http::response([])]);

    $client = makeGlpiV2Client(['url' => 'http://glpi.test/apirest.php/']);
    $client->authenticate();
    $client->getItems('Phone');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://glpi.test/api.php/v2/Assets/Phone'));
});

// ── Collections ──────────────────────────────────────────────────────────────

it('pagine via start/limit tant que Content-Range indique des items restants', function () {
    $starts = [];
    fakeGlpiV2([
        'GET Assets/Software' => function (Request $r) use (&$starts) {
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
            $starts[] = (int) $q['start'];

            return match ((int) $q['start']) {
                0 => Http::response(array_fill(0, 1000, ['id' => 1, 'name' => 'a']), 206, ['Content-Range' => '0-999/2500']),
                1000 => Http::response(array_fill(0, 1000, ['id' => 2, 'name' => 'b']), 206, ['Content-Range' => '1000-1999/2500']),
                default => Http::response(array_fill(0, 500, ['id' => 3, 'name' => 'c']), 200, ['Content-Range' => '2000-2499/2500']),
            };
        },
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItems('Software', ['range' => '0-999']))->toHaveCount(2500)
        ->and($starts)->toBe([0, 1000, 2000]);
});

it('exclut les items en corbeille et les gabarits, comme l\'API v1', function () {
    fakeGlpiV2(['GET Assets/Computer' => Http::response([
        ['id' => 1, 'name' => 'OK', 'is_deleted' => false, 'is_template' => false],
        ['id' => 2, 'name' => 'Corbeille', 'is_deleted' => true, 'is_template' => false],
        ['id' => 3, 'name' => 'Gabarit', 'is_deleted' => false, 'is_template' => true],
    ])]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect(array_column($client->getItems('Computer'), 'name'))->toBe(['OK']);
});

it('route chaque itemtype v1 vers sa ressource v2', function (string $itemType, string $route) {
    fakeGlpiV2(["GET {$route}" => Http::response([['id' => 1, 'name' => 'x']])]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItems($itemType))->toHaveCount(1);
})->with([
    ['Computer', 'Assets/Computer'],
    ['NetworkEquipment', 'Assets/NetworkEquipment'],
    ['Phone', 'Assets/Phone'],
    ['Peripheral', 'Assets/Peripheral'],
    ['Rack', 'Assets/Rack'],
    ['Software', 'Assets/Software'],
    ['Appliance', 'Assets/Appliance'],
    ['Certificate', 'Assets/Certificate'],
    ['Cluster', 'Management/Cluster'],
    ['Database', 'Management/Database'],
    ['DatabaseInstance', 'Management/DatabaseInstance'],
    ['Domain', 'Management/Domain'],
    ['Location', 'Dropdowns/Location'],
    ['Entity', 'Administration/Entity'],
]);

it('rejette un itemtype non exposé par l\'API v2', function () {
    fakeGlpiV2([]);
    $client = makeGlpiV2Client();
    $client->authenticate();

    expect(fn () => $client->getItems('Monitor'))
        ->toThrow(RuntimeException::class, "Itemtype GLPI non supporté par l'API v2 : Monitor");
});

it('expanse les dropdowns arborescents en chemin complet (une seule requête de résolution)', function () {
    fakeGlpiV2([
        'GET Assets/Computer' => Http::response([
            ['id' => 1, 'name' => 'PC-1', 'location' => ['id' => 3, 'name' => 'Salle 101']],
            ['id' => 2, 'name' => 'PC-2', 'location' => ['id' => 3, 'name' => 'Salle 101']],
        ]),
        'GET Dropdowns/Location' => Http::response([
            ['id' => 3, 'name' => 'Salle 101', 'completename' => 'Siège > Bâtiment A > Salle 101'],
        ]),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();
    $items = $client->getItems('Computer', ['expand_dropdowns' => 1]);

    expect(array_column($items, 'locations_id'))->toBe(['Siège > Bâtiment A > Salle 101', 'Siège > Bâtiment A > Salle 101']);
    Http::assertSentCount(3); // token + Computer + Location (cache)
});

it('garde les ids numériques avec expand_dropdowns=0', function () {
    fakeGlpiV2(['GET Management/Database' => Http::response([
        ['id' => 4, 'name' => 'erp', 'instance' => ['id' => 7, 'name' => 'PG-PROD']],
    ])]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItems('Database', ['expand_dropdowns' => 0])[0]['databaseinstances_id'])->toBe(7);
});

// ── Entité ───────────────────────────────────────────────────────────────────

it('restreint chaque requête à l\'entité configurée (récursif) via les en-têtes GLPI-Entity', function () {
    fakeGlpiV2(['GET Assets/Phone' => Http::response([])]);

    $client = makeGlpiV2Client(['entity_id' => 3]);
    $client->authenticate();
    $client->getItems('Phone');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'Assets/Phone')
        && $r->hasHeader('GLPI-Entity', '3')
        && $r->hasHeader('GLPI-Entity-Recursive', 'true'));
});

it('n\'envoie aucun en-tête d\'entité sans entité configurée', function () {
    fakeGlpiV2(['GET Assets/Phone' => Http::response([])]);

    $client = makeGlpiV2Client();
    $client->authenticate();
    $client->getItems('Phone');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'Assets/Phone') && ! $r->hasHeader('GLPI-Entity'));
});

it('lève la restriction d\'entité le temps de withoutEntityRestriction() puis la rétablit', function () {
    fakeGlpiV2(['GET Assets/Phone' => Http::response([])]);

    $client = makeGlpiV2Client();
    $client->setEntityId(5);
    $client->authenticate();

    $client->withoutEntityRestriction(fn () => $client->getItems('Phone'));
    $client->getItems('Phone');

    $phoneRequests = Http::recorded(fn (Request $r) => str_contains($r->url(), 'Assets/Phone'))->map(fn ($pair) => $pair[0])->values();

    expect($phoneRequests[0]->hasHeader('GLPI-Entity'))->toBeFalse()
        ->and($phoneRequests[1]->hasHeader('GLPI-Entity', '5'))->toBeTrue()
        ->and($client->getEntityId())->toBe(5);
});

// ── Détail d'un item (with_*) ────────────────────────────────────────────────

it('enrichit un Computer comme les paramètres with_* de l\'API v1', function () {
    fakeGlpiV2([
        'GET Assets/Computer/2' => Http::response(['id' => 2, 'name' => 'PC-001', 'type' => ['id' => 1, 'name' => 'Poste']]),
        'GET Assets/Computer/2/Component/Processor' => Http::response([
            ['id' => 1, 'frequency' => '2500', 'nbcores' => 8, 'processor' => ['id' => 1, 'designation' => 'Intel Core i7-11700']],
        ]),
        'GET Assets/Computer/2/Volume' => Http::response([
            ['id' => 1, 'name' => 'C:', 'mount_point' => 'C:', 'total_size' => 512000, 'free_size' => 1000],
        ]),
        'GET Assets/Computer/2/Infocom' => Http::response([
            'id' => 1, 'date_buy' => '2024-01-15', 'date_order' => '2024-01-02', 'warranty_duration' => 36, 'value' => 1200,
        ]),
        'GraphQL NetworkPort' => graphqlResponse('NetworkPort', [
            ['id' => 1, 'name' => 'eth0', 'mac' => 'aa:bb:cc:dd:ee:01', 'instantiation_type' => 'NetworkPortEthernet', 'logical_number' => 1, 'is_deleted' => false],
            ['id' => 2, 'name' => 'old', 'mac' => 'aa:bb:cc:dd:ee:99', 'instantiation_type' => 'NetworkPortEthernet', 'logical_number' => 2, 'is_deleted' => true],
            ['id' => 3, 'name' => 'wlan0', 'mac' => 'aa:bb:cc:dd:ee:02', 'instantiation_type' => 'NetworkPortWifi', 'logical_number' => 3, 'is_deleted' => false],
        ]),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    $item = $client->getItem('Computer', 2, [
        'expand_dropdowns' => 1, 'with_networkports' => 1, 'with_devices' => 1, 'with_disks' => 1, 'with_infocoms' => 1,
    ]);

    expect($item['computertypes_id'])->toBe('Poste')
        ->and($item['_devices']['Item_DeviceProcessor'][0])->toMatchArray(['deviceprocessors_id' => 'Intel Core i7-11700', 'frequency' => '2500', 'nbcores' => 8])
        ->and($item['_disks'][0]['totalsize'])->toBe(512000)
        ->and($item['_infocoms'])->toMatchArray(['buy_date' => '2024-01-15', 'order_date' => '2024-01-02', 'warranty_duration' => 36, 'value' => 1200])
        ->and(array_column($item['_networkports']['NetworkPortEthernet'], 'mac'))->toBe(['aa:bb:cc:dd:ee:01'])
        ->and($item['_networkports']['NetworkPortEthernet'][0]['NetworkName']['IPAddress'])->toBe([])
        ->and($item['_networkports']['NetworkPortWifi'][0]['mac'])->toBe('aa:bb:cc:dd:ee:02');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'GraphQL')
        && str_contains($r['query'], 'filter: "itemtype==Computer;items_id==2"'));
});

it('renvoie _infocoms vide quand GLPI répond 404 (pas d\'infos financières)', function () {
    fakeGlpiV2(['GET Assets/Computer/2' => Http::response(['id' => 2, 'name' => 'PC'])]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItem('Computer', 2, ['with_infocoms' => 1])['_infocoms'])->toBe([]);
});

it('dégrade sans échec si les ports réseau ne sont pas accessibles (scope graphql absent)', function () {
    Log::spy();
    fakeGlpiV2([
        'GET Assets/Computer/2' => Http::response(['id' => 2, 'name' => 'PC']),
        'GraphQL NetworkPort' => Http::response(['status' => 'ERROR_RIGHT_MISSING', 'title' => "You don't have permission", 'detail' => 'You do not have the required scope(s) to access this endpoint.'], 403),
        'GET Inventory/Agent' => Http::response([]),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItem('Computer', 2, ['with_networkports' => 1])['_networkports'])->toBe([]);
    $client->getItem('Computer', 2, ['with_networkports' => 1]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'Ports réseau indisponibles') && str_contains($msg, 'graphql'));
});

it('liste les logiciels installés (with_softwares) avec l\'id numérique du Software', function () {
    fakeGlpiV2([
        'GET Assets/Computer/2' => Http::response(['id' => 2, 'name' => 'PC']),
        'GraphQL SoftwareInstallation' => graphqlResponse('SoftwareInstallation', [
            ['id' => 1, 'softwareversion' => ['id' => 1, 'name' => '128.0', 'software' => ['id' => 9, 'name' => 'Firefox']]],
            ['id' => 2, 'softwareversion' => null],
        ]),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    $softwares = $client->getItem('Computer', 2, ['with_softwares' => 1, 'expand_dropdowns' => 0])['_softwares'];

    expect($softwares)->toBe([['id' => 1, 'softwares_id' => 9, 'softwareversions_id' => 1, 'name' => 'Firefox']]);
});

it('lève une erreur explicite si GraphQL renvoie des erreurs', function () {
    fakeGlpiV2([
        'GET Assets/Computer/2' => Http::response(['id' => 2, 'name' => 'PC']),
        'GraphQL SoftwareInstallation' => Http::response(['errors' => [['message' => 'Cannot query field']]]),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect(fn () => $client->getItem('Computer', 2, ['with_softwares' => 1]))
        ->toThrow(RuntimeException::class, 'Erreur GraphQL GLPI (SoftwareInstallation) : Cannot query field');
});

// ── Sous-items et relations ──────────────────────────────────────────────────

it('récupère le système d\'exploitation via OSInstallation (Item_OperatingSystem v1)', function () {
    fakeGlpiV2(['GET Assets/Computer/2/OSInstallation' => Http::response([
        ['id' => 1, 'operatingsystem' => ['id' => 2, 'name' => 'Windows 11'], 'version' => ['id' => 2, 'name' => '23H2']],
    ])]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getSubItems('Computer', 2, 'Item_OperatingSystem', ['expand_dropdowns' => 1])[0])
        ->toMatchArray(['operatingsystems_id' => 'Windows 11', 'operatingsystemversions_id' => '23H2']);
});

it('récupère les lignes pivot Appliance_Item via GraphQL (filtre sur l\'appliance)', function () {
    fakeGlpiV2(['GraphQL Appliance_Item' => graphqlResponse('Appliance_Item', [
        ['id' => 1, 'itemtype' => 'Computer', 'items_id' => 5, 'appliance' => ['id' => 3]],
        ['id' => 2, 'itemtype' => 'Software', 'items_id' => 9, 'appliance' => ['id' => 3]],
    ])]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getSubItems('Appliance', 3, 'Appliance_Item', ['expand_dropdowns' => 0]))->toBe([
        ['id' => 1, 'appliances_id' => 3, 'items_id' => 5, 'itemtype' => 'Computer'],
        ['id' => 2, 'appliances_id' => 3, 'items_id' => 9, 'itemtype' => 'Software'],
    ]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'GraphQL') && str_contains($r['query'], 'filter: "appliance.id==3"'));
});

it('reconstitue Item_Rack depuis le champ items des Rack', function () {
    fakeGlpiV2(['GET Assets/Rack' => Http::response([
        ['id' => 1, 'name' => 'RACK-01', 'items' => [
            ['id' => 10, 'itemtype' => 'NetworkEquipment', 'items_id' => 1],
            ['id' => 11, 'itemtype' => 'Computer', 'items_id' => 4],
        ]],
        ['id' => 2, 'name' => 'RACK-02', 'items' => []],
    ])]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItems('Item_Rack', ['expand_dropdowns' => 0]))->toBe([
        ['id' => 10, 'racks_id' => 1, 'itemtype' => 'NetworkEquipment', 'items_id' => 1],
        ['id' => 11, 'racks_id' => 1, 'itemtype' => 'Computer', 'items_id' => 4],
    ]);
});

it('récupère toutes les machines virtuelles (ItemVirtualMachine) via GraphQL, hors corbeille', function () {
    fakeGlpiV2(['GraphQL VirtualMachine' => graphqlResponse('VirtualMachine', [
        ['id' => 1, 'itemtype' => 'Computer', 'items_id' => 4, 'name' => 'VM-APP-01', 'uuid' => 'abc', 'is_deleted' => false],
        ['id' => 2, 'itemtype' => 'Computer', 'items_id' => 4, 'name' => 'VM-OLD', 'uuid' => 'def', 'is_deleted' => true],
    ])]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    $vms = $client->getItems('ItemVirtualMachine', ['expand_dropdowns' => 0]);

    expect($vms)->toHaveCount(1)
        ->and($vms[0])->toMatchArray(['itemtype' => 'Computer', 'items_id' => 4, 'name' => 'VM-APP-01', 'uuid' => 'abc', 'is_deleted' => 0]);
});

it('pagine les requêtes GraphQL selon total_count', function () {
    $starts = [];
    fakeGlpiV2(['GraphQL VirtualMachine' => function (Request $r) use (&$starts) {
        preg_match('/start: (\d+)/', $r['query'], $m);
        $starts[] = (int) $m[1];
        $rows = array_fill(0, (int) $m[1] === 0 ? 1000 : 2, ['id' => 1, 'itemtype' => 'Computer', 'items_id' => 1, 'is_deleted' => false]);

        return Http::response([
            'data' => ['VirtualMachine' => $rows],
            'extensions' => ['pagination' => ['VirtualMachine' => ['start' => (int) $m[1], 'limit' => 1000, 'total_count' => 1002]]],
        ]);
    }]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItems('ItemVirtualMachine'))->toHaveCount(1002)
        ->and($starts)->toBe([0, 1000]);
});

it('indique le scope manquant quand GLPI refuse l\'accès GraphQL (403)', function () {
    fakeGlpiV2(['GraphQL Appliance_Item' => Http::response([
        'status' => 'ERROR_RIGHT_MISSING', 'title' => "You don't have permission to perform this action.",
        'detail' => 'You do not have the required scope(s) to access this endpoint.',
    ], 403)]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect(fn () => $client->getSubItems('Appliance', 1, 'Appliance_Item'))
        ->toThrow(RuntimeException::class, 'scope OAuth manquant');
});

it('killSession() oublie le jeton (API v2 sans session serveur)', function () {
    fakeGlpiV2([]);
    $client = makeGlpiV2Client();
    $client->authenticate();
    $client->killSession();

    expect(fn () => $client->getItems('Phone'))->toThrow(RuntimeException::class, 'non authentifié');
    Http::assertSentCount(1);
});

// ── Contournement GLPI 11.0.8 : completename/level null en collection ────────

it('recalcule completename et level des Location parents renvoyés à null en collection', function () {
    // Reproduit la réponse réelle de GLPI 11.0.8 : les nœuds ayant des enfants
    // (Siège, Bâtiment A) ont completename/level à null dans /Dropdowns/Location.
    fakeGlpiV2(['GET Dropdowns/Location' => Http::response([
        ['id' => 3, 'name' => 'Salle 101', 'completename' => 'Siège > Bâtiment A > Salle 101', 'level' => 3, 'parent' => ['id' => 2, 'name' => 'Bâtiment A']],
        ['id' => 2, 'name' => 'Bâtiment A', 'completename' => null, 'level' => null, 'parent' => ['id' => 1, 'name' => 'Siège']],
        ['id' => 1, 'name' => 'Siège', 'completename' => null, 'level' => null, 'parent' => null],
    ])]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    $byName = collect($client->getItems('Location', ['expand_dropdowns' => 1]))->keyBy('name');

    expect($byName['Siège'])->toMatchArray(['completename' => 'Siège', 'level' => 1, 'locations_id' => 0])
        ->and($byName['Bâtiment A'])->toMatchArray(['completename' => 'Siège > Bâtiment A', 'level' => 2, 'locations_id' => 'Siège'])
        ->and($byName['Salle 101'])->toMatchArray(['completename' => 'Siège > Bâtiment A > Salle 101', 'level' => 3]);
});

it('va chercher un parent hors collection par GET unitaire pour reconstruire le chemin', function () {
    fakeGlpiV2([
        'GET Administration/Entity' => Http::response([
            ['id' => 5, 'name' => 'Filiale A', 'completename' => null, 'level' => null, 'parent' => ['id' => 0, 'name' => 'Entité racine']],
        ]),
        'GET Administration/Entity/0' => Http::response(['id' => 0, 'name' => 'Entité racine', 'completename' => 'Entité racine', 'level' => 1, 'parent' => null]),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItems('Entity')[0])->toMatchArray(['completename' => 'Entité racine > Filiale A', 'level' => 2]);
});

it('résout entities_id en chemin complet même pour une entité parente (filtre explicite par entité)', function () {
    fakeGlpiV2([
        'GET Management/Domain' => Http::response([
            ['id' => 1, 'name' => 'filiale.example', 'entity' => ['id' => 5, 'name' => 'Filiale A']],
        ]),
        'GET Administration/Entity' => Http::response([
            ['id' => 0, 'name' => 'Entité racine', 'completename' => null, 'level' => null, 'parent' => null],
            ['id' => 5, 'name' => 'Filiale A', 'completename' => null, 'level' => null, 'parent' => ['id' => 0, 'name' => 'Entité racine']],
            ['id' => 6, 'name' => 'Agence', 'completename' => 'Entité racine > Filiale A > Agence', 'level' => 3, 'parent' => ['id' => 5, 'name' => 'Filiale A']],
        ]),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItems('Domain', ['expand_dropdowns' => 1])[0]['entities_id'])->toBe('Entité racine > Filiale A');
});

// ── Adresses IP : dernier inventaire du GLPI Agent (non exposées par l'API v2) ──

function agentInventoryJson(array $networks): string
{
    return json_encode(['action' => 'inventory', 'itemtype' => 'Computer', 'content' => ['networks' => $networks]]);
}

it('récupère les IP depuis l\'inventaire de l\'agent et les rattache aux ports par MAC', function () {
    fakeGlpiV2([
        'GET Assets/Computer/6' => Http::response(['id' => 6, 'name' => 'PC-AGENT-01']),
        'GraphQL NetworkPort' => graphqlResponse('NetworkPort', [
            ['id' => 1, 'name' => 'eth0', 'mac' => 'aa:bb:cc:11:22:33', 'instantiation_type' => 'NetworkPortEthernet', 'logical_number' => 1, 'is_deleted' => false],
        ]),
        'GET Inventory/Agent' => Http::response([
            ['id' => 4, 'itemtype' => 'Computer', 'items_id' => 6, 'last_contact' => '2026-10-02T07:47:20+00:00'],
        ]),
        'GET Inventory/Agent/4/InventoryFile' => Http::response(agentInventoryJson([
            ['description' => 'lo', 'ipaddress' => '127.0.0.1', 'type' => 'loopback', 'virtualdev' => true],
            ['description' => 'eth0', 'ipaddress' => '192.168.10.21', 'macaddr' => 'AA:BB:CC:11:22:33', 'type' => 'ethernet'],
            ['description' => 'eth0', 'ipaddress6' => 'fe80::a8bb:ccff:fe11:2233', 'macaddr' => 'aa:bb:cc:11:22:33', 'type' => 'ethernet'],
        ]), 200, ['Content-Type' => 'application/json']),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    $ports = $client->getItem('Computer', 6, ['with_networkports' => 1])['_networkports'];

    expect($ports)->toHaveKey('NetworkPortEthernet')
        ->and($ports['NetworkPortEthernet'])->toHaveCount(1)
        ->and($ports['NetworkPortEthernet'][0]['mac'])->toBe('aa:bb:cc:11:22:33')
        ->and($ports['NetworkPortEthernet'][0]['NetworkName']['IPAddress'])->toBe([
            ['name' => '192.168.10.21'],
            ['name' => 'fe80::a8bb:ccff:fe11:2233'],
        ]);
});

it('lit aussi les inventaires XML (agents FusionInventory)', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><REQUEST><CONTENT>'
        .'<NETWORKS><DESCRIPTION>wlan0</DESCRIPTION><IPADDRESS>10.1.2.3</IPADDRESS><MACADDR>de:ad:be:ef:00:01</MACADDR><TYPE>wifi</TYPE></NETWORKS>'
        .'<NETWORKS><DESCRIPTION>lo</DESCRIPTION><IPADDRESS>127.0.0.1</IPADDRESS></NETWORKS>'
        .'</CONTENT><DEVICEID>pc</DEVICEID><QUERY>INVENTORY</QUERY></REQUEST>';

    fakeGlpiV2([
        'GET Assets/Computer/7' => Http::response(['id' => 7, 'name' => 'PC-FUSION']),
        'GraphQL NetworkPort' => graphqlResponse('NetworkPort', [
            ['id' => 3, 'name' => 'wlan0', 'mac' => 'de:ad:be:ef:00:01', 'instantiation_type' => 'NetworkPortWifi', 'logical_number' => 1, 'is_deleted' => false],
        ]),
        'GET Inventory/Agent' => Http::response([['id' => 9, 'itemtype' => 'Computer', 'items_id' => 7]]),
        'GET Inventory/Agent/9/InventoryFile' => Http::response($xml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    expect($client->getItem('Computer', 7, ['with_networkports' => 1])['_networkports']['NetworkPortWifi'][0]['NetworkName']['IPAddress'])
        ->toBe([['name' => '10.1.2.3']]);
});

it('crée un port à part pour une interface d\'inventaire sans port GLPI correspondant', function () {
    fakeGlpiV2([
        'GET Assets/Computer/6' => Http::response(['id' => 6, 'name' => 'PC']),
        'GraphQL NetworkPort' => graphqlResponse('NetworkPort', []),
        'GET Inventory/Agent' => Http::response([['id' => 4, 'itemtype' => 'Computer', 'items_id' => 6]]),
        'GET Inventory/Agent/4/InventoryFile' => Http::response(agentInventoryJson([
            ['description' => 'wlp2s0', 'ipaddress' => '10.9.9.9', 'macaddr' => 'aa:aa:aa:aa:aa:aa', 'type' => 'wifi'],
            ['description' => 'docker0', 'macaddr' => 'bb:bb:bb:bb:bb:bb', 'type' => 'ethernet'],
        ])),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    // docker0 (sans IP) n'apporte rien et n'est pas ajouté
    expect($client->getItem('Computer', 6, ['with_networkports' => 1])['_networkports'])->toBe([
        'NetworkPortWifi' => [[
            'id' => null, 'name' => 'wlp2s0', 'mac' => 'aa:aa:aa:aa:aa:aa', 'logical_number' => null,
            'NetworkName' => ['IPAddress' => [['name' => '10.9.9.9']]],
        ]],
    ]);
});

it('utilise l\'agent vu le plus récemment et ne charge la liste des agents qu\'une fois', function () {
    fakeGlpiV2([
        'GET Assets/Computer/6' => Http::response(['id' => 6, 'name' => 'PC']),
        'GET Assets/Computer/8' => Http::response(['id' => 8, 'name' => 'PC-SANS-AGENT']),
        'GraphQL NetworkPort' => graphqlResponse('NetworkPort', []),
        'GET Inventory/Agent' => Http::response([
            ['id' => 1, 'itemtype' => 'Computer', 'items_id' => 6, 'last_contact' => '2025-01-01T00:00:00+00:00'],
            ['id' => 2, 'itemtype' => 'Computer', 'items_id' => 6, 'last_contact' => '2026-09-30T00:00:00+00:00'],
        ]),
        'GET Inventory/Agent/2/InventoryFile' => Http::response(agentInventoryJson([
            ['description' => 'eth0', 'ipaddress' => '10.0.0.2', 'macaddr' => 'aa:aa:aa:aa:aa:02'],
        ])),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    $ports = $client->getItem('Computer', 6, ['with_networkports' => 1])['_networkports'];
    $client->getItem('Computer', 8, ['with_networkports' => 1]);

    expect($ports['NetworkPortEthernet'][0]['NetworkName']['IPAddress'])->toBe([['name' => '10.0.0.2']]);
    Http::assertSentCount(7); // token + 2×item + 2×GraphQL + agents (une fois) + 1 inventaire
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'Inventory/Agent/1/'));
});

it('ne bloque pas la synchro si le fichier d\'inventaire est absent (404) : pas d\'IP', function () {
    fakeGlpiV2([
        'GET Assets/Computer/6' => Http::response(['id' => 6, 'name' => 'PC']),
        'GraphQL NetworkPort' => graphqlResponse('NetworkPort', [
            ['id' => 1, 'name' => 'eth0', 'mac' => 'aa:bb:cc:11:22:33', 'instantiation_type' => 'NetworkPortEthernet', 'logical_number' => 1, 'is_deleted' => false],
        ]),
        'GET Inventory/Agent' => Http::response([['id' => 4, 'itemtype' => 'Computer', 'items_id' => 6]]),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    $ports = $client->getItem('Computer', 6, ['with_networkports' => 1])['_networkports'];

    expect($ports['NetworkPortEthernet'][0]['mac'])->toBe('aa:bb:cc:11:22:33')
        ->and($ports['NetworkPortEthernet'][0]['NetworkName']['IPAddress'])->toBe([]);
});

it('dégrade avec un warning unique si les agents ne sont pas lisibles (droits)', function () {
    Log::spy();
    fakeGlpiV2([
        'GET Assets/Computer/6' => Http::response(['id' => 6, 'name' => 'PC']),
        'GraphQL NetworkPort' => graphqlResponse('NetworkPort', []),
        'GET Inventory/Agent' => Http::response(['status' => 'ERROR_RIGHT_MISSING', 'title' => "You don't have permission"], 403),
    ]);

    $client = makeGlpiV2Client();
    $client->authenticate();

    $client->getItem('Computer', 6, ['with_networkports' => 1]);
    $client->getItem('Computer', 6, ['with_networkports' => 1]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'Agents d\'inventaire illisibles'));
});
