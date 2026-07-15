<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\ApplicationSyncHandler;
use App\Services\Glpi\Handlers\DomainSyncHandler;
use App\Services\Glpi\Handlers\LocationSyncHandler;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Glpi\Mappers\ApplicationMapper;
use App\Services\Glpi\Mappers\DomainMapper;
use App\Services\Glpi\Mappers\LocationMapper;
use App\Services\Glpi\Mappers\WorkstationMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

// ── Tests filtrage par statut (Évolution 2) ───────────────────────────────────

function makeFilterGlpiMock(array $items): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);
    $mock->shouldReceive('getItems')->andReturn($items);
    $mock->shouldReceive('getItem')->andReturn([]);
    $mock->shouldReceive('getSubItems')->andReturn([]);
    $mock->shouldReceive('getEntityId')->andReturn(null);

    return $mock;
}

/**
 * Mock Mercator de base (sans 'create' — chaque test configure ce dont il a besoin).
 */
function makeFilterMercatorBase(): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getBuildings')->andReturn([]);
    $mock->shouldReceive('getSites')->andReturn([]);
    $mock->shouldReceive('getAll')->andReturn([]);

    return $mock;
}

function makeFilterHandler(): WorkstationSyncHandler
{
    return new WorkstationSyncHandler(new WorkstationMapper);
}

function computerItem(string $name, mixed $stateId, string $computerType = 'Poste de travail'): array
{
    return [
        'id' => rand(1, 9999),
        'name' => $name,
        'comment' => '',
        'serial' => '',
        'locations_id' => 0,
        'computertypes_id' => $computerType,
        'manufacturers_id' => '',
        'computermodels_id' => '',
        'operatingsystems_id' => '',
        'states_id' => $stateId,
        'users_id' => 0,
        'ram' => null,
        '_networkports' => [],
        '_devices' => [],
        '_disks' => [],
        '_infocoms' => [],
    ];
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('synchronise tous les items si aucun filtre statut n\'est défini', function () {
    config(['glpi.allowed_states' => ['default' => [], 'Computer' => []]]);
    config(['glpi.computer_types' => ['workstations' => [], 'logical_servers' => [], 'physical_servers' => []]]);

    $created = [];
    $mercator = makeFilterMercatorBase();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 1];
        });

    $items = [
        computerItem('PC-01', 'En production'),
        computerItem('PC-02', 'En stock'),
        computerItem('PC-03', 0),
    ];

    (new GlpiSyncService)->sync(makeFilterGlpiMock($items), $mercator, makeFilterHandler());

    expect($created)->toHaveCount(3);
});

it('filtre par statut avec la config globale', function () {
    config(['glpi.allowed_states' => ['default' => ['En production'], 'Computer' => []]]);
    config(['glpi.computer_types' => ['workstations' => [], 'logical_servers' => [], 'physical_servers' => []]]);

    $created = [];
    $mercator = makeFilterMercatorBase();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 1];
        });

    $items = [
        computerItem('PC-ACTIF', 'En production'),
        computerItem('PC-STOCK', 'En stock'),
        computerItem('PC-REBUT', 'Mis au rebut'),
    ];

    (new GlpiSyncService)->sync(makeFilterGlpiMock($items), $mercator, makeFilterHandler());

    expect($created)->toContain('PC-ACTIF');
    expect($created)->not->toContain('PC-STOCK');
    expect($created)->not->toContain('PC-REBUT');
});

it('filtre par statut avec la config spécifique au type (Computer)', function () {
    config(['glpi.allowed_states' => [
        'default' => ['En production', 'En stock'],
        'Computer' => ['En production'],
    ]]);
    config(['glpi.computer_types' => ['workstations' => [], 'logical_servers' => [], 'physical_servers' => []]]);

    $created = [];
    $mercator = makeFilterMercatorBase();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 1];
        });

    $items = [
        computerItem('PC-ACTIF', 'En production'),
        computerItem('PC-STOCK', 'En stock'),
    ];

    (new GlpiSyncService)->sync(makeFilterGlpiMock($items), $mercator, makeFilterHandler());

    expect($created)->toContain('PC-ACTIF');
    expect($created)->not->toContain('PC-STOCK');
});

it('la config spécifique au type a priorité sur la config globale', function () {
    config(['glpi.allowed_states' => [
        'default' => [],
        'Computer' => ['1'],
    ]]);
    config(['glpi.computer_types' => ['workstations' => [], 'logical_servers' => [], 'physical_servers' => []]]);

    $created = [];
    $mercator = makeFilterMercatorBase();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 1];
        });

    $items = [
        computerItem('PC-MATCH', '1'),
        computerItem('PC-EXCLU', '2'),
    ];

    (new GlpiSyncService)->sync(makeFilterGlpiMock($items), $mercator, makeFilterHandler());

    expect($created)->toContain('PC-MATCH');
    expect($created)->not->toContain('PC-EXCLU');
});

it('accepte uniquement les items avec states_id=0 si 0 est dans la liste', function () {
    config(['glpi.allowed_states' => ['default' => ['0'], 'Computer' => []]]);
    config(['glpi.computer_types' => ['workstations' => [], 'logical_servers' => [], 'physical_servers' => []]]);

    $created = [];
    $mercator = makeFilterMercatorBase();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 1];
        });

    $items = [
        computerItem('PC-SANS-STATUT', 0),
        computerItem('PC-EN-PROD', 'En production'),
    ];

    (new GlpiSyncService)->sync(makeFilterGlpiMock($items), $mercator, makeFilterHandler());

    expect($created)->toContain('PC-SANS-STATUT');
    expect($created)->not->toContain('PC-EN-PROD');
});

it('ignore le filtre statut pour Location (pas d\'attribut states_id dans GLPI)', function () {
    config(['glpi.allowed_states' => ['default' => ['En production']]]);

    $created = [];
    $mercator = makeFilterMercatorBase();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 1];
        });

    $items = [
        ['id' => 1, 'name' => 'Site A', 'completename' => 'Site A', 'locations_id' => 'Racine'],
        ['id' => 2, 'name' => 'Site B', 'completename' => 'Site B', 'locations_id' => 'Racine'],
    ];

    $handler = new LocationSyncHandler(new LocationMapper);

    (new GlpiSyncService)->sync(makeFilterGlpiMock($items), $mercator, $handler);

    expect($created)->toContain('Site A');
    expect($created)->toContain('Site B');
});

it('ignore le filtre statut pour Domain (pas d\'attribut states_id dans GLPI)', function () {
    config(['glpi.allowed_states' => ['default' => ['En production']]]);

    $created = [];
    $mercator = makeFilterMercatorBase();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 1];
        });

    $items = [
        ['id' => 1, 'name' => 'example.com', 'comment' => '', 'domaintypes_id' => 'Interne'],
        ['id' => 2, 'name' => 'example.org', 'comment' => '', 'domaintypes_id' => 'Externe'],
    ];

    $handler = new DomainSyncHandler(new DomainMapper);

    (new GlpiSyncService)->sync(makeFilterGlpiMock($items), $mercator, $handler);

    expect($created)->toContain('example.com');
    expect($created)->toContain('example.org');
});

it('ignore le filtre statut pour Software (pas d\'attribut states_id dans GLPI)', function () {
    config(['glpi.allowed_states' => ['default' => ['2']]]);
    config(['glpi.software_categories' => []]);

    $created = [];
    $mercator = makeFilterMercatorBase();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 1];
        });

    $items = [
        ['id' => 1, 'name' => 'Firefox', 'comment' => '', 'manufacturers_id' => 0, 'softwarecategories_id' => 0, 'users_id_tech' => 0, 'date' => null, 'locations_id' => 0],
        ['id' => 2, 'name' => '7-Zip', 'comment' => '', 'manufacturers_id' => 0, 'softwarecategories_id' => 0, 'users_id_tech' => 0, 'date' => null, 'locations_id' => 0],
        ['id' => 3, 'name' => 'LibreOffice', 'comment' => '', 'manufacturers_id' => 0, 'softwarecategories_id' => 0, 'users_id_tech' => 0, 'date' => null, 'locations_id' => 0],
    ];

    $handler = new ApplicationSyncHandler(new ApplicationMapper);

    (new GlpiSyncService)->sync(makeFilterGlpiMock($items), $mercator, $handler);

    expect($created)->toHaveCount(3);
    expect($created)->toContain('Firefox', '7-Zip', 'LibreOffice');
});

it('émet un avertissement quand un filtre statut est configuré pour Software', function () {
    config(['glpi.allowed_states' => ['default' => ['2']]]);
    config(['glpi.software_categories' => []]);

    Log::spy();

    $mercator = makeFilterMercatorBase();
    $mercator->shouldReceive('create')->andReturn(['id' => 1]);

    $items = [
        ['id' => 1, 'name' => 'Firefox', 'comment' => '', 'manufacturers_id' => 0, 'softwarecategories_id' => 0, 'users_id_tech' => 0, 'date' => null, 'locations_id' => 0],
    ];

    $handler = new ApplicationSyncHandler(new ApplicationMapper);

    (new GlpiSyncService)->sync(makeFilterGlpiMock($items), $mercator, $handler);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'Software') && str_contains($message, 'states_id'))
        ->once();
});

it('n\'émet aucun avertissement pour Software quand aucun filtre statut n\'est configuré', function () {
    config(['glpi.allowed_states' => ['default' => []]]);
    config(['glpi.software_categories' => []]);

    Log::spy();

    $mercator = makeFilterMercatorBase();
    $mercator->shouldReceive('create')->andReturn(['id' => 1]);

    $items = [
        ['id' => 1, 'name' => 'Firefox', 'comment' => '', 'manufacturers_id' => 0, 'softwarecategories_id' => 0, 'users_id_tech' => 0, 'date' => null, 'locations_id' => 0],
    ];

    $handler = new ApplicationSyncHandler(new ApplicationMapper);

    (new GlpiSyncService)->sync(makeFilterGlpiMock($items), $mercator, $handler);

    Log::shouldNotHaveReceived('warning');
});
