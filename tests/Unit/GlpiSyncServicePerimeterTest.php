<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\PhysicalServerSyncHandler;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Glpi\Mappers\PhysicalServerMapper;
use App\Services\Glpi\Mappers\WorkstationMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;

// ── Périmètre Mercator (--perimeter / MERCATOR_PERIMETER_ID) ─────────────────

beforeEach(function () {
    config(['glpi.computer_types.workstations' => [], 'glpi.allowed_states' => ['default' => []]]);
});

function perimeterGlpi(array $computers): GlpiClientInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);
    $mock->shouldReceive('getItems')->andReturn($computers);
    $mock->shouldReceive('getItem')->andReturn([]);
    $mock->shouldReceive('getSubItems')->andReturn([]);

    return $mock;
}

/**
 * Mercator simulé qui enregistre create/update/delete.
 */
function perimeterMercator(array $workstations, array $buildings = [], array $sites = []): object
{
    $recorder = new class
    {
        public array $created = [];

        public array $updated = [];

        public array $deleted = [];

        public MercatorClientInterface $client;
    };

    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getBuildings')->andReturn($buildings);
    $mock->shouldReceive('getSites')->andReturn($sites);
    $mock->shouldReceive('getAll')->andReturn($workstations);
    $mock->shouldReceive('create')->andReturnUsing(function ($ep, $payload) use ($recorder) {
        $recorder->created[$payload['name']] = $payload;

        return ['id' => 900 + count($recorder->created)];
    });
    $mock->shouldReceive('update')->andReturnUsing(function ($ep, $id, $payload) use ($recorder) {
        $recorder->updated[$id] = $payload;

        return $payload;
    });
    $mock->shouldReceive('delete')->andReturnUsing(function ($ep, $id) use ($recorder) {
        $recorder->deleted[] = $id;
    });
    $recorder->client = $mock;

    return $recorder;
}

function syncWorkstations(array $computers, object $mercator, ?int $perimeterId): array
{
    return (new GlpiSyncService)->sync(
        perimeterGlpi($computers),
        $mercator->client,
        new WorkstationSyncHandler(new WorkstationMapper),
        false,
        $perimeterId,
    );
}

it('n\'envoie aucun perimeter_id sans périmètre configuré (création et mise à jour)', function () {
    $mercator = perimeterMercator([['id' => 1, 'name' => 'PC-1', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 3]]);

    syncWorkstations([['id' => 1, 'name' => 'PC-1'], ['id' => 2, 'name' => 'PC-2']], $mercator, null);

    expect($mercator->created['PC-2'])->not->toHaveKey('perimeter_id')
        ->and($mercator->updated[1])->not->toHaveKey('perimeter_id');
});

it('impose le périmètre configuré à la création', function () {
    $mercator = perimeterMercator([]);

    syncWorkstations([['id' => 2, 'name' => 'PC-2']], $mercator, 5);

    expect($mercator->created['PC-2']['perimeter_id'])->toBe(5);
});

it('envoie le périmètre configuré à la mise à jour', function () {
    $mercator = perimeterMercator([
        ['id' => 1, 'name' => 'PC-1', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 5],
        // Mercator sans gestion des périmètres : pas de perimeter_id → considéré dans le périmètre
        ['id' => 2, 'name' => 'PC-2', 'ext_refs' => '{GLPI}2'],
    ]);

    $stats = syncWorkstations([['id' => 1, 'name' => 'PC-1'], ['id' => 2, 'name' => 'PC-2']], $mercator, 5);

    expect($stats['updated'])->toBe(2)
        ->and($mercator->updated[1]['perimeter_id'])->toBe(5)
        ->and($mercator->updated[2]['perimeter_id'])->toBe(5);
});

it('privilégie l\'homonyme du périmètre cible pour la réconciliation par nom', function () {
    $mercator = perimeterMercator([
        ['id' => 10, 'name' => 'PC-1', 'ext_refs' => null, 'perimeter_id' => 1],
        ['id' => 20, 'name' => 'PC-1', 'ext_refs' => null, 'perimeter_id' => 5],
    ]);

    syncWorkstations([['id' => 1, 'name' => 'PC-1']], $mercator, 5);

    expect(array_keys($mercator->updated))->toContain(20)
        ->and($mercator->updated[20]['ext_refs'])->toBe('{GLPI}1');
});

it('privilégie l\'item du périmètre cible quand plusieurs portent le même tag ext_refs', function () {
    $mercator = perimeterMercator([
        ['id' => 20, 'name' => 'PC-1', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 5],
        ['id' => 10, 'name' => 'PC-1 copie', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 1],
    ]);

    syncWorkstations([['id' => 1, 'name' => 'PC-1']], $mercator, 5);

    expect($mercator->updated)->toHaveKey(20)
        ->and($mercator->updated)->not->toHaveKey(10);
});

it('ne nettoie (suppression / [OLD]) que les orphelins du périmètre cible', function () {
    $mercator = perimeterMercator([
        ['id' => 1, 'name' => 'OLD-TAGUE-P5', 'ext_refs' => '{GLPI}91', 'perimeter_id' => 5],
        ['id' => 2, 'name' => 'MANUEL-P5', 'ext_refs' => null, 'perimeter_id' => 5],
        ['id' => 3, 'name' => 'OLD-TAGUE-P1', 'ext_refs' => '{GLPI}92', 'perimeter_id' => 1],
        ['id' => 4, 'name' => 'MANUEL-P1', 'ext_refs' => null, 'perimeter_id' => 1],
    ]);

    $stats = syncWorkstations([], $mercator, 5);

    expect($mercator->deleted)->toBe([1])
        ->and($mercator->updated)->toBe([2 => ['name' => '[OLD] MANUEL-P5']])
        ->and($stats)->toMatchArray(['deleted' => 1, 'marked_old' => 1]);
});

it('nettoie tous les orphelins sans périmètre configuré (comportement historique)', function () {
    $mercator = perimeterMercator([
        ['id' => 1, 'name' => 'A', 'ext_refs' => '{GLPI}91', 'perimeter_id' => 5],
        ['id' => 3, 'name' => 'B', 'ext_refs' => '{GLPI}92', 'perimeter_id' => 1],
    ]);

    syncWorkstations([], $mercator, null);

    expect($mercator->deleted)->toBe([1, 3]);
});

it('considère un item sans perimeter_id (Mercator sans périmètres) comme dans le périmètre', function () {
    $mercator = perimeterMercator([['id' => 1, 'name' => 'ORPHELIN', 'ext_refs' => '{GLPI}91']]);

    syncWorkstations([], $mercator, 5);

    expect($mercator->deleted)->toBe([1]);
});

it('résout le building homonyme du périmètre cible', function () {
    $mercator = perimeterMercator([], [
        ['id' => 70, 'name' => 'Salle 101', 'site_id' => 7, 'perimeter_id' => 5],
        ['id' => 10, 'name' => 'Salle 101', 'site_id' => 1, 'perimeter_id' => 1],
    ]);

    syncWorkstations([['id' => 2, 'name' => 'PC-2', 'locations_id' => 'Siège > Salle 101']], $mercator, 5);

    expect($mercator->created['PC-2'])->toMatchArray(['building_id' => 70, 'site_id' => 7]);
});

it('résout le site homonyme du périmètre cible', function () {
    $mercator = perimeterMercator([], [], [
        ['id' => 7, 'name' => 'Siège', 'perimeter_id' => 5],
        ['id' => 1, 'name' => 'Siège', 'perimeter_id' => 1],
    ]);

    syncWorkstations([['id' => 2, 'name' => 'PC-2', 'locations_id' => 'Siège']], $mercator, 5);

    expect($mercator->created['PC-2']['site_id'])->toBe(7);
});

it('ne réconcilie pas par nom un homonyme d\'un autre périmètre : crée un nouvel item', function () {
    // Cas de deux instances GLPI alimentant chacune leur périmètre : "PC-1" du périmètre 1
    // (autre instance) ne doit être ni déplacé, ni écrasé, ni nettoyé.
    $mercator = perimeterMercator([
        ['id' => 10, 'name' => 'PC-1', 'ext_refs' => '{GLPI}7', 'perimeter_id' => 1],
        ['id' => 11, 'name' => 'Firefox-PC', 'ext_refs' => null, 'perimeter_id' => 1],
    ]);

    $stats = syncWorkstations([['id' => 1, 'name' => 'PC-1'], ['id' => 2, 'name' => 'Firefox-PC']], $mercator, 5);

    expect($stats)->toMatchArray(['created' => 2, 'updated' => 0, 'deleted' => 0, 'marked_old' => 0])
        ->and($mercator->created['PC-1'])->toMatchArray(['perimeter_id' => 5, 'ext_refs' => '{GLPI}1'])
        ->and($mercator->updated)->toBe([])
        ->and($mercator->deleted)->toBe([]);
});

it('ignore un item d\'un autre périmètre portant le même tag ext_refs (autre instance GLPI)', function () {
    // L'instance A a synchronisé son Computer n°1 dans le périmètre 1 ; l'instance B, dont
    // le Computer n°1 est un autre poste, synchronise dans le périmètre 5.
    $mercator = perimeterMercator([['id' => 10, 'name' => 'PC-A', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 1]]);

    $stats = syncWorkstations([['id' => 1, 'name' => 'PC-B']], $mercator, 5);

    expect($stats)->toMatchArray(['created' => 1, 'updated' => 0, 'deleted' => 0, 'marked_old' => 0])
        ->and($mercator->created['PC-B'])->toMatchArray(['perimeter_id' => 5, 'ext_refs' => '{GLPI}1'])
        ->and($mercator->updated)->toBe([])
        ->and($mercator->deleted)->toBe([]);
});

it('ne déplace plus un item déjà synchronisé dans un autre périmètre : il est recréé dans le périmètre cible', function () {
    $mercator = perimeterMercator([['id' => 10, 'name' => 'PC-1', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 1]]);

    syncWorkstations([['id' => 1, 'name' => 'PC-1']], $mercator, 5);

    expect($mercator->updated)->toBe([])
        ->and($mercator->created['PC-1']['perimeter_id'])->toBe(5);
});

it('résout le bay_id uniquement parmi les bays du périmètre cible', function () {
    $glpi = Mockery::mock(GlpiClientInterface::class);
    $glpi->shouldReceive('getItems')->with('Computer', Mockery::any())->andReturn([['id' => 4, 'name' => 'SRV-1', 'computertypes_id' => 'Serveur']]);
    $glpi->shouldReceive('getItems')->with('Item_Rack', Mockery::any())->andReturn([['itemtype' => 'Computer', 'items_id' => 4, 'racks_id' => 1]]);
    $glpi->shouldReceive('getItem')->andReturn([]);
    $glpi->shouldReceive('getSubItems')->andReturn([]);
    config(['glpi.computer_types.physical_servers' => ['Serveur']]);

    $created = [];
    $mercator = Mockery::mock(MercatorClientInterface::class);
    $mercator->shouldReceive('getBuildings')->andReturn([]);
    $mercator->shouldReceive('getSites')->andReturn([]);
    $mercator->shouldReceive('getAll')->with('physical-servers')->andReturn([]);
    $mercator->shouldReceive('getAll')->with('bays')->andReturn([
        ['id' => 70, 'name' => 'RACK-A', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 1],
        ['id' => 80, 'name' => 'RACK-B', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 5],
    ]);
    $mercator->shouldReceive('create')->andReturnUsing(function ($ep, $payload) use (&$created) {
        $created[] = $payload;

        return ['id' => 1];
    });

    (new GlpiSyncService)->sync(
        $glpi,
        $mercator,
        new PhysicalServerSyncHandler(new PhysicalServerMapper(new WorkstationMapper)),
        false,
        5,
    );

    expect($created[0]['bay_id'])->toBe(80);
});

it('réconcilie par nom tous périmètres confondus sans périmètre configuré (comportement historique)', function () {
    $mercator = perimeterMercator([['id' => 10, 'name' => 'PC-1', 'ext_refs' => null, 'perimeter_id' => 1]]);

    syncWorkstations([['id' => 1, 'name' => 'PC-1']], $mercator, null);

    expect($mercator->created)->toBe([])
        ->and($mercator->updated[10]['ext_refs'])->toBe('{GLPI}1');
});

it('réconcilie par nom un item sans perimeter_id (Mercator sans périmètres)', function () {
    $mercator = perimeterMercator([['id' => 10, 'name' => 'PC-1', 'ext_refs' => null]]);

    syncWorkstations([['id' => 1, 'name' => 'PC-1']], $mercator, 5);

    expect($mercator->created)->toBe([])
        ->and($mercator->updated)->toHaveKey(10);
});
