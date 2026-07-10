<?php

use App\Services\Glpi\Mappers\RackMapper;

function glpiRack(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_racks.json'), true)[0],
        $overrides
    );
}

it('mappe le nom du rack', function () {
    $result = (new RackMapper)->map(glpiRack(), ['buildings_map' => []]);

    expect($result['name'])->toBe('RACK-A01');
});

it('mappe le commentaire dans la description', function () {
    $result = (new RackMapper)->map(glpiRack(['id' => 5, 'comment' => 'Baie principale datacenter']), ['buildings_map' => []]);

    expect($result['description'])->toStartWith('Baie principale datacenter');
});

it('sérialise les champs GLPI non mappés dans la description', function () {
    $result = (new RackMapper)->map(glpiRack(), ['buildings_map' => []]);

    expect($result['description'])->toContain('"racktypes_id" : "Baie 42U"');
    expect($result['description'])->toContain('"states_id" : "En production"');
});

it('résout le building_id depuis la salle GLPI', function () {
    $buildings = ['salle 101' => ['id' => 5, 'site_id' => 1]];

    $result = (new RackMapper)->map(
        glpiRack(['locations_id' => 'Salle 101']),
        ['buildings_map' => $buildings]
    );

    expect($result['building_id'])->toBe(5);
});

it('inclut building_id à null si salle inconnue', function () {
    $result = (new RackMapper)->map(
        glpiRack(['locations_id' => 'Salle Inconnue']),
        ['buildings_map' => []]
    );

    expect($result['building_id'])->toBeNull();
});

it('n\'inclut pas le type GLPI (non présent dans Bay Mercator)', function () {
    $result = (new RackMapper)->map(glpiRack(), ['buildings_map' => []]);

    expect($result)->not->toHaveKey('type');
});

it('renseigne le site_id hérité du building', function () {
    $buildings = ['salle 101' => ['id' => 5, 'site_id' => 1]];

    $result = (new RackMapper)->map(
        glpiRack(['locations_id' => 'Salle 101']),
        ['buildings_map' => $buildings]
    );

    expect($result['site_id'])->toBe(1);
});

it('résout le site_id depuis une localisation racine (Site) si aucun building ne correspond', function () {
    $result = (new RackMapper)->map(
        glpiRack(['locations_id' => 'Siège Social']),
        ['buildings_map' => [], 'sites_map' => ['siège social' => 9]]
    );

    expect($result['site_id'])->toBe(9);
    expect($result['building_id'])->toBeNull();
});

it('inclut building_id et site_id à null si la localisation est inconnue', function () {
    $result = (new RackMapper)->map(
        glpiRack(['locations_id' => 'Salle Inconnue']),
        ['buildings_map' => [], 'sites_map' => []]
    );

    expect($result['building_id'])->toBeNull();
    expect($result['site_id'])->toBeNull();
});
