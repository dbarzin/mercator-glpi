<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\DatabaseSyncHandler;
use App\Services\Glpi\Mappers\DatabaseMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Mockery\MockInterface;

// ── Helpers locaux ────────────────────────────────────────────────────────────

function databaseCollisionGlpiMock(array $items): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);
    $mock->shouldReceive('getItems')->andReturn($items);
    $mock->shouldReceive('getItem')->andReturn([]);
    $mock->shouldReceive('getSubItems')->andReturn([]);

    return $mock;
}

function databaseCollisionMercatorMock(array $databases = []): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getBuildings')->andReturn([]);
    $mock->shouldReceive('getSites')->andReturn([]);
    $mock->shouldReceive('getAll')->andReturn($databases);

    return $mock;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('crée deux enregistrements Mercator distincts pour deux Database GLPI homonymes (instances différentes)', function () {
    // Contrairement à Software/Appliance (cf. GlpiSyncServiceTest "réconcilie deux
    // items GLPI distincts partageant le même nom"), deux DatabaseInstance GLPI
    // distinctes peuvent légitimement chacune porter une base nommée "prod" — ce ne
    // sont PAS le même objet logique. Sans DisablesNameFallbackMatching, le second
    // item se réconcilierait à tort sur l'enregistrement Mercator créé par le premier
    // (perte silencieuse constatée en usage réel, cf. issue #17).
    $database = fn (int $id) => ['id' => $id, 'name' => 'prod', 'databaseinstances_id' => $id * 10];
    $items = [$database(1), $database(2)];

    $created = [];

    $mercator = databaseCollisionMercatorMock();
    $mercator->shouldReceive('create')
        ->twice()
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 500 + count($created)];
        });
    $mercator->shouldNotReceive('update');

    $handler = new DatabaseSyncHandler(new DatabaseMapper);

    $stats = (new GlpiSyncService)->sync(databaseCollisionGlpiMock($items), $mercator, $handler);

    expect($created)->toHaveCount(2);
    expect($stats['created'])->toBe(2);
    expect($stats['updated'])->toBe(0);
    expect($stats['errors'])->toBe(0);
});

it('ne réconcilie pas une Database GLPI sur un enregistrement Mercator homonyme non tagué', function () {
    // Une base Mercator "prod" déjà présente (créée manuellement ou par une autre
    // DatabaseInstance) et pas encore taguée {GLPI} ne doit pas être réutilisée par
    // repli sur le nom : DisablesNameFallbackMatching l'exclut du tout, seul ext_refs
    // fait foi pour Database. L'ancien enregistrement homonyme, non réconcilié, est
    // alors traité comme orphelin (marqué [OLD], processOrphans() = true).
    $items = [['id' => 1, 'name' => 'prod', 'databaseinstances_id' => 10]];

    $created = [];
    $updated = [];

    $mercator = databaseCollisionMercatorMock([
        ['id' => 500, 'name' => 'prod', 'ext_refs' => null],
    ]);
    $mercator->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 999];
        });
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    $handler = new DatabaseSyncHandler(new DatabaseMapper);

    $stats = (new GlpiSyncService)->sync(databaseCollisionGlpiMock($items), $mercator, $handler);

    expect($created)->toHaveCount(1);
    expect($stats['created'])->toBe(1);
    expect($stats['marked_old'])->toBe(1);
    expect($updated)->toHaveCount(1);
    expect($updated[0]['id'])->toBe(500);
    expect($updated[0]['payload'])->toBe(['name' => '[OLD] prod']);
});
