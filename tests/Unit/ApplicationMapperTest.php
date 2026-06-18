<?php

use App\Services\Glpi\Mappers\ApplicationMapper;

// ── Helper ────────────────────────────────────────────────────────────────────

function glpiSoftware(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_softwares.json'), true)[0],
        $overrides
    );
}

// ── Champs de base ────────────────────────────────────────────────────────────

it('mappe le nom du logiciel', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware());

    expect($result['name'])->toBe('Firefox');
});

it('mappe product avec le nom du logiciel', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware());

    expect($result['product'])->toBe('Firefox');
});

it('mappe le fabricant en vendor et editor', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware());

    expect($result['vendor'])->toBe('Mozilla');
    expect($result['editor'])->toBe('Mozilla');
});

it('mappe la catégorie en type', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware());

    expect($result['type'])->toBe('Navigateur');
});

it('mappe le technicien en responsible', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware());

    expect($result['responsible'])->toBe('admin.sys');
});

it('mappe la date d\'ajout en install_date', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware());

    expect($result['install_date'])->toBe('2023-01-15');
});

// ── Description ──────────────────────────────────────────────────────────────

it('mappe le commentaire dans la description', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware(['id' => 10, 'comment' => 'Mon logiciel']));

    expect($result['description'])->toStartWith('Mon logiciel');
});

it('ne mappe pas de description si le commentaire est vide (pas de champs non mappés)', function () {
    $result = (new ApplicationMapper)->map([
        'id' => 10, 'name' => 'X', 'comment' => '',
        'manufacturers_id' => 'M', 'softwarecategories_id' => 'C',
        'users_id_tech' => 'U', 'date' => '2024-01-01', 'locations_id' => 0,
    ]);

    expect($result)->not->toHaveKey('description');
});

it('sérialise les champs GLPI non mappés dans la description', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware());

    expect($result['description'])->toContain('"is_valid_license" : "1"');
});

// ── Valeurs nulles ────────────────────────────────────────────────────────────

it('ignore les champs dropdowns à 0 (non renseignés)', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware([
        'manufacturers_id' => 0,
        'softwarecategories_id' => '0',
        'users_id_tech' => 0,
    ]));

    expect($result)->not->toHaveKey('vendor');
    expect($result)->not->toHaveKey('editor');
    expect($result)->not->toHaveKey('type');
    expect($result)->not->toHaveKey('responsible');
});

it('ignore la date si absente ou nulle', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware(['date' => null]));

    expect($result)->not->toHaveKey('install_date');
});

it('ignore la date 0000-00-00', function () {
    $result = (new ApplicationMapper)->map(glpiSoftware(['date' => '0000-00-00']));

    expect($result)->not->toHaveKey('install_date');
});
