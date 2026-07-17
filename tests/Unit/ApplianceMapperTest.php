<?php

use App\Services\Glpi\Mappers\ApplianceMapper;
use Illuminate\Support\Facades\Log;

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

// ── Mode "applications" (GLPI_APPLIANCE_MERCATOR_ENDPOINT=applications, issue #12) ──

it('reste en mode activities si GLPI_APPLIANCE_MERCATOR_ENDPOINT est absent (non-régression)', function () {
    $result = (new ApplianceMapper)->map(glpiAppliance());

    expect($result['name'])->toBe('ERP-PRODUCTION');
    expect($result['responsible'])->toBe('admin.applicatif');
    expect($result)->not->toHaveKey('type');
});

it('mappe vers un payload Application quand l\'endpoint configuré est "applications"', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $result = (new ApplianceMapper)->map(glpiAppliance());

    expect($result['name'])->toBe('ERP-PRODUCTION');
    expect($result['type'])->toBe('Application métier');
    expect($result['responsible'])->toBe('admin.applicatif');
    expect($result)->not->toHaveKey('vendor');
    expect($result)->not->toHaveKey('editor');
    expect($result)->not->toHaveKey('install_date');
});

it('tronque le nom d\'Appliance à 64 caractères en mode applications', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $longName = str_repeat('B', 70);
    $result = (new ApplianceMapper)->map(glpiAppliance(['name' => $longName]));

    expect($result['name'])->toHaveLength(64);
    expect($result['name'])->toBe(mb_substr($longName, 0, 64));
});

it('journalise en debug quand un nom d\'Appliance est tronqué', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);
    Log::spy();

    $longName = str_repeat('B', 80);
    (new ApplianceMapper)->map(glpiAppliance(['name' => $longName]));

    Log::shouldHaveReceived('debug')
        ->withArgs(fn (string $message) => str_contains($message, 'Nom tronqué') && str_contains($message, '64'))
        ->once();
});

it('ne tronque pas le nom en mode activities même s\'il dépasse 64 caractères', function () {
    $longName = str_repeat('B', 80);

    $result = (new ApplianceMapper)->map(glpiAppliance(['name' => $longName]));

    expect($result['name'])->toBe($longName);
});

it('retombe sur le payload activities si GLPI_APPLIANCE_MERCATOR_ENDPOINT est invalide', function () {
    config(['glpi.appliance_mercator_endpoint' => 'foo']);

    $result = (new ApplianceMapper)->map(glpiAppliance());

    expect($result)->not->toHaveKey('type');
    expect($result['name'])->toBe('ERP-PRODUCTION');
});
