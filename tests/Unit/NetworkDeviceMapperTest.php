<?php

use App\Services\Glpi\Mappers\NetworkDeviceMapper;

function glpiNetworkDevice(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_network_devices.json'), true)[0],
        $overrides
    );
}

it('mappe le nom du switch', function () {
    $result = (new NetworkDeviceMapper)->map(glpiNetworkDevice(), ['buildings_map' => []]);

    expect($result['name'])->toBe('SW-CORE-01');
});

it('mappe le type d\'équipement réseau', function () {
    $result = (new NetworkDeviceMapper)->map(glpiNetworkDevice(), ['buildings_map' => []]);

    expect($result['type'])->toBe('Switch');
});

it('mappe le commentaire dans la description', function () {
    $result = (new NetworkDeviceMapper)->map(glpiNetworkDevice(['id' => 10, 'comment' => 'Switch cœur de réseau']), ['buildings_map' => []]);

    expect($result['description'])->toContain('Switch cœur de réseau');
});

it('résout le building_id et site_id depuis la salle GLPI', function () {
    $buildings = ['salle 101' => ['id' => 5, 'site_id' => 1]];

    $result = (new NetworkDeviceMapper)->map(
        glpiNetworkDevice(['locations_id' => 'Salle 101']),
        ['buildings_map' => $buildings]
    );

    expect($result['building_id'])->toBe(5);
    expect($result['site_id'])->toBe(1);
});

it('résout le site_id quand la localisation est un site et non un building', function () {
    $result = (new NetworkDeviceMapper)->map(
        glpiNetworkDevice(['locations_id' => 'Site Distant']),
        ['buildings_map' => [], 'sites_map' => ['site distant' => 3]]
    );

    expect($result['site_id'])->toBe(3);
    expect($result['building_id'])->toBeNull();
});

it('inclut building_id et site_id à null si salle inconnue', function () {
    $result = (new NetworkDeviceMapper)->map(
        glpiNetworkDevice(['locations_id' => 'Salle Inconnue']),
        ['buildings_map' => []]
    );

    expect($result['building_id'])->toBeNull();
    expect($result['site_id'])->toBeNull();
});

it('mappe le fabricant en vendor', function () {
    $result = (new NetworkDeviceMapper)->map(glpiNetworkDevice(), ['buildings_map' => []]);

    expect($result['vendor'])->toBe('Cisco');
});

it('mappe le modèle en product', function () {
    $result = (new NetworkDeviceMapper)->map(glpiNetworkDevice(), ['buildings_map' => []]);

    expect($result['product'])->toBe('Catalyst 2960');
});

it('n\'inclut pas vendor si fabricant absent', function () {
    $result = (new NetworkDeviceMapper)->map(glpiNetworkDevice(['manufacturers_id' => 0]), ['buildings_map' => []]);

    expect($result)->not->toHaveKey('vendor');
});

it('ne mappe pas les champs non présents dans PhysicalSwitch Mercator', function () {
    $result = (new NetworkDeviceMapper)->map(glpiNetworkDevice(), ['buildings_map' => []]);

    expect($result)->not->toHaveKey('manufacturer');
    expect($result)->not->toHaveKey('model');
    expect($result)->not->toHaveKey('serial_number');
    expect($result)->not->toHaveKey('address_ip');
    expect($result)->not->toHaveKey('mac_address');
});

it('résout le bay_id via Item_Rack puis la bay Mercator correspondante', function () {
    $result = (new NetworkDeviceMapper)->map(
        glpiNetworkDevice(['id' => 10]),
        [
            'buildings_map' => [],
            'item_rack_map' => ['NetworkEquipment_10' => 5],
            'racks_map' => ['5' => 42],
        ]
    );

    expect($result['bay_id'])->toBe(42);
});

it('n\'inclut pas bay_id si l\'équipement n\'est dans aucun rack', function () {
    $result = (new NetworkDeviceMapper)->map(
        glpiNetworkDevice(['id' => 10]),
        ['buildings_map' => [], 'item_rack_map' => [], 'racks_map' => []]
    );

    expect($result)->not->toHaveKey('bay_id');
});

it('n\'inclut pas bay_id si le rack GLPI n\'a pas encore de bay Mercator', function () {
    $result = (new NetworkDeviceMapper)->map(
        glpiNetworkDevice(['id' => 10]),
        [
            'buildings_map' => [],
            'item_rack_map' => ['NetworkEquipment_10' => 5],
            'racks_map' => [],
        ]
    );

    expect($result)->not->toHaveKey('bay_id');
});
