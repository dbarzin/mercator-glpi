<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\SiteSyncHandler;
use App\Services\Glpi\Mappers\SiteMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Mockery\MockInterface;

// ── Filtrage explicite par entité pour les Location (Site) ───────────────────
// Comme Domain, l'itemtype Location n'applique pas la restriction d'entité de
// la session côté serveur GLPI : un Site déplacé vers une autre entité (hors
// scope configuré) doit être exclu du run et donc traité comme orphelin
// (cf. issue #13).

function rootLocationsAcrossEntities(): array
{
    return [
        ['id' => 1, 'name' => 'Site A', 'locations_id' => 0, 'entities_id' => 'Entité racine > Filiale A'],
        ['id' => 2, 'name' => 'Site B', 'locations_id' => 0, 'entities_id' => 'Entité racine > Filiale B'],
    ];
}

function glpiMockForLocationEntityFilter(array $items, int $entityId, array $entity): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);
    $mock->shouldReceive('getItems')->andReturn($items);
    $mock->shouldReceive('getSubItems')->andReturn([]);
    $mock->shouldReceive('getEntityId')->andReturn($entityId);
    $mock->shouldReceive('getItem')->with('Entity', $entityId)->andReturn($entity);
    $mock->shouldReceive('getItem')->andReturn([]);

    return $mock;
}

it('exclut un Site déplacé vers une autre entité, qui devient alors orphelin', function () {
    $created = [];
    $deleted = [];

    $mercator = Mockery::mock(MercatorClientInterface::class);
    $mercator->shouldReceive('getBuildings')->andReturn([]);
    $mercator->shouldReceive('getSites')->andReturn([]);
    $mercator->shouldReceive('getAll')->with('sites')->andReturn([
        ['id' => 10, 'name' => 'Site A', 'ext_refs' => '{GLPI}1'],
        ['id' => 11, 'name' => 'Site B', 'ext_refs' => '{GLPI}2'],
    ]);
    $mercator->shouldReceive('create')->andReturnUsing(function (string $ep, array $payload) use (&$created) {
        $created[] = $payload['name'];

        return ['id' => 100];
    });
    $mercator->shouldReceive('update')->andReturnUsing(function () {});
    $mercator->shouldReceive('delete')->andReturnUsing(function (string $ep, int $id) use (&$deleted) {
        $deleted[] = $id;
    });

    // Site B a été déplacé vers "Filiale B", hors du scope configuré ("Filiale A").
    $glpi = glpiMockForLocationEntityFilter(
        rootLocationsAcrossEntities(),
        entityId: 5,
        entity: ['completename' => 'Entité racine > Filiale A'],
    );

    $stats = (new GlpiSyncService)->sync($glpi, $mercator, new SiteSyncHandler(new SiteMapper));

    expect($created)->not->toContain('Site B');
    expect($deleted)->toContain(11);
    expect($stats['deleted'])->toBe(1);
});
