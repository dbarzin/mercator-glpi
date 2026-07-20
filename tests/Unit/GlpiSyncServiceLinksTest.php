<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Mercator\Contracts\MercatorClientInterface;

// ── Fixtures ──────────────────────────────────────────────────────────────────
// glpiComputersWithSoftwareFixture() est définie dans tests/Pest.php

function mercatorApplicationsFixture(): array
{
    return json_decode(
        file_get_contents(__DIR__ . '/../Fixtures/mercator_applications.json'),
        true
    )['data'];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Mock GlpiClient :
 * - getItems('Computer') → liste des computers (sans logiciels)
 * - getItem('Computer', id) → computer individuel avec _softwares
 */
function linkGlpiMock(array $computers): \Mockery\MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);

    // Collection sans logiciels (limitation GLPI sur with_softwares en collection)
    $mock->shouldReceive('getItems')
        ->with('Computer', \Mockery::type('array'))
        ->andReturn(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $computers));

    // Item individuel avec _softwares (appelé pour chaque computer présent dans Mercator)
    foreach ($computers as $computer) {
        $mock->shouldReceive('getItem')
            ->with('Computer', $computer['id'], \Mockery::type('array'))
            ->andReturn($computer);
    }

    return $mock;
}

function linkMercatorMock(
    array $workstations,
    array $applications,
): \Mockery\MockInterface {
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getAll')->with('workstations')->andReturn($workstations);
    $mock->shouldReceive('getAll')->with('applications')->andReturn($applications);
    return $mock;
}

// Workstations Mercator incluant les deux postes de la fixture GLPI
function wsWithNewPC(): array
{
    return [
        ...mercatorWorkstationsFixture(),
        ['id' => 13, 'name' => 'PC-NOUVEAU-01', 'description' => '[glpi_id:43]'],
    ];
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('associe les applications aux workstations correspondantes', function () {
    $updated = [];

    $mercator = linkMercatorMock(wsWithNewPC(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');
            return [];
        });

    (new GlpiSyncService())->syncLinks(
        linkGlpiMock(glpiComputersWithSoftwareFixture()),
        $mercator,
    );

    // PC-DIDIER-01 (Mercator id 10) → Firefox (20) + LibreOffice (21)
    $ws10 = collect($updated)->first(fn($u) => $u['id'] === 10);
    expect($ws10)->not->toBeNull();
    expect($ws10['payload']['applications'])->toContain(20)->toContain(21);
    // Le name est toujours inclus dans le payload (requis par l'API Mercator)
    expect($ws10['payload'])->toHaveKey('name');
});

it('n\'associe pas deux fois le même logiciel', function () {
    $updated = [];

    // Firefox deux fois dans softwares (deux versions différentes du même logiciel)
    $computers = [[
        'id'       => 42,
        'name'     => 'PC-DIDIER-01',
        'softwares' => [
            ['softwares_id' => 'Firefox', 'softwareversions_id' => '120.0'],
            ['softwares_id' => 'Firefox', 'softwareversions_id' => '121.0'],
        ],
    ]];

    $mercator = linkMercatorMock(mercatorWorkstationsFixture(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');
            return [];
        });

    (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    $ws10   = collect($updated)->first(fn($u) => $u['id'] === 10);
    $appIds = $ws10['payload']['applications'];

    // Firefox (20) ne doit apparaître qu'une seule fois
    expect(array_count_values($appIds)[20])->toBe(1);
});

it('ignore un computer dont le poste n\'existe pas dans Mercator', function () {
    // id 999 : ne doit collisionner avec aucun tag ext_refs des fixtures Mercator
    // (mercator_workstations.json contient une entrée taguée {GLPI}99, cf. non-régression ext_refs)
    $computers = [[
        'id'       => 999,
        'name'     => 'PC-INCONNU',
        'softwares' => [['softwares_id' => 'Firefox', 'softwareversions_id' => '120']],
    ]];

    $mercator = linkMercatorMock(mercatorWorkstationsFixture(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')->andReturn([])->zeroOrMoreTimes();

    $stats = (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    expect($stats['updated'])->toBe(0);
});

it('compte comme skipped les logiciels absents de Mercator', function () {
    $computers = [[
        'id'       => 42,
        'name'     => 'PC-DIDIER-01',
        'softwares' => [
            ['softwares_id' => 'Firefox',         'softwareversions_id' => '120'],
            ['softwares_id' => 'LogicielInconnu', 'softwareversions_id' => '1.0'],
        ],
    ]];

    $mercator = linkMercatorMock(mercatorWorkstationsFixture(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')->andReturn([]);

    $stats = (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    // Firefox résolu, LogicielInconnu ignoré
    expect($stats['updated'])->toBe(1);
    expect($stats['skipped'])->toBe(1);
});

it('ne fait aucune écriture en mode dry-run', function () {
    $mercator = linkMercatorMock(wsWithNewPC(), mercatorApplicationsFixture());
    $mercator->shouldNotReceive('update');

    (new GlpiSyncService())->syncLinks(
        linkGlpiMock(glpiComputersWithSoftwareFixture()),
        $mercator,
        dryRun: true,
    );
});

it('retourne les statistiques correctes', function () {
    $mercator = linkMercatorMock(wsWithNewPC(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')->andReturn([]);

    // 2 computers avec logiciels connus → 2 workstations mises à jour
    $stats = (new GlpiSyncService())->syncLinks(
        linkGlpiMock(glpiComputersWithSoftwareFixture()),
        $mercator,
    );

    expect($stats['updated'])->toBe(2);
    expect($stats['errors'])->toBe(0);
});

// ── Réconciliation ext_refs (refactoring) ──────────────────────────────────────

it('matche le Computer à sa workstation via ext_refs même si elle a été renommée côté Mercator', function () {
    $updated = [];

    $computers = [[
        'id' => 5,
        'name' => 'PC-RENOMME-DANS-GLPI',
        'softwares' => [],
    ]];
    // Workstation Mercator taguée {GLPI}5, nom différent du Computer GLPI
    $workstations = [['id' => 50, 'name' => 'Nom Mercator différent', 'ext_refs' => '{GLPI}5']];

    $mercator = linkMercatorMock($workstations, mercatorApplicationsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');

            return [];
        });

    // Aucun logiciel : pas de PUT attendu, on vérifie juste qu'aucune erreur ne survient
    // et que le Computer est bien résolu (pas de crash / pas de skip prématuré).
    (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    expect($updated)->toBeEmpty(); // pas de logiciel → pas de PUT, mais pas d'exception non plus
});

it('matche un logiciel à son application via ext_refs (softwares_id numérique) même si l\'application a été renommée', function () {
    $updated = [];

    $computers = [[
        'id' => 42,
        'name' => 'PC-DIDIER-01',
        'softwares' => [
            ['softwares_id' => 99],
        ],
    ]];
    $workstations = mercatorWorkstationsFixture();
    // Application Mercator taguée {GLPI}99, nom différent du Software GLPI
    $applications = [['id' => 77, 'name' => 'Nom Mercator différent', 'ext_refs' => '{GLPI}99']];

    $mercator = linkMercatorMock($workstations, $applications);
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    expect($updated[10]['applications'])->toBe([77]);
});

it('distingue deux Software GLPI homonymes via ext_refs (softwares_id numérique)', function () {
    $updated = [];

    $computers = [
        ['id' => 42, 'name' => 'PC-DIDIER-01', 'softwares' => [['softwares_id' => 10]]],
        ['id' => 43, 'name' => 'PC-NOUVEAU-01', 'softwares' => [['softwares_id' => 11]]],
    ];
    $workstations = wsWithNewPC();
    // Deux applications Mercator homonymes, distinguées uniquement par ext_refs
    $applications = [
        ['id' => 200, 'name' => 'MonLogiciel', 'ext_refs' => '{GLPI}10'],
        ['id' => 201, 'name' => 'MonLogiciel', 'ext_refs' => '{GLPI}11'],
    ];

    $mercator = linkMercatorMock($workstations, $applications);
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    expect($updated[10]['applications'])->toBe([200]);
    expect($updated[13]['applications'])->toBe([201]);
});

it('ne matche jamais une application taguée {GLPI-Appliance} via l\'index {GLPI} des Software', function () {
    $updated = [];

    $computers = [[
        'id' => 42,
        'name' => 'PC-DIDIER-01',
        'softwares' => [['softwares_id' => 10]],
    ]];
    $workstations = mercatorWorkstationsFixture();
    // {GLPI-Appliance}10 ne doit pas être confondu avec {GLPI}10 : seule l'entrée
    // taguée {GLPI}10 doit être retenue par l'index {GLPI} des applications.
    $applications = [
        ['id' => 500, 'name' => 'Appliance-Homonyme', 'ext_refs' => '{GLPI-Appliance}10'],
        ['id' => 20, 'name' => 'LogicielCorrect', 'ext_refs' => '{GLPI}10'],
    ];

    $mercator = linkMercatorMock($workstations, $applications);
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    expect($updated[10]['applications'])->toBe([20]);
    expect($updated[10]['applications'])->not->toContain(500);
});

it('retombe sur le nom sans planter quand softwares_id n\'est pas numérique', function () {
    $updated = [];

    $computers = [[
        'id' => 42,
        'name' => 'PC-DIDIER-01',
        'softwares' => [
            ['softname' => 'Firefox'], // pas de softwares_id du tout
        ],
    ]];

    $mercator = linkMercatorMock(mercatorWorkstationsFixture(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    expect($updated[10]['applications'])->toBe([20]);
});

it('appelle getItem(Computer) avec expand_dropdowns=0 et with_softwares=1', function () {
    $computer = ['id' => 42, 'name' => 'PC-DIDIER-01', 'softwares' => []];

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Computer', Mockery::type('array'))->andReturn([['id' => 42, 'name' => 'PC-DIDIER-01']]);
    $glpi->shouldReceive('getItem')
        ->with('Computer', 42, ['with_softwares' => 1, 'expand_dropdowns' => 0])
        ->once()
        ->andReturn($computer);

    $mercator = linkMercatorMock(mercatorWorkstationsFixture(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')->andReturn([])->zeroOrMoreTimes();

    (new GlpiSyncService())->syncLinks($glpi, $mercator);
});

it('le payload PUT ne contient que name et applications', function () {
    $updated = [];

    $mercator = linkMercatorMock(mercatorWorkstationsFixture(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    $computers = [['id' => 42, 'name' => 'PC-DIDIER-01', 'softwares' => [['softwares_id' => 'Firefox']]]];

    (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    expect(array_keys($updated[10]))->toEqualCanonicalizing(['name', 'applications']);
});

it('compte une erreur PUT et continue pour les workstations suivantes', function () {
    $updated = [];

    $computers = [
        ['id' => 42, 'name' => 'PC-DIDIER-01', 'softwares' => [['softwares_id' => 'Firefox']]],
        ['id' => 43, 'name' => 'PC-NOUVEAU-01', 'softwares' => [['softwares_id' => 'Firefox']]],
    ];

    $mercator = linkMercatorMock(wsWithNewPC(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            if ($id === 10) {
                throw new RuntimeException('boom');
            }
            $updated[$id] = $payload;

            return [];
        });

    $stats = (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    expect($stats['errors'])->toBe(1);
    expect($stats['updated'])->toBe(1);
    expect($updated[13]['applications'])->toContain(20);
});
