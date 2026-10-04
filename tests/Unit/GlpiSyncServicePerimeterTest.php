<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
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

it('remplace le périmètre existant à la mise à jour', function () {
    $mercator = perimeterMercator([['id' => 1, 'name' => 'PC-1', 'ext_refs' => '{GLPI}1', 'perimeter_id' => 1]]);

    $stats = syncWorkstations([['id' => 1, 'name' => 'PC-1']], $mercator, 5);

    expect($stats['updated'])->toBe(1)
        ->and($mercator->updated[1]['perimeter_id'])->toBe(5);
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
