<?php

use App\Services\Glpi\Handlers\ApplicationSyncHandler;
use App\Services\Glpi\Mappers\ApplicationMapper;

// ── Tests filtrage par catégorie de logiciel (GLPI_SOFTWARE_CATEGORIES) ──────

function softwareWithCategory(mixed $categoryValue): array
{
    return ['softwarecategories_id' => $categoryValue, 'name' => 'Firefox'];
}

function makeApplicationHandler(): ApplicationSyncHandler
{
    return new ApplicationSyncHandler(new ApplicationMapper);
}

it('accepte toutes les catégories si la config est vide', function () {
    config(['glpi.software_categories' => []]);

    $handler = makeApplicationHandler();

    expect($handler->filterItem(softwareWithCategory('Navigateur')))->toBeTrue();
    expect($handler->filterItem(softwareWithCategory('Bureautique')))->toBeTrue();
    expect($handler->filterItem(softwareWithCategory(0)))->toBeTrue();
});

it('filtre par nom de catégorie si configuré', function () {
    config(['glpi.software_categories' => ['Navigateur']]);

    $handler = makeApplicationHandler();

    expect($handler->filterItem(softwareWithCategory('Navigateur')))->toBeTrue();
    expect($handler->filterItem(softwareWithCategory('Bureautique')))->toBeFalse();
});

it('filtre par ID numérique', function () {
    config(['glpi.software_categories' => ['1']]);

    $handler = makeApplicationHandler();

    expect($handler->filterItem(softwareWithCategory('1')))->toBeTrue();
    expect($handler->filterItem(softwareWithCategory('2')))->toBeFalse();
});

it('est insensible à la casse', function () {
    config(['glpi.software_categories' => ['Navigateur']]);

    $handler = makeApplicationHandler();

    expect($handler->filterItem(softwareWithCategory('NAVIGATEUR')))->toBeTrue();
    expect($handler->filterItem(softwareWithCategory('navigateur')))->toBeTrue();
});

it('rejette les logiciels sans catégorie si un filtre est configuré', function () {
    config(['glpi.software_categories' => ['Navigateur']]);

    $handler = makeApplicationHandler();

    expect($handler->filterItem(softwareWithCategory(null)))->toBeFalse();
    expect($handler->filterItem(softwareWithCategory(0)))->toBeFalse();
});
