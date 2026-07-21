<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

// ── Fixtures ──────────────────────────────────────────────────────────────────
// GLPI n'expose pas _items sur Appliance (aucun paramètre "with_items" reconnu par
// l'API, vérifié contre une instance réelle — cf. GlpiSyncService::fetchApplianceLinkedItems()).
// Les Computer liés ne sont accessibles que via le pivot Appliance_Item
// (/Appliance/{id}/Appliance_Item), qui ne porte que items_id (numérique) et
// itemtype — jamais le nom du Computer. Le mock reflète ce format ; chaque
// appliance de fixture porte 'computers' => [['items_id' => int, 'name' => ?string], ...],
// 'name' n'étant utilisé que pour simuler la réponse du getItem('Computer', id) de
// repli (déclenché seulement si le matching par ext_refs échoue).

function applianceLinksGlpiMock(array $appliances): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);

    $mock->shouldReceive('getItems')
        ->with('Appliance', Mockery::type('array'))
        ->andReturn(array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name']], $appliances));

    foreach ($appliances as $appliance) {
        $computerLinks = $appliance['computers'] ?? [];

        $pivotRows = [];
        foreach ($computerLinks as $i => $computer) {
            $pivotRows[] = [
                'id' => ($appliance['id'] * 1000) + $i,
                'appliances_id' => $appliance['id'],
                'items_id' => $computer['items_id'] ?? null,
                'itemtype' => 'Computer',
            ];
        }

        $mock->shouldReceive('getSubItems')
            ->with('Appliance', $appliance['id'], 'Appliance_Item', Mockery::type('array'))
            ->andReturn($pivotRows);

        foreach ($computerLinks as $computer) {
            if (isset($computer['items_id'], $computer['name'])) {
                $mock->shouldReceive('getItem')
                    ->with('Computer', $computer['items_id'])
                    ->zeroOrMoreTimes()
                    ->andReturn(['id' => $computer['items_id'], 'name' => $computer['name']]);
            }
        }
    }

    return $mock;
}

function applianceLinksMercatorMock(array $applications, array $logicalServers): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getAll')->with('applications')->andReturn($applications);
    $mock->shouldReceive('getAll')->with('logical-servers')->andReturn($logicalServers);

    return $mock;
}

function testApplianceApplications(): array
{
    return [
        ['id' => 200, 'name' => 'ERP', 'ext_refs' => '{GLPI-Appliance}42'],
    ];
}

function testApplianceLogicalServers(): array
{
    return [
        ['id' => 300, 'name' => 'VM-ERP-01', 'ext_refs' => '{GLPI}10'],
        ['id' => 301, 'name' => 'VM-ERP-02', 'ext_refs' => '{GLPI}11'],
    ];
}

function testAppliance(array $computerItems, int $id = 42, string $name = 'ERP'): array
{
    return [
        'id' => $id,
        'name' => $name,
        'computers' => $computerItems,
    ];
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('lie une application à ses serveurs logiques via les Computer de l\'appliance', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $updated = [];

    $mercator = applianceLinksMercatorMock(testApplianceApplications(), testApplianceLogicalServers());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    $appliance = testAppliance([
        ['items_id' => 10, 'name' => 'VM-ERP-01'],
        ['items_id' => 11, 'name' => 'VM-ERP-02'],
    ]);

    $stats = (new GlpiSyncService)->syncApplianceLinks(
        applianceLinksGlpiMock([$appliance]),
        $mercator,
    );

    expect($updated[200]['logical_servers'])->toContain(300)->toContain(301);
    expect($updated[200])->toHaveKey('name');
    expect($updated[200])->not->toHaveKey('activities');
    expect($stats['updated'])->toBe(1);
});

it('lie via ext_refs sans appel getItem complémentaire quand items_id matche directement', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $updated = [];

    $appliance = testAppliance([
        ['items_id' => 10, 'name' => null],
        ['items_id' => 11, 'name' => null],
    ]);

    $glpi = applianceLinksGlpiMock([$appliance]);
    $glpi->shouldNotReceive('getItem'); // ext_refs {GLPI}10/{GLPI}11 doit suffire

    $mercator = applianceLinksMercatorMock(testApplianceApplications(), testApplianceLogicalServers());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    (new GlpiSyncService)->syncApplianceLinks($glpi, $mercator);

    expect($updated[200]['logical_servers'])->toContain(300)->toContain(301);
});

it('ne fait rien en mode activities (warning, stats vides, pas d\'appel GLPI/Mercator)', function () {
    // Config par défaut = activities
    Log::spy();

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldNotReceive('getItems');
    $glpi->shouldNotReceive('getItem');
    $glpi->shouldNotReceive('getSubItems');

    $mercator = Mockery::mock(MercatorClientInterface::class);
    $mercator->shouldNotReceive('getAll');
    $mercator->shouldNotReceive('update');

    $stats = (new GlpiSyncService)->syncApplianceLinks($glpi, $mercator);

    expect($stats)->toBe(['updated' => 0, 'skipped' => 0, 'errors' => 0]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'appliance_links') && str_contains($message, 'GLPI_APPLIANCE_MERCATOR_ENDPOINT'))
        ->atLeast()->once();
});

it('compte comme skipped une appliance sans application Mercator correspondante', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $mercator = applianceLinksMercatorMock([], testApplianceLogicalServers());
    $mercator->shouldReceive('update')->andReturn([])->zeroOrMoreTimes();

    $appliance = testAppliance([['items_id' => 10, 'name' => 'VM-ERP-01']]);

    $stats = (new GlpiSyncService)->syncApplianceLinks(
        applianceLinksGlpiMock([$appliance]),
        $mercator,
    );

    expect($stats['skipped'])->toBe(1);
    expect($stats['updated'])->toBe(0);
});

it('ignore un Computer sans serveur logique Mercator correspondant mais lie les autres', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $updated = [];

    $mercator = applianceLinksMercatorMock(testApplianceApplications(), testApplianceLogicalServers());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    $appliance = testAppliance([
        ['items_id' => 10, 'name' => 'VM-ERP-01'],
        ['items_id' => 999, 'name' => 'PC-NON-SERVEUR'], // pas un serveur logique Mercator
    ]);

    $stats = (new GlpiSyncService)->syncApplianceLinks(
        applianceLinksGlpiMock([$appliance]),
        $mercator,
    );

    expect($updated[200]['logical_servers'])->toBe([300]);
    expect($stats['updated'])->toBe(1);
});

it('résout par nom (via getItem complémentaire) quand le serveur logique Mercator n\'a pas d\'ext_refs exploitable', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $updated = [];

    $logicalServers = [
        ['id' => 300, 'name' => 'vm-erp-01', 'ext_refs' => null],
    ];

    $mercator = applianceLinksMercatorMock(testApplianceApplications(), $logicalServers);
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    $appliance = testAppliance([
        ['items_id' => 10, 'name' => 'VM-ERP-01'], // casse différente, id ne matche rien (pas d'ext_refs)
    ]);

    (new GlpiSyncService)->syncApplianceLinks(
        applianceLinksGlpiMock([$appliance]),
        $mercator,
    );

    expect($updated[200]['logical_servers'])->toBe([300]);
});

it('déduplique un même Computer référencé deux fois dans le pivot Appliance_Item', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $updated = [];

    $mercator = applianceLinksMercatorMock(testApplianceApplications(), testApplianceLogicalServers());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    $appliance = testAppliance([
        ['items_id' => 10, 'name' => null],
        ['items_id' => 10, 'name' => null],
    ]);

    (new GlpiSyncService)->syncApplianceLinks(
        applianceLinksGlpiMock([$appliance]),
        $mercator,
    );

    expect($updated[200]['logical_servers'])->toBe([300]);
});

it('ne fait aucune écriture en mode dry-run', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $mercator = applianceLinksMercatorMock(testApplianceApplications(), testApplianceLogicalServers());
    $mercator->shouldNotReceive('update');

    $appliance = testAppliance([['items_id' => 10, 'name' => 'VM-ERP-01']]);

    $stats = (new GlpiSyncService)->syncApplianceLinks(
        applianceLinksGlpiMock([$appliance]),
        $mercator,
        dryRun: true,
    );

    expect($stats['updated'])->toBe(1);
});

it('compte une erreur PUT et continue pour les appliances suivantes', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $applications = [
        ['id' => 200, 'name' => 'ERP', 'ext_refs' => '{GLPI-Appliance}42'],
        ['id' => 201, 'name' => 'CRM', 'ext_refs' => '{GLPI-Appliance}43'],
    ];

    $updated = [];

    $mercator = applianceLinksMercatorMock($applications, testApplianceLogicalServers());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            if ($id === 200) {
                throw new RuntimeException('boom');
            }
            $updated[$id] = $payload;

            return [];
        });

    $appliances = [
        testAppliance([['items_id' => 10, 'name' => null]], id: 42, name: 'ERP'),
        testAppliance([['items_id' => 11, 'name' => null]], id: 43, name: 'CRM'),
    ];

    $stats = (new GlpiSyncService)->syncApplianceLinks(
        applianceLinksGlpiMock($appliances),
        $mercator,
    );

    expect($stats['errors'])->toBe(1);
    expect($stats['updated'])->toBe(1);
    expect($updated[201]['logical_servers'])->toBe([301]);
});

it('le payload PUT ne contient que name et logical_servers', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $updated = [];

    $mercator = applianceLinksMercatorMock(testApplianceApplications(), testApplianceLogicalServers());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    $appliance = testAppliance([['items_id' => 10, 'name' => null]]);

    (new GlpiSyncService)->syncApplianceLinks(
        applianceLinksGlpiMock([$appliance]),
        $mercator,
    );

    expect(array_keys($updated[200]))->toEqualCanonicalizing(['name', 'logical_servers']);
});

it('ignore une ligne pivot Computer sans items_id numérique, sans planter', function () {
    config(['glpi.appliance_mercator_endpoint' => 'applications']);

    $appliance = testAppliance([['items_id' => null, 'name' => null]]);

    $mercator = applianceLinksMercatorMock(testApplianceApplications(), testApplianceLogicalServers());
    $mercator->shouldReceive('update')->andReturn([])->zeroOrMoreTimes();

    $stats = (new GlpiSyncService)->syncApplianceLinks(
        applianceLinksGlpiMock([$appliance]),
        $mercator,
    );

    expect($stats['errors'])->toBe(0);
});
