<?php

use App\Services\Glpi\Mappers\LocationMapper;

function glpiLocation(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_locations.json'), true)[0],
        $overrides
    );
}

it('mappe le nom du bâtiment', function () {
    $result = (new LocationMapper)->map(glpiLocation());

    expect($result['name'])->toBe('Siège Social');
});

it('mappe le commentaire dans la description', function () {
    $result = (new LocationMapper)->map(glpiLocation(['id' => 3, 'comment' => 'Bâtiment principal']));

    expect($result['description'])->toStartWith('Bâtiment principal');
});

it('ne mappe pas de description si commentaire vide (pas de champs non mappés)', function () {
    $result = (new LocationMapper)->map(['id' => 1, 'name' => 'X', 'comment' => '', 'locations_id' => 0], []);

    expect($result)->not->toHaveKey('description');
});

it('sérialise les champs géographiques GLPI non mappés dans la description', function () {
    $result = (new LocationMapper)->map(glpiLocation());

    expect($result['description'])->toContain('"address" : "1 rue de la Paix"');
    expect($result['description'])->toContain('"town" : "Paris"');
    expect($result['description'])->toContain('"country" : "France"');
});

it('ne mappe pas les champs géographiques (non présents dans Building Mercator)', function () {
    $result = (new LocationMapper)->map(glpiLocation());

    expect($result)->not->toHaveKey('city');
    expect($result)->not->toHaveKey('country');
    expect($result)->not->toHaveKey('address');
    expect($result)->not->toHaveKey('zipcode');
    expect($result)->not->toHaveKey('latitude');
    expect($result)->not->toHaveKey('longitude');
});

it('résout building_id depuis la location parente GLPI', function () {
    $map = ['bâtiment a' => ['id' => 7, 'site_id' => 2]];
    $result = (new LocationMapper)->map(
        glpiLocation(['locations_id' => 'Bâtiment A']),
        ['buildings_map' => $map]
    );

    expect($result['building_id'])->toBe(7);
});

it('inclut building_id à null si pas de location parente', function () {
    $result = (new LocationMapper)->map(glpiLocation(['locations_id' => 0]), ['buildings_map' => []]);

    expect($result['building_id'])->toBeNull();
});

it('inclut building_id à null si location parente inconnue dans Mercator', function () {
    $result = (new LocationMapper)->map(
        glpiLocation(['locations_id' => 'Inconnue']),
        ['buildings_map' => []]
    );

    expect($result['building_id'])->toBeNull();
});

it('rattache directement au Site quand le parent est une Location racine', function () {
    $result = (new LocationMapper)->map(
        glpiLocation(['name' => 'Bâtiment A', 'locations_id' => 'Siège Social']),
        ['sites_map' => ['siège social' => 9]]
    );

    expect($result['site_id'])->toBe(9);
    expect($result['building_id'])->toBeNull();
});

it('inclut site_id à null si la location racine parente n\'a pas de site correspondant', function () {
    $result = (new LocationMapper)->map(
        glpiLocation(['locations_id' => 'Siège Social']),
        ['sites_map' => []]
    );

    expect($result['site_id'])->toBeNull();
});

it('hérite du site_id du building parent pour une location non racine', function () {
    $map = ['bâtiment a' => ['id' => 7, 'site_id' => 2]];
    $result = (new LocationMapper)->map(
        glpiLocation(['locations_id' => 'Bâtiment A']),
        ['buildings_map' => $map]
    );

    expect($result['building_id'])->toBe(7);
    expect($result['site_id'])->toBe(2);
});

it('résout building_id depuis le chemin complet renvoyé par GLPI pour une location petite-fille', function () {
    // GLPI expand_dropdowns renvoie le chemin complet ("Siège Social > Bâtiment A")
    // pour le parent d'une location imbriquée à plus d'un niveau.
    $map = ['bâtiment a' => ['id' => 7, 'site_id' => 2]];
    $result = (new LocationMapper)->map(
        glpiLocation(['name' => 'Salle 101', 'locations_id' => 'Siège Social > Bâtiment A']),
        ['buildings_map' => $map]
    );

    expect($result['building_id'])->toBe(7);
    expect($result['site_id'])->toBe(2);
});
