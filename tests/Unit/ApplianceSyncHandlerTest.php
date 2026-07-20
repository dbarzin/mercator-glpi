<?php

use App\Services\Glpi\Contracts\SupportsCustomExtRefsTag;
use App\Services\Glpi\Handlers\ApplianceSyncHandler;
use App\Services\Glpi\Mappers\ApplianceMapper;
use Illuminate\Support\Facades\Log;

function makeApplianceHandler(): ApplianceSyncHandler
{
    return new ApplianceSyncHandler(new ApplianceMapper);
}

it('implémente SupportsCustomExtRefsTag', function () {
    expect(makeApplianceHandler())->toBeInstanceOf(SupportsCustomExtRefsTag::class);
});

it('cible activities et le tag {GLPI} par défaut (sans configuration, non-régression)', function () {
    $handler = makeApplianceHandler();

    expect($handler->mercatorEndpoint())->toBe('activities');
    expect($handler->extRefsTag())->toBe('{GLPI}');
});

it('cible applications et le tag {GLPI-Appliance} quand configuré', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);
    $handler = makeApplianceHandler();

    expect($handler->mercatorEndpoint())->toBe('applications');
    expect($handler->extRefsTag())->toBe('{GLPI-Appliance}');
});

it('retombe sur activities et journalise un warning si la valeur est invalide', function () {
    config(['glpi.appliance_mercator_endpoint' => 'foo']);
    Log::spy();

    $handler = makeApplianceHandler();

    expect($handler->mercatorEndpoint())->toBe('activities');
    expect($handler->extRefsTag())->toBe('{GLPI}');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'GLPI_APPLIANCE_MERCATOR_ENDPOINT') && str_contains($message, 'foo'))
        ->atLeast()->once();
});
