<?php

use App\Services\Glpi\Handlers\LocationSyncHandler;
use App\Services\Glpi\Mappers\LocationMapper;

function makeLocationSyncHandler(): LocationSyncHandler
{
    return new LocationSyncHandler(new LocationMapper);
}

it('rejette une location racine (devient un Site, cf. SiteSyncHandler)', function () {
    expect(makeLocationSyncHandler()->filterItem(glpiLocation(['locations_id' => 0])))->toBeFalse();
});

it('accepte une location non racine', function () {
    expect(makeLocationSyncHandler()->filterItem(glpiLocation(['locations_id' => 'Bâtiment A'])))->toBeTrue();
});

it('cible l\'endpoint buildings', function () {
    expect(makeLocationSyncHandler()->mercatorEndpoint())->toBe('buildings');
});

it('traite les orphelins (building supprimé côté GLPI → nettoyé côté Mercator)', function () {
    expect(makeLocationSyncHandler()->processOrphans())->toBeTrue();
});
