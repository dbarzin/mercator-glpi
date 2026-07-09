<?php

use App\Services\Glpi\Mappers\PeripheralMapper;

// ── Helper ────────────────────────────────────────────────────────────────────

function glpiPeripheral(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_peripherals.json'), true)[0],
        $overrides
    );
}

function peripheralBuildingsMap(): array
{
    $buildings = json_decode(
        file_get_contents(__DIR__.'/../Fixtures/mercator_buildings.json'),
        true
    )['data'];

    $map = [];
    foreach ($buildings as $b) {
        $map[strtolower($b['name'])] = [
            'id' => $b['id'],
            'site_id' => $b['site_id'] ?? null,
        ];
    }

    return $map;
}

// ── Champs de base ────────────────────────────────────────────────────────────

it('mappe le nom du périphérique', function () {
    $result = (new PeripheralMapper)->map(glpiPeripheral(), ['buildings_map' => []]);

    expect($result['name'])->toBe('Imprimante-RDC');
});

it('mappe le fabricant en vendor', function () {
    $result = (new PeripheralMapper)->map(glpiPeripheral(), ['buildings_map' => []]);

    expect($result['vendor'])->toBe('HP');
});

it('mappe le type', function () {
    $result = (new PeripheralMapper)->map(glpiPeripheral(), ['buildings_map' => []]);

    expect($result['type'])->toBe('Imprimante');
});

it('mappe le modèle en product', function () {
    $result = (new PeripheralMapper)->map(glpiPeripheral(), ['buildings_map' => []]);

    expect($result['product'])->toBe('LaserJet Pro M404');
});

it('mappe le technicien en responsible', function () {
    $result = (new PeripheralMapper)->map(glpiPeripheral(), ['buildings_map' => []]);

    expect($result['responsible'])->toBe('admin.sys');
});

// ── Description ──────────────────────────────────────────────────────────────

it('mappe le commentaire dans la description', function () {
    $result = (new PeripheralMapper)->map(
        glpiPeripheral(['id' => 5, 'comment' => 'Mon périphérique']),
        ['buildings_map' => []]
    );

    expect($result['description'])->toBe('Mon périphérique');
});

it('ne mappe pas de description si le commentaire est vide', function () {
    $result = (new PeripheralMapper)->map(
        glpiPeripheral(['id' => 5, 'comment' => '']),
        ['buildings_map' => []]
    );

    expect($result)->not->toHaveKey('description');
});

// ── Localisation ─────────────────────────────────────────────────────────────

it('résout building_id et site_id depuis le nom de la salle', function () {
    $result = (new PeripheralMapper)->map(
        glpiPeripheral(['locations_id' => 'Salle 101']),
        ['buildings_map' => peripheralBuildingsMap()]
    );

    expect($result['building_id'])->toBe(5);
    expect($result['site_id'])->toBe(1);
});

it('résout le site_id quand la localisation est un site et non un building', function () {
    $result = (new PeripheralMapper)->map(
        glpiPeripheral(['locations_id' => 'Site Distant']),
        ['buildings_map' => peripheralBuildingsMap(), 'sites_map' => ['site distant' => 3]]
    );

    expect($result['site_id'])->toBe(3);
    expect($result['building_id'])->toBeNull();
});

it('laisse building_id et site_id à null si la salle est inconnue', function () {
    $result = (new PeripheralMapper)->map(
        glpiPeripheral(['locations_id' => 'Salle Inconnue']),
        ['buildings_map' => peripheralBuildingsMap()]
    );

    expect($result['building_id'])->toBeNull();
    expect($result['site_id'])->toBeNull();
});

it('résout la localisation insensiblement à la casse', function () {
    $result = (new PeripheralMapper)->map(
        glpiPeripheral(['locations_id' => 'SALLE 101']),
        ['buildings_map' => peripheralBuildingsMap()]
    );

    expect($result['building_id'])->toBe(5);
});

// ── Réseau ────────────────────────────────────────────────────────────────────

it('extrait l\'adresse IP depuis les ports réseau', function () {
    $result = (new PeripheralMapper)->map(glpiPeripheral(), ['buildings_map' => []]);

    expect($result['address_ip'])->toBe('192.168.1.50');
});

it('ne plante pas si aucun port réseau', function () {
    $result = (new PeripheralMapper)->map(
        glpiPeripheral(['_networkports' => []]),
        ['buildings_map' => []]
    );

    expect($result)->not->toHaveKey('address_ip');
});

// ── Valeurs nulles ────────────────────────────────────────────────────────────

it('ignore les champs dropdowns à 0', function () {
    $result = (new PeripheralMapper)->map(glpiPeripheral([
        'manufacturers_id' => 0,
        'peripheraltypes_id' => '0',
        'peripheralmodels_id' => 0,
        'users_id_tech' => 0,
    ]), ['buildings_map' => []]);

    expect($result)->not->toHaveKey('vendor');
    expect($result)->not->toHaveKey('type');
    expect($result)->not->toHaveKey('product');
    expect($result)->not->toHaveKey('responsible');
});
