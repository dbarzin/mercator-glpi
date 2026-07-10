<?php

use App\Services\Glpi\Handlers\SiteSyncHandler;
use App\Services\Glpi\Mappers\SiteMapper;

function makeSiteHandler(): SiteSyncHandler
{
    return new SiteSyncHandler(new SiteMapper);
}

it('accepte une location racine (locations_id = 0)', function () {
    $handler = makeSiteHandler();

    expect($handler->filterItem(glpiLocation(['locations_id' => 0])))->toBeTrue();
});

it('rejette une location non racine', function () {
    $handler = makeSiteHandler();

    expect($handler->filterItem(glpiLocation(['locations_id' => 'Bâtiment A'])))->toBeFalse();
});

it('cible l\'endpoint sites', function () {
    expect(makeSiteHandler()->mercatorEndpoint())->toBe('sites');
});

it('traite les orphelins (site supprimé côté GLPI → nettoyé côté Mercator)', function () {
    expect(makeSiteHandler()->processOrphans())->toBeTrue();
});
