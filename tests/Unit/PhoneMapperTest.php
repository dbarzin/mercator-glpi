<?php

use App\Services\Glpi\Mappers\PhoneMapper;

// ── Helper ────────────────────────────────────────────────────────────────────

function glpiPhone(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_phones.json'), true)[0],
        $overrides
    );
}

function phoneBuildingsMap(): array
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

it('mappe le nom du téléphone', function () {
    $result = (new PhoneMapper)->map(glpiPhone(), ['buildings_map' => []]);

    expect($result['name'])->toBe('TEL-DG-01');
});

it('mappe le fabricant en vendor', function () {
    $result = (new PhoneMapper)->map(glpiPhone(), ['buildings_map' => []]);

    expect($result['vendor'])->toBe('Cisco');
});

it('mappe le type', function () {
    $result = (new PhoneMapper)->map(glpiPhone(), ['buildings_map' => []]);

    expect($result['type'])->toBe('IP');
});

it('mappe le modèle en product', function () {
    $result = (new PhoneMapper)->map(glpiPhone(), ['buildings_map' => []]);

    expect($result['product'])->toBe('IP Phone 8841');
});

// ── Description ──────────────────────────────────────────────────────────────

it('mappe le commentaire dans la description', function () {
    $result = (new PhoneMapper)->map(
        glpiPhone(['id' => 3, 'comment' => 'Téléphone DG']),
        ['buildings_map' => []]
    );

    expect($result['description'])->toBe('Téléphone DG');
});

it('ne mappe pas de description si le commentaire est vide', function () {
    $result = (new PhoneMapper)->map(
        glpiPhone(['id' => 3, 'comment' => '']),
        ['buildings_map' => []]
    );

    expect($result)->not->toHaveKey('description');
});

// ── Localisation ─────────────────────────────────────────────────────────────

it('résout building_id et site_id depuis le nom de la salle', function () {
    $result = (new PhoneMapper)->map(
        glpiPhone(['locations_id' => 'Salle 101']),
        ['buildings_map' => phoneBuildingsMap()]
    );

    expect($result['building_id'])->toBe(5);
    expect($result['site_id'])->toBe(1);
});

it('résout le site_id quand la localisation est un site et non un building', function () {
    $result = (new PhoneMapper)->map(
        glpiPhone(['locations_id' => 'Site Distant']),
        ['buildings_map' => phoneBuildingsMap(), 'sites_map' => ['site distant' => 3]]
    );

    expect($result['site_id'])->toBe(3);
    expect($result['building_id'])->toBeNull();
});

it('laisse building_id et site_id à null si la salle est inconnue', function () {
    $result = (new PhoneMapper)->map(
        glpiPhone(['locations_id' => 'Salle Inconnue']),
        ['buildings_map' => phoneBuildingsMap()]
    );

    expect($result['building_id'])->toBeNull();
    expect($result['site_id'])->toBeNull();
});

it('résout la localisation insensiblement à la casse', function () {
    $result = (new PhoneMapper)->map(
        glpiPhone(['locations_id' => 'SALLE 101']),
        ['buildings_map' => phoneBuildingsMap()]
    );

    expect($result['building_id'])->toBe(5);
});

// ── Réseau ────────────────────────────────────────────────────────────────────

it('extrait l\'adresse IP depuis les ports réseau', function () {
    $result = (new PhoneMapper)->map(glpiPhone(), ['buildings_map' => []]);

    expect($result['address_ip'])->toBe('10.0.1.10');
});

it('ne plante pas si aucun port réseau', function () {
    $result = (new PhoneMapper)->map(
        glpiPhone(['_networkports' => []]),
        ['buildings_map' => []]
    );

    expect($result)->not->toHaveKey('address_ip');
});

// ── Valeurs nulles ────────────────────────────────────────────────────────────

it('ignore les champs dropdowns à 0', function () {
    $result = (new PhoneMapper)->map(glpiPhone([
        'manufacturers_id' => 0,
        'phonetypes_id' => '0',
        'phonemodels_id' => 0,
    ]), ['buildings_map' => []]);

    expect($result)->not->toHaveKey('vendor');
    expect($result)->not->toHaveKey('type');
    expect($result)->not->toHaveKey('product');
});
