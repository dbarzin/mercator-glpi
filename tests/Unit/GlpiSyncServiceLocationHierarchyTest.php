<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\LocationSyncHandler;
use App\Services\Glpi\Handlers\SiteSyncHandler;
use App\Services\Glpi\Mappers\LocationMapper;
use App\Services\Glpi\Mappers\SiteMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Mockery\MockInterface;

// ── Sync Location → buildings : héritage de site_id / building_id ─────────────
// Règle : une Location racine devient un Site (cf. SiteSyncHandler, hors de ce
// handler) ; une Location non racine devient un Building rattaché soit
// directement au Site de son parent racine (building_id = null), soit au
// Building de son parent non racine, avec propagation transitive du site_id.

function makeLocationHandler(): LocationSyncHandler
{
    return new LocationSyncHandler(new LocationMapper);
}

function glpiMockLocations(array $items): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);
    $mock->shouldReceive('getItems')->andReturn($items);
    $mock->shouldReceive('getItem')->andReturn([]);

    return $mock;
}

function mercatorMockBlank(): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getBuildings')->andReturn([]);
    $mock->shouldReceive('getSites')->andReturn([['id' => 1, 'name' => 'Siège Social']]);
    $mock->shouldReceive('getAll')->with('buildings')->andReturn([]);

    return $mock;
}

it('rattache directement au Site une Location de niveau 2 dont le parent est la racine', function () {
    $created = [];
    $nextId = 100;

    $mercator = mercatorMockBlank();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created, &$nextId) {
            $id = $nextId++;
            $created[$payload['name']] = $payload + ['id' => $id];

            return ['id' => $id] + $payload;
        });

    // La Location racine est filtrée par LocationSyncHandler::filterItem (elle
    // devient un Site via SiteSyncHandler, pas un Building). Seule la Location
    // de niveau 2 doit produire un Building ici.
    $glpi = glpiMockLocations([
        ['id' => 2, 'name' => 'Salle 101', 'comment' => '', 'locations_id' => 'Siège Social', 'level' => 2],
        ['id' => 1, 'name' => 'Siège Social', 'comment' => '', 'locations_id' => 0, 'level' => 1],
    ]);

    (new GlpiSyncService)->sync($glpi, $mercator, makeLocationHandler());

    expect($created)->not->toHaveKey('Siège Social');
    expect($created['Salle 101']['site_id'])->toBe(1);
    expect($created['Salle 101']['building_id'])->toBeNull();
});

it('propage le site_id transitivement sur une hiérarchie à 3 niveaux', function () {
    $created = [];
    $nextId = 200;

    $mercator = mercatorMockBlank();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created, &$nextId) {
            $id = $nextId++;
            $created[$payload['name']] = $payload + ['id' => $id];

            return ['id' => $id] + $payload;
        });

    // GLPI renvoie les Location dans le désordre : seul le tri par "level" (étape 3c)
    // garantit que le Building parent est créé avant qu'on tente de résoudre le
    // Building enfant qui en dépend (buildings_map mis à jour à chaud, étape 6).
    $glpi = glpiMockLocations([
        ['id' => 3, 'name' => 'Baie A', 'comment' => '', 'locations_id' => 'Siège Social > Salle 101', 'level' => 3],
        ['id' => 1, 'name' => 'Siège Social', 'comment' => '', 'locations_id' => 0, 'level' => 1],
        ['id' => 2, 'name' => 'Salle 101', 'comment' => '', 'locations_id' => 'Siège Social', 'level' => 2],
    ]);

    (new GlpiSyncService)->sync($glpi, $mercator, makeLocationHandler());

    expect($created)->not->toHaveKey('Siège Social');
    expect($created['Salle 101']['building_id'] ?? null)->toBeNull();
    expect($created['Salle 101']['site_id'])->toBe(1);
    expect($created['Baie A']['building_id'])->toBe($created['Salle 101']['id']);
    expect($created['Baie A']['site_id'])->toBe(1);
});

it('met à jour plutôt que de dupliquer un Building déjà synchronisé (idempotence)', function () {
    $store = [];
    $nextId = 300;

    $mercator = Mockery::mock(MercatorClientInterface::class);
    $mercator->shouldReceive('getSites')->andReturn([['id' => 1, 'name' => 'Siège Social']]);
    $mercator->shouldReceive('getBuildings')->andReturnUsing(function () use (&$store) {
        return array_values($store);
    });
    $mercator->shouldReceive('getAll')->with('buildings')->andReturnUsing(function () use (&$store) {
        return array_values($store);
    });
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$store, &$nextId) {
            $id = $nextId++;
            $store[$id] = $payload + ['id' => $id];

            return ['id' => $id] + $payload;
        });
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$store) {
            $store[$id] = $payload + ['id' => $id];

            return ['id' => $id] + $payload;
        });

    $glpi = glpiMockLocations([
        ['id' => 2, 'name' => 'Salle 101', 'comment' => '', 'locations_id' => 'Siège Social', 'level' => 2],
        ['id' => 1, 'name' => 'Siège Social', 'comment' => '', 'locations_id' => 0, 'level' => 1],
    ]);

    $service = new GlpiSyncService;
    $handler = makeLocationHandler();

    $first = $service->sync($glpi, $mercator, $handler);
    $second = $service->sync($glpi, $mercator, $handler);

    expect($first['created'])->toBe(1);
    expect($first['updated'])->toBe(0);
    expect($second['created'])->toBe(0);
    expect($second['updated'])->toBe(1);
    expect($store)->toHaveCount(1);
});

// ── Suppression de Location côté GLPI → nettoyage côté Mercator (issue #13) ───

it('supprime le Building Mercator dont la Location non racine a été supprimée côté GLPI', function () {
    $deleted = [];
    $updated = [];

    $mercator = Mockery::mock(MercatorClientInterface::class);
    $mercator->shouldReceive('getSites')->andReturn([]);
    $mercator->shouldReceive('getBuildings')->andReturn([
        ['id' => 50, 'name' => 'Salle 101', 'ext_refs' => '{GLPI}2'],
        ['id' => 51, 'name' => 'Salle Manuelle', 'ext_refs' => null],
    ]);
    $mercator->shouldReceive('getAll')->with('buildings')->andReturn([
        ['id' => 50, 'name' => 'Salle 101', 'ext_refs' => '{GLPI}2'],
        ['id' => 51, 'name' => 'Salle Manuelle', 'ext_refs' => null],
    ]);
    $mercator->shouldReceive('delete')
        ->andReturnUsing(function (string $ep, int $id) use (&$deleted) {
            $deleted[] = $id;
        });
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    // "Salle 101" (id GLPI 2) n'existe plus côté GLPI : seule "Siège Social" (racine,
    // filtrée par LocationSyncHandler::filterItem) est encore renvoyée.
    $glpi = glpiMockLocations([
        ['id' => 1, 'name' => 'Siège Social', 'comment' => '', 'locations_id' => 0, 'level' => 1],
    ]);

    $stats = (new GlpiSyncService)->sync($glpi, $mercator, makeLocationHandler());

    // Building tagué {GLPI}2 (créé par le connecteur) → supprimé
    expect($deleted)->toContain(50);
    // Building sans tag (créé manuellement) → jamais supprimé, marqué [OLD]
    expect($deleted)->not->toContain(51);
    $markedOld = collect($updated)->first(fn ($u) => $u['id'] === 51);
    expect($markedOld['payload']['name'])->toBe('[OLD] Salle Manuelle');
    expect($stats['deleted'])->toBe(1);
    expect($stats['marked_old'])->toBe(1);
});

it('supprime le Site Mercator dont la Location racine a été supprimée côté GLPI', function () {
    $deleted = [];
    $updated = [];

    $mercator = Mockery::mock(MercatorClientInterface::class);
    $mercator->shouldReceive('getSites')->andReturn([
        ['id' => 60, 'name' => 'Ancien Siège', 'ext_refs' => '{GLPI}9'],
    ]);
    $mercator->shouldReceive('getBuildings')->andReturn([]);
    $mercator->shouldReceive('getAll')->with('sites')->andReturn([
        ['id' => 60, 'name' => 'Ancien Siège', 'ext_refs' => '{GLPI}9'],
        ['id' => 61, 'name' => 'Siège Manuel', 'ext_refs' => null],
    ]);
    $mercator->shouldReceive('delete')
        ->andReturnUsing(function (string $ep, int $id) use (&$deleted) {
            $deleted[] = $id;
        });
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    // "Ancien Siège" (id GLPI 9) n'existe plus côté GLPI.
    $glpi = glpiMockLocations([]);

    $handler = new SiteSyncHandler(new SiteMapper);
    $stats = (new GlpiSyncService)->sync($glpi, $mercator, $handler);

    // Site tagué {GLPI}9 (créé par le connecteur) → supprimé
    expect($deleted)->toContain(60);
    // Site sans tag (créé manuellement) → jamais supprimé, marqué [OLD]
    expect($deleted)->not->toContain(61);
    $markedOld = collect($updated)->first(fn ($u) => $u['id'] === 61);
    expect($markedOld['payload']['name'])->toBe('[OLD] Siège Manuel');
    expect($stats['deleted'])->toBe(1);
    expect($stats['marked_old'])->toBe(1);
});
