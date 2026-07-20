<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\ApplianceSyncHandler;
use App\Services\Glpi\Handlers\ApplicationSyncHandler;
use App\Services\Glpi\Mappers\ApplianceMapper;
use App\Services\Glpi\Mappers\ApplicationMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Mockery\MockInterface;

// ── Helpers locaux (cf. issue #12 : routage Appliance → applications) ─────────

function applianceItem(int $id, string $name, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'name' => $name,
        'comment' => '',
        'appliancetypes_id' => 0,
        'users_id_tech' => 0,
        'locations_id' => 0,
    ], $overrides);
}

function applianceRoutingGlpiMock(array $items): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);
    $mock->shouldReceive('getItems')->andReturn($items);
    $mock->shouldReceive('getItem')->andReturn([]);
    $mock->shouldReceive('getSubItems')->andReturn([]);

    return $mock;
}

function applianceRoutingMercatorMock(array $applications = []): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getBuildings')->andReturn([]);
    $mock->shouldReceive('getSites')->andReturn([]);
    $mock->shouldReceive('getAll')->andReturn($applications);

    return $mock;
}

// ── Pas de collision d'id entre Appliance et Software sur le même endpoint ────

it('crée un nouvel enregistrement taggé {GLPI-Appliance} sans toucher au Software de même id', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $created = [];
    $updated = [];

    $mercator = applianceRoutingMercatorMock([
        ['id' => 100, 'name' => 'Firefox', 'ext_refs' => '{GLPI}5'],
    ]);
    $mercator->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 200];
        });
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    $handler = new ApplianceSyncHandler(new ApplianceMapper);

    $stats = (new GlpiSyncService)->sync(
        applianceRoutingGlpiMock([applianceItem(5, 'MonAppliance')]),
        $mercator,
        $handler,
    );

    expect($created)->toHaveCount(1);
    expect($created[0]['ext_refs'])->toBe('{GLPI-Appliance}5');
    expect(collect($updated)->pluck('id'))->not->toContain(100);
    expect($stats['created'])->toBe(1);
    expect($stats['updated'])->toBe(0);
});

// ── Orphelins scopés par tag ────────────────────────────────────────────────

it('ignore (sans suppression ni [OLD]) une application taguée {GLPI} lors d\'une sync Appliances→applications', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $mercator = applianceRoutingMercatorMock([
        ['id' => 42, 'name' => 'SomeSoftware', 'ext_refs' => '{GLPI}42'],
    ]);
    $mercator->shouldNotReceive('delete');
    $mercator->shouldNotReceive('update');
    $mercator->shouldReceive('create')->andReturn(['id' => 500]);

    $handler = new ApplianceSyncHandler(new ApplianceMapper);

    $stats = (new GlpiSyncService)->sync(
        applianceRoutingGlpiMock([]), // aucune Appliance dans GLPI
        $mercator,
        $handler,
    );

    expect($stats['deleted'])->toBe(0);
    expect($stats['marked_old'])->toBe(0);
});

it('ignore (sans suppression ni [OLD]) une application taguée {GLPI-Appliance} lors d\'une sync Software→applications', function () {
    $mercator = applianceRoutingMercatorMock([
        ['id' => 7, 'name' => 'SomeAppliance', 'ext_refs' => '{GLPI-Appliance}7'],
    ]);
    $mercator->shouldNotReceive('delete');
    $mercator->shouldNotReceive('update');
    $mercator->shouldReceive('create')->andReturn(['id' => 600]);

    $handler = new ApplicationSyncHandler(new ApplicationMapper);

    $stats = (new GlpiSyncService)->sync(
        applianceRoutingGlpiMock([]), // aucun Software dans GLPI
        $mercator,
        $handler,
    );

    expect($stats['deleted'])->toBe(0);
    expect($stats['marked_old'])->toBe(0);
});

it('supprime un item Mercator légitimement orphelin taggé {GLPI-Appliance}', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $deleted = [];

    $mercator = applianceRoutingMercatorMock([
        ['id' => 99, 'name' => 'OldAppliance', 'ext_refs' => '{GLPI-Appliance}99'],
    ]);
    $mercator->shouldReceive('delete')
        ->andReturnUsing(function (string $ep, int $id) use (&$deleted) {
            $deleted[] = $id;
        });

    $handler = new ApplianceSyncHandler(new ApplianceMapper);

    $stats = (new GlpiSyncService)->sync(
        applianceRoutingGlpiMock([]), // absent de GLPI
        $mercator,
        $handler,
    );

    expect($deleted)->toContain(99);
    expect($stats['deleted'])->toBe(1);
});

// ── Rétrocompatibilité mode activities ─────────────────────────────────────

it('réconcilie normalement en mode activities par défaut (rétrocompatibilité, tag {GLPI} inchangé)', function () {
    $updated = [];

    $mercator = applianceRoutingMercatorMock([
        ['id' => 10, 'name' => 'ANCIEN-NOM', 'ext_refs' => '{GLPI}7'],
    ]);
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });
    $mercator->shouldNotReceive('delete');

    $handler = new ApplianceSyncHandler(new ApplianceMapper);

    $stats = (new GlpiSyncService)->sync(
        applianceRoutingGlpiMock([applianceItem(7, 'ERP-PRODUCTION')]),
        $mercator,
        $handler,
    );

    expect(collect($updated)->pluck('id'))->toContain(10);
    expect($stats['updated'])->toBe(1);
    expect($stats['created'])->toBe(0);
    expect($stats['marked_old'])->toBe(0);
});

// ── buildExtRefs : préservation des références étrangères ──────────────────

it('préserve {OTHER} et {GLPI} existants tout en ajoutant {GLPI-Appliance}', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $updated = [];

    $mercator = applianceRoutingMercatorMock([
        ['id' => 55, 'name' => 'MonAppliance', 'ext_refs' => '{OTHER}x|{GLPI}5'],
    ]);
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    $handler = new ApplianceSyncHandler(new ApplianceMapper);

    (new GlpiSyncService)->sync(
        applianceRoutingGlpiMock([applianceItem(5, 'MonAppliance')]),
        $mercator,
        $handler,
    );

    $update = collect($updated)->first(fn ($u) => $u['id'] === 55);
    expect($update)->not->toBeNull();
    expect($update['payload']['ext_refs'])->toContain('{OTHER}x');
    expect($update['payload']['ext_refs'])->toContain('{GLPI}5');
    expect($update['payload']['ext_refs'])->toContain('{GLPI-Appliance}5');
});

// ── Homonymes au sein d'un même run (régression issue #12) ─────────────────

it('réconcilie deux Appliances GLPI homonymes sur un même run en mode applications', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $items = [
        applianceItem(20, 'Portail-RH'),
        applianceItem(21, 'Portail-RH'),
    ];

    $created = [];
    $updated = [];

    $mercator = applianceRoutingMercatorMock([]);
    $mercator->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 900];
        });
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    $handler = new ApplianceSyncHandler(new ApplianceMapper);

    $stats = (new GlpiSyncService)->sync(
        applianceRoutingGlpiMock($items),
        $mercator,
        $handler,
    );

    expect($created)->toHaveCount(1);
    expect($updated)->toHaveCount(1);
    expect($stats['errors'])->toBe(0);
});
