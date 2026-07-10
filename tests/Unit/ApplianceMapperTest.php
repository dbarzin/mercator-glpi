<?php

use App\Services\Glpi\Mappers\ApplianceMapper;

function glpiAppliance(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_appliances.json'), true)[0],
        $overrides
    );
}

it('mappe le nom de l\'activité', function () {
    $result = (new ApplianceMapper)->map(glpiAppliance());

    expect($result['name'])->toBe('ERP-PRODUCTION');
});

it('mappe le responsable technique', function () {
    $result = (new ApplianceMapper)->map(glpiAppliance());

    expect($result['responsible'])->toBe('admin.applicatif');
});

it('mappe le commentaire dans la description', function () {
    $result = (new ApplianceMapper)->map(glpiAppliance(['id' => 7, 'comment' => 'Application ERP principal']));

    expect($result['description'])->toContain('Application ERP principal');
});

it('n\'inclut pas le responsable si vide', function () {
    $result = (new ApplianceMapper)->map(glpiAppliance(['users_id_tech' => '']));

    expect($result)->not->toHaveKey('responsible');
});

it('ne mappe pas les champs non présents dans Activity Mercator', function () {
    $result = (new ApplianceMapper)->map(glpiAppliance());

    expect($result)->not->toHaveKey('type');
    expect($result)->not->toHaveKey('building_id');
    expect($result)->not->toHaveKey('site_id');
});
