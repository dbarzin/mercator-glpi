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

it('réconcilie deux Database GLPI homonymes (instances différentes) sur un seul enregistrement Mercator, sans suffixe', function () {
    // Deux DatabaseInstance GLPI distinctes peuvent légitimement chacune porter une
    // base nommée "prod" (cf. issue #17). Mercator exige un nom unique par endpoint :
    // créer un second enregistrement "prod" échouerait (422 "already been taken"). Le
    // second item doit donc se réconcilier sur le même enregistrement que le premier,
    // en conservant le nom GLPI tel quel (pas de suffixe d'id).
    $database = fn (int $id) => ['id' => $id, 'name' => 'prod', 'databaseinstances_id' => $id * 10];
    $items = [$database(4), $database(80)];

    $created = [];
    $updated = [];

    $mercator = databaseCollisionMercatorMock();
    $mercator->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 500];
        });
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    $handler = new DatabaseSyncHandler(new DatabaseMapper);

    $stats = (new GlpiSyncService)->sync(databaseCollisionGlpiMock($items), $mercator, $handler);

    expect($created)->toHaveCount(1);
    expect($created[0]['name'])->toBe('prod');
    expect($updated)->toHaveCount(1);
    expect($updated[0]['id'])->toBe(500);
    expect($stats['created'])->toBe(1);
    expect($stats['updated'])->toBe(1);
    expect($stats['errors'])->toBe(0);
});

it('cumule les id GLPI des Database homonymes dans ext_refs au lieu de les remplacer', function () {
    $database = fn (int $id) => ['id' => $id, 'name' => 'prod', 'databaseinstances_id' => $id * 10];
    $items = [$database(4), $database(80)];

    $updated = [];

    $mercator = databaseCollisionMercatorMock();
    $mercator->shouldReceive('create')->once()->andReturn(['id' => 500]);
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = $payload;

            return [];
        });

    $handler = new DatabaseSyncHandler(new DatabaseMapper);

    (new GlpiSyncService)->sync(databaseCollisionGlpiMock($items), $mercator, $handler);

    // Le premier item (id 4) crée l'enregistrement avec ext_refs "{GLPI}4" (vérifié via
    // le payload de la CREATE, non capturé ici) ; le second (id 80) doit AJOUTER son id
    // plutôt que remplacer — l'update reçu doit donc porter les deux tags.
    expect($updated)->toHaveCount(1);
    expect($updated[0]['ext_refs'])->toBe('{GLPI}4|{GLPI}80');
});

it('réconcilie une Database GLPI sur un enregistrement Mercator homonyme non tagué (repli par nom)', function () {
    $items = [['id' => 1, 'name' => 'prod', 'databaseinstances_id' => 10]];

    $updated = [];

    $mercator = databaseCollisionMercatorMock([
        ['id' => 500, 'name' => 'prod', 'ext_refs' => null],
    ]);
    $mercator->shouldNotReceive('create');
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    $handler = new DatabaseSyncHandler(new DatabaseMapper);

    $stats = (new GlpiSyncService)->sync(databaseCollisionGlpiMock($items), $mercator, $handler);

    expect($stats['created'])->toBe(0);
    expect($stats['updated'])->toBe(1);
    expect($stats['marked_old'])->toBe(0);
    expect($updated)->toHaveCount(1);
    expect($updated[0]['id'])->toBe(500);
    expect($updated[0]['payload']['name'])->toBe('prod');
});

it('réconcilie une Database GLPI renommée dans GLPI via l\'un quelconque de ses id GLPI cumulés dans ext_refs', function () {
    // Enregistrement déjà fusionné lors d'un run précédent (ext_refs porte les deux id).
    // Le second item GLPI (id 80) doit être retrouvé via son id même si, entretemps, le
    // premier (id 4) a été renommé côté GLPI et ne partage donc plus le même nom.
    $items = [
        ['id' => 4, 'name' => 'prod-renamed', 'databaseinstances_id' => 40],
        ['id' => 80, 'name' => 'prod', 'databaseinstances_id' => 800],
    ];

    $updated = [];

    $mercator = databaseCollisionMercatorMock([
        ['id' => 500, 'name' => 'prod', 'ext_refs' => '{GLPI}4|{GLPI}80'],
    ]);
    $mercator->shouldNotReceive('create');
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    $handler = new DatabaseSyncHandler(new DatabaseMapper);

    $stats = (new GlpiSyncService)->sync(databaseCollisionGlpiMock($items), $mercator, $handler);

    // Les deux items GLPI se réconcilient tous les deux sur le même enregistrement 500.
    expect($stats['created'])->toBe(0);
    expect($updated)->toHaveCount(2);
    expect($updated[0]['id'])->toBe(500);
    expect($updated[1]['id'])->toBe(500);
});
