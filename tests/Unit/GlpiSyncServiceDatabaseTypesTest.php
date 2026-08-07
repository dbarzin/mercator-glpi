<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\DatabaseSyncHandler;
use App\Services\Glpi\Mappers\DatabaseMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Mockery\MockInterface;

// ── Résolution du type de technologie (Database GLPI → DatabaseInstance → type) ──
// cf. SupportsDatabaseInstanceResolution, GlpiSyncService::buildDatabaseTypesMap().

function databaseTypesGlpiMock(array $databases, array $instances): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);

    // Chargement principal (étape 1 de sync()) : expand_dropdowns=1, cf. DatabaseSyncHandler::glpiQueryParams().
    $mock->shouldReceive('getItems')
        ->with('Database', Mockery::on(fn ($p) => ($p['expand_dropdowns'] ?? null) === 1))
        ->andReturn($databases);

    // buildDatabaseTypesMap() : DatabaseInstance en expand_dropdowns=1 (type en nom).
    $mock->shouldReceive('getItems')
        ->with('DatabaseInstance', Mockery::on(fn ($p) => ($p['expand_dropdowns'] ?? null) === 1))
        ->andReturn($instances);

    // buildDatabaseTypesMap() : Database en expand_dropdowns=0 (databaseinstances_id numérique, cf. issue #8).
    $mock->shouldReceive('getItems')
        ->with('Database', Mockery::on(fn ($p) => ($p['expand_dropdowns'] ?? null) === 0))
        ->andReturn($databases);

    $mock->shouldReceive('getEntityId')->andReturn(null);

    return $mock;
}

function databaseTypesMercatorMock(): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getBuildings')->andReturn([]);
    $mock->shouldReceive('getSites')->andReturn([]);
    $mock->shouldReceive('getAll')->with('databases')->andReturn([]);

    return $mock;
}

it('compose db-id → type via databaseinstances_id et databaseinstancetypes_id', function () {
    $glpi = databaseTypesGlpiMock(
        databases: [
            ['id' => 1, 'name' => 'prod-db', 'databaseinstances_id' => 10],
        ],
        instances: [
            ['id' => 10, 'databaseinstancetypes_id' => 'MariaDB'],
        ],
    );

    $mercator = databaseTypesMercatorMock();

    $created = null;
    $mercator->shouldReceive('create')->andReturnUsing(function (string $ep, array $payload) use (&$created) {
        $created = $payload;

        return ['id' => 99] + $payload;
    });

    (new GlpiSyncService)->sync($glpi, $mercator, new DatabaseSyncHandler(new DatabaseMapper));

    expect($created['type'])->toBe('MariaDB');
});

it('ne produit aucune entrée pour une base dont databaseinstances_id ne matche aucune instance', function () {
    $glpi = databaseTypesGlpiMock(
        databases: [
            ['id' => 1, 'name' => 'orphan-db', 'databaseinstances_id' => 999],
        ],
        instances: [
            ['id' => 10, 'databaseinstancetypes_id' => 'MariaDB'],
        ],
    );

    $mercator = databaseTypesMercatorMock();

    $created = null;
    $mercator->shouldReceive('create')->andReturnUsing(function (string $ep, array $payload) use (&$created) {
        $created = $payload;

        return ['id' => 99] + $payload;
    });

    (new GlpiSyncService)->sync($glpi, $mercator, new DatabaseSyncHandler(new DatabaseMapper));

    expect($created)->not->toHaveKey('type');
});

it('n\'injecte database_types que pour un handler SupportsDatabaseInstanceResolution', function () {
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Location', Mockery::any())->andReturn([
        ['id' => 1, 'name' => 'Site A', 'comment' => '', 'level' => 0, 'locations_id' => 0],
    ]);
    $glpi->shouldNotReceive('getItems')->with('DatabaseInstance', Mockery::any());
    $glpi->shouldReceive('getEntityId')->andReturn(null);

    $mercator = Mockery::mock(MercatorClientInterface::class);
    $mercator->shouldReceive('getBuildings')->andReturn([]);
    $mercator->shouldReceive('getSites')->andReturn([]);
    $mercator->shouldReceive('getAll')->with('buildings')->andReturn([]);

    $capturedContext = null;

    $handler = new class implements SyncHandler
    {
        public static $capturedContext;

        public function glpiItemType(): string
        {
            return 'Location';
        }

        public function mercatorEndpoint(): string
        {
            return 'buildings';
        }

        public function glpiQueryParams(): array
        {
            return [];
        }

        public function map(array $glpiItem, array $context): array
        {
            self::$capturedContext = $context;

            return ['name' => $glpiItem['name']];
        }

        public function processOrphans(): bool
        {
            return false;
        }

        public function filterItem(array $item): bool
        {
            return true;
        }
    };

    $mercator->shouldReceive('create')->andReturn(['id' => 1]);

    (new GlpiSyncService)->sync($glpi, $mercator, $handler);

    expect($handler::$capturedContext)->not->toHaveKey('database_types');
});
