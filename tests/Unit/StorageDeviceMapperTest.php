<?php

use App\Services\Glpi\Mappers\StorageDeviceMapper;

function glpiStorageDevice(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_network_devices.json'), true)[0],
        ['networkequipmenttypes_id' => 'Baie de stockage'],
        $overrides
    );
}

it('mappe le nom de la baie de stockage', function () {
    $result = (new StorageDeviceMapper)->map(glpiStorageDevice(), ['buildings_map' => []]);

    expect($result['name'])->toBe('SW-CORE-01');
});

it('mappe le type d\'équipement', function () {
    $result = (new StorageDeviceMapper)->map(glpiStorageDevice(), ['buildings_map' => []]);

    expect($result['type'])->toBe('Baie de stockage');
});

it('résout le building_id et site_id depuis la salle GLPI', function () {
    $buildings = ['salle 101' => ['id' => 5, 'site_id' => 1]];

    $result = (new StorageDeviceMapper)->map(
        glpiStorageDevice(['locations_id' => 'Salle 101']),
        ['buildings_map' => $buildings]
    );

    expect($result['building_id'])->toBe(5);
    expect($result['site_id'])->toBe(1);
});

it('extrait l\'adresse IP depuis les ports réseau', function () {
    $result = (new StorageDeviceMapper)->map(glpiStorageDevice(), ['buildings_map' => []]);

    expect($result['address_ip'])->toBe('10.0.0.1');
});

it('résout le site_id quand la localisation est un site et non un building', function () {
    $result = (new StorageDeviceMapper)->map(
        glpiStorageDevice(['locations_id' => 'Site Distant']),
        ['buildings_map' => [], 'sites_map' => ['site distant' => 3]]
    );

    expect($result['site_id'])->toBe(3);
    expect($result['building_id'])->toBeNull();
});

it('résout le bay_id via Item_Rack puis la bay Mercator correspondante', function () {
    $result = (new StorageDeviceMapper)->map(
        glpiStorageDevice(['id' => 10]),
        [
            'buildings_map' => [],
            'item_rack_map' => ['NetworkEquipment_10' => 5],
            'racks_map' => ['5' => 42],
        ]
    );

    expect($result['bay_id'])->toBe(42);
});

it('n\'inclut pas bay_id si le dispositif n\'est dans aucun rack', function () {
    $result = (new StorageDeviceMapper)->map(
        glpiStorageDevice(['id' => 10]),
        ['buildings_map' => [], 'item_rack_map' => [], 'racks_map' => []]
    );

    expect($result)->not->toHaveKey('bay_id');
});
