<?php

use App\Services\Glpi\Mappers\WifiTerminalMapper;

function glpiWifiTerminal(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_network_devices.json'), true)[0],
        ['networkequipmenttypes_id' => 'Wifi Access Point'],
        $overrides
    );
}

it('mappe le nom de la borne wifi', function () {
    $result = (new WifiTerminalMapper)->map(glpiWifiTerminal(), ['buildings_map' => []]);

    expect($result['name'])->toBe('SW-CORE-01');
});

it('mappe le type, le vendor et le product', function () {
    $result = (new WifiTerminalMapper)->map(glpiWifiTerminal(), ['buildings_map' => []]);

    expect($result['type'])->toBe('Wifi Access Point');
    expect($result['vendor'])->toBe('Cisco');
    expect($result['product'])->toBe('Catalyst 2960');
});

it('résout le building_id et site_id depuis la salle GLPI', function () {
    $buildings = ['salle 101' => ['id' => 5, 'site_id' => 1]];

    $result = (new WifiTerminalMapper)->map(
        glpiWifiTerminal(['locations_id' => 'Salle 101']),
        ['buildings_map' => $buildings]
    );

    expect($result['building_id'])->toBe(5);
    expect($result['site_id'])->toBe(1);
});

it('extrait l\'adresse IP depuis les ports réseau', function () {
    $result = (new WifiTerminalMapper)->map(glpiWifiTerminal(), ['buildings_map' => []]);

    expect($result['address_ip'])->toBe('10.0.0.1');
});

it('résout le site_id quand la localisation est un site et non un building', function () {
    $result = (new WifiTerminalMapper)->map(
        glpiWifiTerminal(['locations_id' => 'Site Distant']),
        ['buildings_map' => [], 'sites_map' => ['site distant' => 3]]
    );

    expect($result['site_id'])->toBe(3);
    expect($result['building_id'])->toBeNull();
});
