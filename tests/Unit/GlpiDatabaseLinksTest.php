<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Mockery\MockInterface;

// ── Fixtures ──────────────────────────────────────────────────────────────────
// Une Database GLPI ne porte ni son type ni son hôte directement : les deux vivent
// sur la DatabaseInstance parente (databaseinstances_id → itemtype/items_id).
// itemtype/items_id ne sont PAS renvoyés par la collection DatabaseInstance
// (getItems) — seuls les champs d'affichage par défaut de cet itemtype le sont —
// syncDatabaseLinks() les résout donc via getItem() (item par item, enregistrement
// brut complet). Le mock reflète ce comportement : getItems('DatabaseInstance', …)
// n'est jamais attendu, seul getItem('DatabaseInstance', $id, …) l'est.

function databaseLinksGlpiMock(array $databases, array $instances, array $computerNames = []): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);

    $mock->shouldReceive('getItems')
        ->with('Database', Mockery::type('array'))
        ->andReturn($databases);

    $mock->shouldNotReceive('getItems')->with('DatabaseInstance', Mockery::any());

    foreach ($instances as $instance) {
        $mock->shouldReceive('getItem')
            ->with('DatabaseInstance', $instance['id'], Mockery::type('array'))
            ->zeroOrMoreTimes()
            ->andReturn($instance);
    }

    foreach ($computerNames as $id => $name) {
        $mock->shouldReceive('getItem')
            ->with('Computer', $id)
            ->zeroOrMoreTimes()
            ->andReturn(['id' => $id, 'name' => $name]);
    }

    $mock->shouldReceive('getItem')->zeroOrMoreTimes()->andReturn([]);

    return $mock;
}

function databaseLinksMercatorMock(array $databases, array $logicalServers): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getAll')->with('databases')->andReturn($databases);
    $mock->shouldReceive('getAll')->with('logical-servers')->andReturn($logicalServers);

    return $mock;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('lie une database à son serveur logique hôte (happy path)', function () {
    $updated = [];
    $mercator = databaseLinksMercatorMock(
        [['id' => 500, 'name' => 'prod-db', 'ext_refs' => '{GLPI}1']],
        [['id' => 300, 'name' => 'srv-01', 'ext_refs' => '{GLPI}55']],
    );
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    $glpi = databaseLinksGlpiMock(
        databases: [['id' => 1, 'name' => 'prod-db', 'databaseinstances_id' => 10]],
        instances: [['id' => 10, 'itemtype' => 'Computer', 'items_id' => 55]],
    );

    $stats = (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator);

    expect($updated[500])->toBe(['name' => 'prod-db', 'logical_servers' => [300]]);
    expect($stats['updated'])->toBe(1);
    expect($stats['skipped'])->toBe(0);
    expect($stats['errors'])->toBe(0);
});

it('résout la database via ext_refs même si les noms diffèrent', function () {
    $updated = [];
    $mercator = databaseLinksMercatorMock(
        [['id' => 500, 'name' => 'nom-different-mercator', 'ext_refs' => '{GLPI}1']],
        [['id' => 300, 'name' => 'srv-01', 'ext_refs' => '{GLPI}55']],
    );
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    $glpi = databaseLinksGlpiMock(
        databases: [['id' => 1, 'name' => 'prod-db', 'databaseinstances_id' => 10]],
        instances: [['id' => 10, 'itemtype' => 'Computer', 'items_id' => 55]],
    );

    (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator);

    expect($updated[500]['logical_servers'])->toBe([300]);
});

it('résout la database par nom (repli) quand ext_refs est absent', function () {
    $updated = [];
    $mercator = databaseLinksMercatorMock(
        [['id' => 500, 'name' => 'PROD-DB', 'ext_refs' => null]],
        [['id' => 300, 'name' => 'srv-01', 'ext_refs' => '{GLPI}55']],
    );
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    $glpi = databaseLinksGlpiMock(
        databases: [['id' => 1, 'name' => 'prod-db', 'databaseinstances_id' => 10]],
        instances: [['id' => 10, 'itemtype' => 'Computer', 'items_id' => 55]],
    );

    (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator);

    expect($updated[500]['logical_servers'])->toBe([300]);
});

it('ignore (skipped) une database sans instance résolue (databaseinstances_id=0)', function () {
    $mercator = databaseLinksMercatorMock(
        [['id' => 500, 'name' => 'prod-db', 'ext_refs' => '{GLPI}1']],
        [['id' => 300, 'name' => 'srv-01', 'ext_refs' => '{GLPI}55']],
    );
    $mercator->shouldNotReceive('update');

    $glpi = databaseLinksGlpiMock(
        databases: [['id' => 1, 'name' => 'prod-db', 'databaseinstances_id' => 0]],
        instances: [['id' => 10, 'itemtype' => 'Computer', 'items_id' => 55]],
    );

    $stats = (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator);

    expect($stats['skipped'])->toBe(1);
    expect($stats['updated'])->toBe(0);
});

it('ignore (skipped) une instance dont l\'hôte n\'est pas un Computer', function () {
    $mercator = databaseLinksMercatorMock(
        [['id' => 500, 'name' => 'prod-db', 'ext_refs' => '{GLPI}1']],
        [['id' => 300, 'name' => 'srv-01', 'ext_refs' => '{GLPI}55']],
    );
    $mercator->shouldNotReceive('update');

    $glpi = databaseLinksGlpiMock(
        databases: [['id' => 1, 'name' => 'prod-db', 'databaseinstances_id' => 10]],
        instances: [['id' => 10, 'itemtype' => 'Software', 'items_id' => 55]],
    );

    $stats = (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator);

    expect($stats['skipped'])->toBe(1);
    expect($stats['updated'])->toBe(0);
});

it('laisse la database intacte quand l\'hôte Computer n\'a pas de serveur logique Mercator correspondant', function () {
    $mercator = databaseLinksMercatorMock(
        [['id' => 500, 'name' => 'prod-db', 'ext_refs' => '{GLPI}1']],
        [], // pas de serveur logique Mercator (ex. Computer synchronisé comme physical-server)
    );
    $mercator->shouldNotReceive('update');

    $glpi = databaseLinksGlpiMock(
        databases: [['id' => 1, 'name' => 'prod-db', 'databaseinstances_id' => 10]],
        instances: [['id' => 10, 'itemtype' => 'Computer', 'items_id' => 55]],
        computerNames: [55 => 'HOST-55'],
    );

    $stats = (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator);

    expect($stats['skipped'])->toBe(1);
    expect($stats['updated'])->toBe(0);
});

it('ne matche jamais une database Mercator non taguée dont le nom ne correspond à rien', function () {
    $mercator = databaseLinksMercatorMock(
        [['id' => 500, 'name' => 'un-autre-nom', 'ext_refs' => null]],
        [['id' => 300, 'name' => 'srv-01', 'ext_refs' => '{GLPI}55']],
    );
    $mercator->shouldNotReceive('update');

    $glpi = databaseLinksGlpiMock(
        databases: [['id' => 1, 'name' => 'prod-db', 'databaseinstances_id' => 10]],
        instances: [['id' => 10, 'itemtype' => 'Computer', 'items_id' => 55]],
    );

    $stats = (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator);

    expect($stats['skipped'])->toBe(1);
    expect($stats['updated'])->toBe(0);
});

it('ne fait aucune écriture en mode dry-run mais reflète les stats', function () {
    $mercator = databaseLinksMercatorMock(
        [['id' => 500, 'name' => 'prod-db', 'ext_refs' => '{GLPI}1']],
        [['id' => 300, 'name' => 'srv-01', 'ext_refs' => '{GLPI}55']],
    );
    $mercator->shouldNotReceive('update');

    $glpi = databaseLinksGlpiMock(
        databases: [['id' => 1, 'name' => 'prod-db', 'databaseinstances_id' => 10]],
        instances: [['id' => 10, 'itemtype' => 'Computer', 'items_id' => 55]],
    );

    $stats = (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator, dryRun: true);

    expect($stats['updated'])->toBe(1);
});

it('résout l\'hôte via getItem (pas getItems) sur DatabaseInstance, dédupliqué par instance', function () {
    $mercator = databaseLinksMercatorMock(
        [
            ['id' => 500, 'name' => 'db-a', 'ext_refs' => '{GLPI}1'],
            ['id' => 501, 'name' => 'db-b', 'ext_refs' => '{GLPI}2'],
        ],
        [['id' => 300, 'name' => 'srv-01', 'ext_refs' => '{GLPI}55']],
    );
    $mercator->shouldReceive('update')->andReturn([]);

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')
        ->with('Database', Mockery::type('array'))
        ->andReturn([
            ['id' => 1, 'name' => 'db-a', 'databaseinstances_id' => 10],
            ['id' => 2, 'name' => 'db-b', 'databaseinstances_id' => 10],
        ]);
    $glpi->shouldNotReceive('getItems')->with('DatabaseInstance', Mockery::any());
    $glpi->shouldReceive('getItem')
        ->with('DatabaseInstance', 10, Mockery::type('array'))
        ->once()
        ->andReturn(['id' => 10, 'itemtype' => 'Computer', 'items_id' => 55]);

    $stats = (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator);

    expect($stats['updated'])->toBe(2);
});

it('une DatabaseInstance en échec (getItem qui lève) ne fait pas planter tout le run, seule la base concernée est ignorée', function () {
    $updated = [];
    $mercator = databaseLinksMercatorMock(
        [
            ['id' => 500, 'name' => 'db-a', 'ext_refs' => '{GLPI}1'],
            ['id' => 501, 'name' => 'db-b', 'ext_refs' => '{GLPI}2'],
        ],
        [['id' => 300, 'name' => 'srv-01', 'ext_refs' => '{GLPI}56']],
    );
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')
        ->with('Database', Mockery::type('array'))
        ->andReturn([
            ['id' => 1, 'name' => 'db-a', 'databaseinstances_id' => 10],
            ['id' => 2, 'name' => 'db-b', 'databaseinstances_id' => 11],
        ]);
    $glpi->shouldReceive('getItem')
        ->with('DatabaseInstance', 10, Mockery::type('array'))
        ->andThrow(new RuntimeException('404 introuvable'));
    $glpi->shouldReceive('getItem')
        ->with('DatabaseInstance', 11, Mockery::type('array'))
        ->andReturn(['id' => 11, 'itemtype' => 'Computer', 'items_id' => 56]);

    $stats = (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator);

    expect($stats['errors'])->toBe(0);
    expect($stats['skipped'])->toBe(1);
    expect($stats['updated'])->toBe(1);
    expect($updated[501]['logical_servers'])->toBe([300]);
});

it('compte une erreur PUT et continue pour les databases suivantes', function () {
    $updated = [];
    $mercator = databaseLinksMercatorMock(
        [
            ['id' => 500, 'name' => 'db-a', 'ext_refs' => '{GLPI}1'],
            ['id' => 501, 'name' => 'db-b', 'ext_refs' => '{GLPI}2'],
        ],
        [
            ['id' => 300, 'name' => 'srv-a', 'ext_refs' => '{GLPI}55'],
            ['id' => 301, 'name' => 'srv-b', 'ext_refs' => '{GLPI}56'],
        ],
    );
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        if ($id === 500) {
            throw new RuntimeException('boom');
        }
        $updated[$id] = $payload;

        return [];
    });

    $glpi = databaseLinksGlpiMock(
        databases: [
            ['id' => 1, 'name' => 'db-a', 'databaseinstances_id' => 10],
            ['id' => 2, 'name' => 'db-b', 'databaseinstances_id' => 11],
        ],
        instances: [
            ['id' => 10, 'itemtype' => 'Computer', 'items_id' => 55],
            ['id' => 11, 'itemtype' => 'Computer', 'items_id' => 56],
        ],
    );

    $stats = (new GlpiSyncService)->syncDatabaseLinks($glpi, $mercator);

    expect($stats['errors'])->toBe(1);
    expect($stats['updated'])->toBe(1);
    expect($updated[501]['logical_servers'])->toBe([301]);
});
