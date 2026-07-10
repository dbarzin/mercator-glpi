<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\LocationSyncHandler;
use App\Services\Glpi\Handlers\NetworkDeviceSyncHandler;
use App\Services\Glpi\Mappers\LocationMapper;
use App\Services\Glpi\Mappers\NetworkDeviceMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Mockery\MockInterface;

// ── Résolution de bay_id (NetworkEquipment ↔ Rack GLPI ↔ Bay Mercator) ────────
// Le bay_id n'est résolu que pour les handlers SupportsBayResolution (cf.
// NetworkDeviceSyncHandler), via 'item_rack_map' (Item_Rack GLPI) et
// 'racks_map' (bays Mercator déjà synchronisées, indexées par ext_refs {GLPI}id).

function mercatorMockWithBays(array $bays): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getBuildings')->andReturn([]);
    $mock->shouldReceive('getSites')->andReturn([]);
    $mock->shouldReceive('getAll')->with('bays')->andReturn($bays);
    $mock->shouldReceive('getAll')->with('physical-switches')->andReturn([]);

    return $mock;
}

it('résout bay_id pour un NetworkEquipment placé dans un Rack déjà synchronisé', function () {
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('NetworkEquipment', Mockery::any())->andReturn([
        ['id' => 10, 'name' => 'SW-01', 'comment' => '', 'locations_id' => 0],
    ]);
    $glpi->shouldReceive('getItems')->with('Item_Rack', Mockery::any())->andReturn([
        ['itemtype' => 'NetworkEquipment', 'items_id' => 10, 'racks_id' => 5],
    ]);
    $glpi->shouldReceive('getItem')->andReturn([]);

    $mercator = mercatorMockWithBays([
        ['id' => 42, 'name' => 'RACK-A01', 'ext_refs' => '{GLPI}5'],
    ]);

    $created = null;
    $mercator->shouldReceive('create')->andReturnUsing(function (string $ep, array $payload) use (&$created) {
        $created = $payload;

        return ['id' => 99] + $payload;
    });

    $handler = new NetworkDeviceSyncHandler(new NetworkDeviceMapper);

    (new GlpiSyncService)->sync($glpi, $mercator, $handler);

    expect($created['bay_id'])->toBe(42);
});

// ── Tests ciblés sur le correctif expand_dropdowns=0 (issue #8) ───────────────

it('appelle getItems Item_Rack avec expand_dropdowns=0', function () {
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('NetworkEquipment', Mockery::any())->andReturn([
        ['id' => 6054, 'name' => 'SW-6054', 'comment' => '', 'locations_id' => 0],
    ]);
    $glpi->shouldReceive('getItems')
        ->with('Item_Rack', Mockery::on(fn ($p) => ($p['expand_dropdowns'] ?? null) === 0))
        ->once()
        ->andReturn([]);
    $glpi->shouldReceive('getItem')->andReturn([]);

    $mercator = mercatorMockWithBays([]);
    $mercator->shouldReceive('create')->andReturn(['id' => 1]);

    (new GlpiSyncService)->sync($glpi, $mercator, new NetworkDeviceSyncHandler(new NetworkDeviceMapper));
});

it('buildItemRackMap produit des clés {itemtype}_{id} numériques et résout le bay_id attendu', function () {
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('NetworkEquipment', Mockery::any())->andReturn([
        ['id' => 6054, 'name' => 'SW-6054', 'comment' => '', 'locations_id' => 0],
    ]);
    $glpi->shouldReceive('getItems')->with('Item_Rack', Mockery::any())->andReturn([
        ['itemtype' => 'NetworkEquipment', 'items_id' => 6054, 'racks_id' => 8],
    ]);
    $glpi->shouldReceive('getItem')->andReturn([]);

    $mercator = mercatorMockWithBays([
        ['id' => 77, 'name' => 'RACK-A01', 'ext_refs' => '{GLPI}8'],
    ]);

    $created = null;
    $mercator->shouldReceive('create')->andReturnUsing(function (string $ep, array $payload) use (&$created) {
        $created = $payload;

        return ['id' => 99] + $payload;
    });

    (new GlpiSyncService)->sync($glpi, $mercator, new NetworkDeviceSyncHandler(new NetworkDeviceMapper));

    expect($created['bay_id'])->toBe(77);
});

it('retourne null pour bay_id quand le NetworkEquipment n\'est dans aucun rack', function () {
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('NetworkEquipment', Mockery::any())->andReturn([
        ['id' => 6054, 'name' => 'SW-6054', 'comment' => '', 'locations_id' => 0],
    ]);
    $glpi->shouldReceive('getItems')->with('Item_Rack', Mockery::any())->andReturn([]);
    $glpi->shouldReceive('getItem')->andReturn([]);

    $mercator = mercatorMockWithBays([]);

    $created = null;
    $mercator->shouldReceive('create')->andReturnUsing(function (string $ep, array $payload) use (&$created) {
        $created = $payload;

        return ['id' => 99] + $payload;
    });

    (new GlpiSyncService)->sync($glpi, $mercator, new NetworkDeviceSyncHandler(new NetworkDeviceMapper));

    // Le mapper utilise array_filter(fn($v) => $v !== null), donc bay_id est absent du payload quand null.
    expect($created['bay_id'] ?? null)->toBeNull();
});

it('ignore les lignes Item_Rack incomplètes sans lever d\'exception', function () {
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('NetworkEquipment', Mockery::any())->andReturn([
        ['id' => 6054, 'name' => 'SW-6054', 'comment' => '', 'locations_id' => 0],
    ]);
    $glpi->shouldReceive('getItems')->with('Item_Rack', Mockery::any())->andReturn([
        ['itemtype' => 'NetworkEquipment', 'items_id' => 6054],            // racks_id absent
        ['itemtype' => 'NetworkEquipment', 'racks_id' => 8],               // items_id absent
        ['items_id' => 6054, 'racks_id' => 8],                             // itemtype absent
        ['itemtype' => 'NetworkEquipment', 'items_id' => 6054, 'racks_id' => 8], // ligne valide
    ]);
    $glpi->shouldReceive('getItem')->andReturn([]);

    $mercator = mercatorMockWithBays([
        ['id' => 77, 'name' => 'RACK-A01', 'ext_refs' => '{GLPI}8'],
    ]);

    $created = null;
    $mercator->shouldReceive('create')->andReturnUsing(function (string $ep, array $payload) use (&$created) {
        $created = $payload;

        return ['id' => 99] + $payload;
    });

    expect(fn () => (new GlpiSyncService)->sync($glpi, $mercator, new NetworkDeviceSyncHandler(new NetworkDeviceMapper)))
        ->not->toThrow(Throwable::class);

    expect($created['bay_id'])->toBe(77);
});

it('ne charge pas Item_Rack pour un handler qui ne résout pas de bay (Location)', function () {
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Location', Mockery::any())->andReturn([]);
    $glpi->shouldNotReceive('getItems')->with('Item_Rack', Mockery::any());
    $glpi->shouldReceive('getItem')->andReturn([]);
    $glpi->shouldReceive('getEntityId')->andReturn(null);

    $mercator = Mockery::mock(MercatorClientInterface::class);
    $mercator->shouldReceive('getBuildings')->andReturn([]);
    $mercator->shouldReceive('getSites')->andReturn([]);
    $mercator->shouldReceive('getAll')->with('buildings')->andReturn([]);
    $mercator->shouldNotReceive('getAll')->with('bays');

    (new GlpiSyncService)->sync($glpi, $mercator, new LocationSyncHandler(new LocationMapper));
});
