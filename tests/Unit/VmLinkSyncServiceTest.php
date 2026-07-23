<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\VmLinkSyncService;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

// ── Fixtures ──────────────────────────────────────────────────────────────────
// Les entrées VM GLPI sont récupérées en un seul appel sur la collection complète
// ("ItemVirtualMachine" en GLPI 11, "ComputerVirtualMachine" en GLPI 10), pas via
// la route sous-item "/Computer/{id}/…" (cf. VmLinkSyncService::fetchAllVmEntries,
// issue #15 : cette route sous-item renvoie une erreur 500 sur certaines instances
// GLPI). ItemVirtualMachine porte les champs polymorphes 'itemtype'/'items_id' ;
// ComputerVirtualMachine porte 'computers_id' directement. Les Computer "hôte" et
// "serveur logique" sont routés via computertypes_id, comme
// GLPI_COMPUTER_TYPES_PHYSICAL_SERVERS / GLPI_COMPUTER_TYPES_LOGICAL_SERVERS.

beforeEach(function () {
    config([
        'glpi.computer_types.physical_servers' => ['Serveur physique'],
        'glpi.computer_types.logical_servers' => ['Machine virtuelle'],
    ]);
});

function vmHost(int $id, string $name = 'HOST'): array
{
    return ['id' => $id, 'name' => $name, 'computertypes_id' => 'Serveur physique'];
}

function vmLogicalComputer(int $id, string $name, ?string $uuid = null): array
{
    return ['id' => $id, 'name' => $name, 'computertypes_id' => 'Machine virtuelle', 'uuid' => $uuid];
}

/**
 * Entrée VM au format "ItemVirtualMachine" (GLPI 11, champs polymorphes).
 */
function vmEntry(int $hostId, string $name, ?string $uuid = null, bool $deleted = false): array
{
    return [
        'itemtype' => 'Computer',
        'items_id' => $hostId,
        'name' => $name,
        'uuid' => $uuid,
        'is_deleted' => $deleted ? 1 : 0,
    ];
}

/**
 * Entrée VM au format "ComputerVirtualMachine" (GLPI 10, champ direct computers_id).
 */
function vmEntryLegacy(int $hostId, string $name, ?string $uuid = null, bool $deleted = false): array
{
    return [
        'computers_id' => $hostId,
        'name' => $name,
        'uuid' => $uuid,
        'is_deleted' => $deleted ? 1 : 0,
    ];
}

function vmMercatorLs(int $mercId, string $name, int $glpiId): array
{
    return ['id' => $mercId, 'name' => $name, 'ext_refs' => "{GLPI}{$glpiId}"];
}

function vmMercatorPs(int $mercId, string $name, int $glpiId): array
{
    return ['id' => $mercId, 'name' => $name, 'ext_refs' => "{GLPI}{$glpiId}"];
}

/**
 * @param  array<int, array>  $vmEntries  entrées "ItemVirtualMachine" (cf. vmEntry())
 */
function vmLinksGlpiMock(array $allComputers, array $vmEntries): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);

    $mock->shouldReceive('getItems')
        ->with('Computer', Mockery::type('array'))
        ->andReturn($allComputers);

    $mock->shouldReceive('getItems')
        ->with('ItemVirtualMachine', Mockery::type('array'))
        ->andReturn($vmEntries);

    // fetchAllVmEntries() s'exécute sous withoutEntityRestriction() (cf. issue #15,
    // bug GLPI 10.0.20 "Unknown column ... is_recursive") : no-op ici, on invoque
    // simplement le callback.
    $mock->shouldReceive('withoutEntityRestriction')
        ->andReturnUsing(fn (callable $callback) => $callback());

    return $mock;
}

function vmLinksMercatorMock(array $logicalServers, array $physicalServers): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getAll')->with('logical-servers')->andReturn($logicalServers);
    $mock->shouldReceive('getAll')->with('physical-servers')->andReturn($physicalServers);

    return $mock;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('détecte le repli GLPI 10 (ComputerVirtualMachine) après échec de ItemVirtualMachine, en un seul essai (pas par hôte)', function () {
    $host = vmHost(1, 'HOST-1');
    $ls = vmLogicalComputer(10, 'VM-1', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Computer', Mockery::type('array'))->andReturn([$host, $ls]);
    $glpi->shouldReceive('withoutEntityRestriction')->andReturnUsing(fn (callable $callback) => $callback());

    $glpi->shouldReceive('getItems')
        ->once()
        ->with('ItemVirtualMachine', Mockery::type('array'))
        ->andThrow(new RuntimeException('Erreur lors de la récupération de ItemVirtualMachine : 400'));

    $glpi->shouldReceive('getItems')
        ->once()
        ->with('ComputerVirtualMachine', Mockery::type('array'))
        ->andReturn([vmEntryLegacy(1, 'VM-1', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')]);

    $mercator = vmLinksMercatorMock(
        [vmMercatorLs(300, 'VM-1', 10)],
        [vmMercatorPs(400, 'HOST-1', 1)]
    );
    $mercator->shouldReceive('update')->andReturn([])->zeroOrMoreTimes();

    $stats = (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($stats['updated'])->toBe(1);
    expect($stats['errors'])->toBe(0);
});

it('ne fait qu\'un seul appel pour récupérer les VM, quel que soit le nombre d\'hôtes (pas de requête par hôte, issue #15)', function () {
    $host1 = vmHost(1, 'HOST-1');
    $host2 = vmHost(2, 'HOST-2');
    $ls = vmLogicalComputer(10, 'VM-1', 'uuid-c-1111-2222-3333-444444444444');

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Computer', Mockery::type('array'))->andReturn([$host1, $host2, $ls]);
    $glpi->shouldReceive('withoutEntityRestriction')->andReturnUsing(fn (callable $callback) => $callback());

    // ->once() : si le service faisait encore une requête par hôte, cette
    // expectation échouerait (2 appels constatés au lieu d'1).
    $glpi->shouldReceive('getItems')
        ->once()
        ->with('ItemVirtualMachine', Mockery::type('array'))
        ->andReturn([vmEntry(2, 'VM-1', 'uuid-c-1111-2222-3333-444444444444')]);

    $mercator = vmLinksMercatorMock([vmMercatorLs(300, 'VM-1', 10)], [vmMercatorPs(401, 'HOST-2', 2)]);

    $updated = [];
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($updated[300]['physical_servers'])->toBe([401]);
});

it('propage une exception si ItemVirtualMachine et ComputerVirtualMachine échouent tous deux', function () {
    $host = vmHost(1, 'HOST-1');

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Computer', Mockery::type('array'))->andReturn([$host]);
    $glpi->shouldReceive('withoutEntityRestriction')->andReturnUsing(fn (callable $callback) => $callback());
    $glpi->shouldReceive('getItems')
        ->with('ItemVirtualMachine', Mockery::type('array'))
        ->andThrow(new RuntimeException('Erreur lors de la récupération de ItemVirtualMachine : 400'));
    $glpi->shouldReceive('getItems')
        ->with('ComputerVirtualMachine', Mockery::type('array'))
        ->andThrow(new RuntimeException('Erreur lors de la récupération de ComputerVirtualMachine : 500'));

    $mercator = vmLinksMercatorMock([], []);

    expect(fn () => (new VmLinkSyncService)->sync($glpi, $mercator))->toThrow(RuntimeException::class);
});

it('récupère les entrées VM via withoutEntityRestriction (issue #15, bug GLPI 10.0.20 "Unknown column ... is_recursive")', function () {
    $host = vmHost(1, 'HOST-1');
    $ls = vmLogicalComputer(10, 'VM-1', 'uuid-d-1111-2222-3333-444444444444');

    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Computer', Mockery::type('array'))->andReturn([$host, $ls]);

    // ->once() : si le service appelait getItems('ComputerVirtualMachine', ...) en
    // dehors de withoutEntityRestriction(), cette expectation ne serait jamais
    // exercée (RuntimeException "no matching handler" à l'exécution du test).
    $glpi->shouldReceive('withoutEntityRestriction')
        ->once()
        ->andReturnUsing(fn (callable $callback) => $callback());

    $glpi->shouldReceive('getItems')
        ->with('ItemVirtualMachine', Mockery::type('array'))
        ->andReturn([vmEntry(1, 'VM-1', 'uuid-d-1111-2222-3333-444444444444')]);

    $mercator = vmLinksMercatorMock([vmMercatorLs(300, 'VM-1', 10)], [vmMercatorPs(400, 'HOST-1', 1)]);

    $updated = [];
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($updated[300]['physical_servers'])->toBe([400]);
});

it('lie via correspondance uuid exacte', function () {
    $host = vmHost(1, 'HOST-1');
    $ls = vmLogicalComputer(10, 'VM-1', '11111111-2222-3333-4444-555555555555');

    $glpi = vmLinksGlpiMock([$host, $ls], [vmEntry(1, 'VM-1', '11111111-2222-3333-4444-555555555555')]);
    $mercator = vmLinksMercatorMock([vmMercatorLs(300, 'VM-1', 10)], [vmMercatorPs(400, 'HOST-1', 1)]);

    $updated = [];
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    $stats = (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($updated[300]['physical_servers'])->toBe([400]);
    expect($stats['updated'])->toBe(1);
});

it('lie via uuid à endianness inversée (variante hyperviseur)', function () {
    $host = vmHost(1, 'HOST-1');
    // uuid "réel" du Computer serveur logique.
    $ls = vmLogicalComputer(10, 'VM-1', '12345678-1234-5678-1234-567812345678');

    $glpi = vmLinksGlpiMock([$host, $ls], [
        // uuid vu depuis l'hôte : 3 premiers groupes en endianness inversée.
        vmEntry(1, 'VM-1', '78563412-3412-7856-1234-567812345678'),
    ]);
    $mercator = vmLinksMercatorMock([vmMercatorLs(300, 'VM-1', 10)], [vmMercatorPs(400, 'HOST-1', 1)]);

    $updated = [];
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($updated[300]['physical_servers'])->toBe([400]);
});

it('résout par nom (insensible à la casse) quand aucun uuid ne correspond', function () {
    $host = vmHost(1, 'HOST-1');
    $ls = vmLogicalComputer(10, 'vm-unique', null);

    $glpi = vmLinksGlpiMock([$host, $ls], [vmEntry(1, 'VM-UNIQUE')]);
    $mercator = vmLinksMercatorMock([vmMercatorLs(300, 'vm-unique', 10)], [vmMercatorPs(400, 'HOST-1', 1)]);

    $updated = [];
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    $stats = (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($updated[300]['physical_servers'])->toBe([400]);
    expect($stats['ambiguous'])->toBe(0);
});

it('ne lie rien et journalise un warning quand deux Computer candidats partagent le même nom', function () {
    Log::spy();

    $host = vmHost(1, 'HOST-1');
    $ls1 = vmLogicalComputer(10, 'dup-name', null);
    $ls2 = vmLogicalComputer(11, 'dup-name', null);

    $glpi = vmLinksGlpiMock([$host, $ls1, $ls2], [vmEntry(1, 'DUP-NAME')]);
    $mercator = vmLinksMercatorMock(
        [vmMercatorLs(300, 'dup-name', 10), vmMercatorLs(301, 'dup-name', 11)],
        [vmMercatorPs(400, 'HOST-1', 1)]
    );
    $mercator->shouldReceive('update')->andReturn([])->zeroOrMoreTimes();

    $stats = (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($stats['ambiguous'])->toBe(1);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m) => str_contains($m, 'ambiguïté'))
        ->atLeast()->once();
});

it('ignore les entrées VM avec is_deleted=1', function () {
    $host = vmHost(1, 'HOST-1');
    $ls = vmLogicalComputer(10, 'VM-1', 'uuid-x-yyyy-zzzz-wwww-vvvvvvvvvvvv');

    $glpi = vmLinksGlpiMock([$host, $ls], [vmEntry(1, 'VM-1', 'uuid-x-yyyy-zzzz-wwww-vvvvvvvvvvvv', deleted: true)]);
    $mercator = vmLinksMercatorMock([vmMercatorLs(300, 'VM-1', 10)], [vmMercatorPs(400, 'HOST-1', 1)]);

    $updated = [];
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($updated[300]['physical_servers'])->toBe([]);
});

it('lie une VM présente sur deux hôtes aux deux serveurs physiques, sans doublon', function () {
    $host1 = vmHost(1, 'HOST-1');
    $host2 = vmHost(2, 'HOST-2');
    $ls = vmLogicalComputer(10, 'VM-1', 'uuid-partage-eeee-ffff-000011112222');

    $glpi = vmLinksGlpiMock([$host1, $host2, $ls], [
        vmEntry(1, 'VM-1', 'uuid-partage-eeee-ffff-000011112222'),
        vmEntry(2, 'VM-1', 'uuid-partage-eeee-ffff-000011112222'),
    ]);
    $mercator = vmLinksMercatorMock(
        [vmMercatorLs(300, 'VM-1', 10)],
        [vmMercatorPs(400, 'HOST-1', 1), vmMercatorPs(401, 'HOST-2', 2)]
    );

    $updated = [];
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($updated[300]['physical_servers'])->toEqualCanonicalizing([400, 401]);
});

it('nettoie (physical_servers vide) un serveur logique tagué {GLPI} sans hôte résolu, sans jamais toucher un serveur logique Mercator non tagué', function () {
    $host = vmHost(1, 'HOST-1'); // aucune VM dessus

    $glpi = vmLinksGlpiMock([$host], []);
    $mercator = vmLinksMercatorMock(
        [
            vmMercatorLs(300, 'VM-ORPHELINE', 10), // tagué {GLPI}10, aucun hôte résolu
            ['id' => 301, 'name' => 'VM-MANUELLE', 'ext_refs' => null], // pas de tag {GLPI}
        ],
        [vmMercatorPs(400, 'HOST-1', 1)]
    );

    $updated = [];
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($updated[300]['physical_servers'])->toBe([]);
    expect($updated)->not->toHaveKey(301);
});

it('ne fait aucune écriture en mode dry-run', function () {
    $host = vmHost(1, 'HOST-1');
    $ls = vmLogicalComputer(10, 'VM-1', 'uuid-1-aaaa-bbbb-cccc-dddddddddddd');

    $glpi = vmLinksGlpiMock([$host, $ls], [vmEntry(1, 'VM-1', 'uuid-1-aaaa-bbbb-cccc-dddddddddddd')]);
    $mercator = vmLinksMercatorMock([vmMercatorLs(300, 'VM-1', 10)], [vmMercatorPs(400, 'HOST-1', 1)]);
    $mercator->shouldNotReceive('update');

    $stats = (new VmLinkSyncService)->sync($glpi, $mercator, dryRun: true);

    expect($stats['updated'])->toBe(1);
});

it('gère sans exception un hôte ou un serveur logique GLPI sans correspondance Mercator, et traite quand même les autres liaisons', function () {
    Log::spy();

    $host1 = vmHost(1, 'HOST-1'); // pas de correspondance Mercator (ext_refs absent)
    $host2 = vmHost(2, 'HOST-2');
    $ls1 = vmLogicalComputer(10, 'VM-SANS-MERC', 'uuid-a-1111-2222-3333-444444444444'); // pas de correspondance Mercator
    $ls2 = vmLogicalComputer(11, 'VM-OK', 'uuid-b-1111-2222-3333-444444444444');

    $glpi = vmLinksGlpiMock([$host1, $host2, $ls1, $ls2], [
        vmEntry(1, 'VM-SANS-MERC', 'uuid-a-1111-2222-3333-444444444444'),
        vmEntry(2, 'VM-OK', 'uuid-b-1111-2222-3333-444444444444'),
    ]);

    $mercator = vmLinksMercatorMock(
        [vmMercatorLs(300, 'VM-OK', 11)], // aucune entrée pour le GLPI id 10
        [vmMercatorPs(401, 'HOST-2', 2)]  // aucune entrée pour le GLPI id 1
    );

    $updated = [];
    $mercator->shouldReceive('update')->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
        $updated[$id] = $payload;

        return [];
    });

    $stats = (new VmLinkSyncService)->sync($glpi, $mercator);

    expect($stats['errors'])->toBe(0);
    expect($updated[300]['physical_servers'])->toBe([401]);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});
