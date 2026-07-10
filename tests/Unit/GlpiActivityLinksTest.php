<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Mockery\MockInterface;

// ── Fixtures ──────────────────────────────────────────────────────────────────

function activityLinksGlpiMock(array $appliances): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);

    $mock->shouldReceive('getItems')
        ->with('Appliance', Mockery::type('array'))
        ->andReturn(array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name']], $appliances));

    foreach ($appliances as $appliance) {
        $mock->shouldReceive('getItem')
            ->with('Appliance', $appliance['id'], Mockery::type('array'))
            ->andReturn($appliance);
    }

    return $mock;
}

function activityLinksMercatorMock(array $activities, array $applications): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getAll')->with('activities')->andReturn($activities);
    $mock->shouldReceive('getAll')->with('applications')->andReturn($applications);

    return $mock;
}

// Données de test

function testAppliances(): array
{
    return [
        [
            'id' => 1,
            'name' => 'ERP-PRODUCTION',
            '_items' => [
                'Software' => [
                    ['id' => 10, 'name' => 'LibreOffice'],
                    ['id' => 11, 'name' => 'Firefox'],
                ],
            ],
        ],
        [
            'id' => 2,
            'name' => 'INFRA-RÉSEAU',
            '_items' => [
                'Software' => [
                    ['id' => 12, 'name' => 'Firefox'],
                ],
            ],
        ],
    ];
}

function testActivities(): array
{
    return [
        ['id' => 100, 'name' => 'ERP-PRODUCTION'],
        ['id' => 101, 'name' => 'INFRA-RÉSEAU'],
    ];
}

function testApplications(): array
{
    return [
        ['id' => 20, 'name' => 'LibreOffice'],
        ['id' => 21, 'name' => 'Firefox'],
        ['id' => 22, 'name' => 'Autre'],
    ];
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('associe les activités aux applications via les logiciels liés aux appliances', function () {
    $updated = [];

    $mercator = activityLinksMercatorMock(testActivities(), testApplications());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    (new GlpiSyncService)->syncActivityLinks(
        activityLinksGlpiMock(testAppliances()),
        $mercator,
    );

    // LibreOffice (20) → activité ERP-PRODUCTION (100)
    expect($updated[20]['activities'])->toContain(100);
    expect($updated[20]['activities'])->not->toContain(101);

    // Firefox (21) → activité ERP-PRODUCTION (100) ET INFRA-RÉSEAU (101)
    expect($updated[21]['activities'])->toContain(100);
    expect($updated[21]['activities'])->toContain(101);
});

it('ne met pas à jour une application sans activité associée', function () {
    $updated = [];

    $mercator = activityLinksMercatorMock(testActivities(), testApplications());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    (new GlpiSyncService)->syncActivityLinks(
        activityLinksGlpiMock(testAppliances()),
        $mercator,
    );

    // 'Autre' (22) n'est lié à aucune appliance GLPI
    expect(isset($updated[22]))->toBeFalse();
});

it('déduplique les activités pour un même logiciel', function () {
    $updated = [];

    // Deux appliances utilisent Firefox, toutes deux mappées sur la même activité
    $appliances = [
        ['id' => 1, 'name' => 'ERP-PRODUCTION', '_items' => ['Software' => [['id' => 11, 'name' => 'Firefox']]]],
        ['id' => 2, 'name' => 'ERP-PRODUCTION', '_items' => ['Software' => [['id' => 11, 'name' => 'Firefox']]]],
    ];
    $activities = [['id' => 100, 'name' => 'ERP-PRODUCTION']];

    $mercator = activityLinksMercatorMock($activities, testApplications());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[$id] = $payload;

            return [];
        });

    (new GlpiSyncService)->syncActivityLinks(
        activityLinksGlpiMock($appliances),
        $mercator,
    );

    // L'activité 100 ne doit apparaître qu'une fois
    expect(array_count_values($updated[21]['activities'])[100])->toBe(1);
});

it('compte comme skipped les appliances sans activité Mercator correspondante', function () {
    $appliances = [
        ['id' => 99, 'name' => 'APPLIANCE-INCONNUE', '_items' => ['Software' => [['id' => 5, 'name' => 'Firefox']]]],
    ];

    $mercator = activityLinksMercatorMock(testActivities(), testApplications());
    $mercator->shouldReceive('update')->andReturn([])->zeroOrMoreTimes();

    $stats = (new GlpiSyncService)->syncActivityLinks(
        activityLinksGlpiMock($appliances),
        $mercator,
    );

    expect($stats['skipped'])->toBe(1);
    expect($stats['updated'])->toBe(0);
});

it('ne fait aucune écriture en mode dry-run', function () {
    $mercator = activityLinksMercatorMock(testActivities(), testApplications());
    $mercator->shouldNotReceive('update');

    (new GlpiSyncService)->syncActivityLinks(
        activityLinksGlpiMock(testAppliances()),
        $mercator,
        dryRun: true,
    );
});

it('retourne les statistiques correctes', function () {
    $mercator = activityLinksMercatorMock(testActivities(), testApplications());
    $mercator->shouldReceive('update')->andReturn([]);

    // LibreOffice et Firefox ont des activités → 2 mises à jour
    $stats = (new GlpiSyncService)->syncActivityLinks(
        activityLinksGlpiMock(testAppliances()),
        $mercator,
    );

    expect($stats['updated'])->toBe(2);
    expect($stats['errors'])->toBe(0);
});
