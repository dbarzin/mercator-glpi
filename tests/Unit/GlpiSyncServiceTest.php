<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\ApplicationSyncHandler;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Glpi\Mappers\ApplicationMapper;
use App\Services\Glpi\Mappers\WorkstationMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Mockery\MockInterface;

// ── Helpers locaux ────────────────────────────────────────────────────────────

function makeHandler(): WorkstationSyncHandler
{
    return new WorkstationSyncHandler(new WorkstationMapper);
}

/**
 * Mock de GlpiClientInterface — aucun constructeur, aucun readonly, mockable sans crash.
 */
function glpiMock(array $items = []): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);
    $mock->shouldReceive('getItems')->andReturn($items);
    $mock->shouldReceive('getItem')->andReturn([]);
    $mock->shouldReceive('getSubItems')->andReturn([]);

    return $mock;
}

/**
 * Mock de MercatorClientInterface.
 */
function mercatorMock(array $workstations = [], array $buildings = []): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getBuildings')->andReturn($buildings);
    $mock->shouldReceive('getSites')->andReturn([]);
    $mock->shouldReceive('getAll')->andReturn($workstations);

    return $mock;
}

// ── Création ──────────────────────────────────────────────────────────────────

it('crée un workstation absent de Mercator', function () {
    $created = [];

    $mercator = mercatorMock([], mercatorBuildingsFixture());
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 99];
        });

    (new GlpiSyncService)->sync(
        glpiMock(glpiComputersFixture()),
        $mercator,
        makeHandler(),
    );

    expect(collect($created)->pluck('name'))
        ->toContain('PC-DIDIER-01')
        ->toContain('PC-NOUVEAU-01');
});

it('ne porte plus le tag glpi_id dans la description lors de la création', function () {
    $created = [];

    $mercator = mercatorMock([], mercatorBuildingsFixture());
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 99];
        });

    (new GlpiSyncService)->sync(
        glpiMock(glpiComputersFixture()),
        $mercator,
        makeHandler(),
    );

    $didier = collect($created)->firstWhere('name', 'PC-DIDIER-01');
    expect($didier['description'])->not->toContain('glpi_id');
});

// ── Enrichissement item par item (with_networkports, with_disks…) ─────────────

it('ne demande pas with_networkports/with_devices/with_disks/with_infocoms sur la collection', function () {
    $params = makeHandler()->glpiQueryParams();

    expect($params)->not->toHaveKey('with_networkports');
    expect($params)->not->toHaveKey('with_devices');
    expect($params)->not->toHaveKey('with_disks');
    expect($params)->not->toHaveKey('with_infocoms');
});

it('enrichit chaque item via getItem() et fusionne le détail avant le mapping', function () {
    $items = [glpiComputersFixture()[0]];

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->andReturn($items);
    $glpi->shouldReceive('getItem')
        ->once()
        ->with('Computer', $items[0]['id'], makeHandler()->glpiDetailParams())
        ->andReturn(['ram' => 8192]);
    $glpi->shouldReceive('getSubItems')->andReturn([]);

    $created = [];
    $mercator = mercatorMock([], mercatorBuildingsFixture());
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 99];
        });

    (new GlpiSyncService)->sync($glpi, $mercator, makeHandler());

    expect($created[0]['memory'])->toBe('8 Go');
});

// ── Réconciliation via ext_refs ({GLPI}id) ────────────────────────────────────

it('ajoute le tag {GLPI}id dans ext_refs lors de la création', function () {
    $created = [];

    $mercator = mercatorMock([], mercatorBuildingsFixture());
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 99];
        });

    (new GlpiSyncService)->sync(
        glpiMock(glpiComputersFixture()),
        $mercator,
        makeHandler(),
    );

    $didier = collect($created)->firstWhere('name', 'PC-DIDIER-01');
    expect($didier['ext_refs'])->toBe('{GLPI}42');
});

it('reconnait un workstation renommé dans GLPI grâce à ext_refs au lieu du nom', function () {
    $updated = [];

    $mercator = mercatorMock([
        ['id' => 10, 'name' => 'ANCIEN-NOM', 'description' => '', 'ext_refs' => '{GLPI}42'],
    ], mercatorBuildingsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });
    $mercator->shouldReceive('create')->andReturn(['id' => 99]);

    (new GlpiSyncService)->sync(
        glpiMock([glpiComputersFixture()[0]]), // PC-DIDIER-01, id GLPI 42
        $mercator,
        makeHandler(),
    );

    // Le nom a changé côté GLPI mais l'item Mercator id=10 est bien reconnu via ext_refs
    $update = collect($updated)->first(fn ($u) => $u['id'] === 10);
    expect($update)->not->toBeNull();
    expect($update['payload']['name'])->toBe('PC-DIDIER-01');
    expect($update['payload']['ext_refs'])->toBe('{GLPI}42');
});

it('préserve les références externes d\'autres sources dans ext_refs', function () {
    $updated = [];

    $mercator = mercatorMock([
        ['id' => 10, 'name' => 'PC-DIDIER-01', 'description' => '', 'ext_refs' => '{PROXMOX}vm123|{GLPI}999'],
    ], mercatorBuildingsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });
    $mercator->shouldReceive('create')->andReturn(['id' => 99]);

    (new GlpiSyncService)->sync(
        glpiMock([glpiComputersFixture()[0]]), // id GLPI 42, matché par nom (ext_refs pointait sur 999)
        $mercator,
        makeHandler(),
    );

    $update = collect($updated)->first(fn ($u) => $u['id'] === 10);
    expect($update['payload']['ext_refs'])->toBe('{PROXMOX}vm123|{GLPI}42');
});

// ── Mise à jour ───────────────────────────────────────────────────────────────

it('met à jour un workstation existant dans Mercator', function () {
    $updated = [];

    $mercator = mercatorMock(mercatorWorkstationsFixture(), mercatorBuildingsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('ep', 'id', 'payload');

            return [];
        });
    $mercator->shouldReceive('delete')->andReturn(null);
    $mercator->shouldReceive('create')->andReturn(['id' => 99]);

    (new GlpiSyncService)->sync(
        glpiMock(glpiComputersFixture()),
        $mercator,
        makeHandler(),
    );

    // PC-DIDIER-01 (id Mercator 10) doit être mis à jour
    $update = collect($updated)->first(fn ($u) => $u['id'] === 10);
    expect($update)->not->toBeNull();
    expect($update['ep'])->toBe('workstations');
});

it('met à jour le système d\'exploitation', function () {
    $updated = [];
    $items = glpiComputersFixture();

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->andReturn($items);
    $glpi->shouldReceive('getItem')->andReturn([]);
    $glpi->shouldReceive('getSubItems')
        ->with('Computer', 42, 'Item_OperatingSystem', ['expand_dropdowns' => 1])
        ->andReturn([['operatingsystems_id' => 'Windows 11 Pro']]);
    $glpi->shouldReceive('getSubItems')->andReturn([]);

    $mercator = mercatorMock(mercatorWorkstationsFixture(), mercatorBuildingsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });
    $mercator->shouldReceive('delete')->andReturn(null);
    $mercator->shouldReceive('create')->andReturn(['id' => 99]);

    (new GlpiSyncService)->sync($glpi, $mercator, makeHandler());

    // GLPI (Item_OperatingSystem) a "Windows 11 Pro", Mercator avait "Windows 10 Pro"
    $update = collect($updated)->first(fn ($u) => $u['id'] === 10);
    expect($update['payload']['operating_system'])->toBe('Windows 11 Pro');
});

// ── Les workstations absentes de GLPI sont ignorées ──────────────────────────

it('supprime un workstation orphelin tagué {GLPI} et marque OLD celui créé manuellement', function () {
    $deleted = [];
    $updated = [];

    $mercator = mercatorMock(mercatorWorkstationsFixture(), mercatorBuildingsFixture());
    $mercator->shouldReceive('delete')
        ->andReturnUsing(function (string $ep, int $id) use (&$deleted) {
            $deleted[] = $id;
        });
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    (new GlpiSyncService)->sync(
        glpiMock([glpiComputersFixture()[0]]),   // seul PC-DIDIER-01 dans GLPI
        $mercator,
        makeHandler(),
    );

    // PC-ANCIEN-GLPI (id 11, ext_refs {GLPI}99) : créé par le connecteur → supprimé
    expect($deleted)->toContain(11);
    // PC-MANUEL-01 (id 12, sans ext_refs) : créé manuellement → marqué [OLD], jamais supprimé
    expect($deleted)->not->toContain(12);
    $markedOld = collect($updated)->first(fn ($u) => $u['id'] === 12);
    expect($markedOld['payload']['name'])->toBe('[OLD] PC-MANUEL-01');
});

it('ne double-préfixe pas un workstation déjà marqué OLD', function () {
    $updated = [];

    $mercator = mercatorMock(
        [['id' => 20, 'name' => '[OLD] PC-DEJA-OLD', 'description' => 'Sans tag GLPI']],
        []
    );
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    (new GlpiSyncService)->sync(
        glpiMock([]),   // GLPI vide
        $mercator,
        makeHandler(),
    );

    // Aucun update sur l'id 20
    expect(collect($updated)->first(fn ($u) => $u['id'] === 20))->toBeNull();
});

// ── Dry-run ───────────────────────────────────────────────────────────────────

it('ne fait aucune écriture en mode dry-run', function () {
    $mercator = mercatorMock(mercatorWorkstationsFixture(), mercatorBuildingsFixture());
    $mercator->shouldNotReceive('create');
    $mercator->shouldNotReceive('update');
    $mercator->shouldNotReceive('delete');

    (new GlpiSyncService)->sync(
        glpiMock(glpiComputersFixture()),
        $mercator,
        makeHandler(),
        dryRun: true,
    );
});

// ── Résolution building_id ────────────────────────────────────────────────────

it('résout le building_id depuis le nom de la salle GLPI', function () {
    $created = [];

    $mercator = mercatorMock([], mercatorBuildingsFixture());
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 99];
        });

    // PC-DIDIER-01 seul : locations_id = "Salle 101" → building_id = 5
    (new GlpiSyncService)->sync(
        glpiMock([glpiComputersFixture()[0]]),
        $mercator,
        makeHandler(),
    );

    expect($created[0]['building_id'])->toBe(5);
});

it('assigne le site_id du building à la workstation', function () {
    $created = [];

    $mercator = mercatorMock([], mercatorBuildingsFixture());
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 99];
        });

    // PC-DIDIER-01 : Salle 101 → building_id=5, site_id=1
    (new GlpiSyncService)->sync(
        glpiMock([glpiComputersFixture()[0]]),
        $mercator,
        makeHandler(),
    );

    expect($created[0]['site_id'])->toBe(1);
});

it('laisse building_id absent si la salle ne correspond à aucun building', function () {
    $created = [];

    $mercator = mercatorMock([], mercatorBuildingsFixture());
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload;

            return ['id' => 100];
        });

    // PC-NOUVEAU-01 seul : locations_id = "Salle Inconnue" → pas de building
    (new GlpiSyncService)->sync(
        glpiMock([glpiComputersFixture()[1]]),
        $mercator,
        makeHandler(),
    );

    expect($created[0]['building_id'])->toBeNull();
});

// ── Comportement orphelins ────────────────────────────────────────────────────

it('marque OLD une application orpheline sans tag {GLPI} (créée manuellement)', function () {
    $updated = [];

    $mercator = Mockery::mock(MercatorClientInterface::class);
    $mercator->shouldReceive('getBuildings')->andReturn([]);
    $mercator->shouldReceive('getSites')->andReturn([]);
    // Une application Mercator sans tag glpi_id et absente de GLPI
    $mercator->shouldReceive('getAll')->andReturn([
        ['id' => 20, 'name' => 'App-Orpheline', 'description' => 'Sans tag GLPI'],
    ]);
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });
    $mercator->shouldNotReceive('delete');

    $handler = new ApplicationSyncHandler(
        new ApplicationMapper
    );

    (new GlpiSyncService)->sync(
        glpiMock([]),   // GLPI vide
        $mercator,
        $handler,
    );

    expect($updated[0]['payload']['name'])->toBe('[OLD] App-Orpheline');
});

it('marque OLD un workstation orphelin sans tag {GLPI} (créé manuellement)', function () {
    $updated = [];

    $mercator = Mockery::mock(MercatorClientInterface::class);
    $mercator->shouldReceive('getBuildings')->andReturn([]);
    $mercator->shouldReceive('getSites')->andReturn([]);
    $mercator->shouldReceive('getAll')->andReturn([
        ['id' => 30, 'name' => 'PC-ORPHELIN', 'description' => 'Sans tag GLPI'],
    ]);
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });
    $mercator->shouldNotReceive('delete');

    (new GlpiSyncService)->sync(
        glpiMock([]),   // GLPI vide
        $mercator,
        makeHandler(),
    );

    expect($updated[0]['payload']['name'])->toBe('[OLD] PC-ORPHELIN');
});

it('retourne les statistiques correctes', function () {
    $mercator = mercatorMock(mercatorWorkstationsFixture(), mercatorBuildingsFixture());
    $mercator->shouldReceive('update')->andReturn([]);
    $mercator->shouldReceive('create')->andReturn(['id' => 99]);
    $mercator->shouldReceive('delete')->andReturn(null);

    // GLPI : PC-DIDIER-01 (update) + PC-NOUVEAU-01 (create)
    // Mercator : PC-ANCIEN-GLPI (delete, tagué {GLPI}) + PC-MANUEL-01 (mark OLD, sans tag)
    $stats = (new GlpiSyncService)->sync(
        glpiMock(glpiComputersFixture()),
        $mercator,
        makeHandler(),
    );

    expect($stats['created'])->toBe(1);    // PC-NOUVEAU-01
    expect($stats['updated'])->toBe(1);    // PC-DIDIER-01
    expect($stats['deleted'])->toBe(1);    // PC-ANCIEN-GLPI
    expect($stats['marked_old'])->toBe(1); // PC-MANUEL-01
    expect($stats['errors'])->toBe(0);
});
