<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Mercator\Contracts\MercatorClientInterface;

// ── Option --perimeter / MERCATOR_PERIMETER_ID de glpi:sync ──────────────────

function mockClientsForPerimeter(?bool $perimeterExists = true): void
{
    test()->mock(GlpiClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getItems')->andReturn([]);
        $mock->shouldReceive('getEntityId')->andReturn(null);
        $mock->shouldReceive('killSession');
    });

    test()->mock(MercatorClientInterface::class, function ($mock) use ($perimeterExists) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getBuildings')->andReturn([]);
        $mock->shouldReceive('getSites')->andReturn([]);
        $mock->shouldReceive('getAll')->andReturn([]);
        $mock->shouldReceive('perimeterExists')->andReturn($perimeterExists);
    });
}

/**
 * Remplace GlpiSyncService par un espion qui mémorise le périmètre transmis.
 */
function spySyncPerimeter(): ArrayObject
{
    $received = new ArrayObject;

    test()->mock(GlpiSyncService::class, function ($mock) use ($received) {
        $mock->shouldReceive('sync')->andReturnUsing(function ($glpi, $mercator, $handler, $dryRun, $perimeterId = null) use ($received) {
            $received[] = $perimeterId;

            return ['created' => 0, 'updated' => 0, 'deleted' => 0, 'marked_old' => 0, 'errors' => 0, 'endpoint_missing' => false];
        });
    });

    return $received;
}

it('transmet --perimeter à la synchronisation', function () {
    mockClientsForPerimeter();
    $received = spySyncPerimeter();

    $this->artisan('glpi:sync', ['--type' => ['workstations'], '--perimeter' => '3', '--dry-run' => true])
        ->expectsOutputToContain('Périmètre Mercator : 3')
        ->assertExitCode(0);

    expect($received->getArrayCopy())->toBe([3]);
});

it('utilise MERCATOR_PERIMETER_ID à défaut d\'option, l\'option restant prioritaire', function () {
    config(['glpi.mercator.perimeter_id' => 4]);
    mockClientsForPerimeter();
    $received = spySyncPerimeter();

    $this->artisan('glpi:sync', ['--type' => ['workstations'], '--dry-run' => true])->assertExitCode(0);
    $this->artisan('glpi:sync', ['--type' => ['workstations'], '--perimeter' => '6', '--dry-run' => true])->assertExitCode(0);

    expect($received->getArrayCopy())->toBe([4, 6]);
});

it('ne transmet aucun périmètre sans option ni configuration', function () {
    config(['glpi.mercator.perimeter_id' => null]);
    mockClientsForPerimeter();
    $received = spySyncPerimeter();

    $this->artisan('glpi:sync', ['--type' => ['workstations'], '--dry-run' => true])
        ->doesntExpectOutputToContain('Périmètre Mercator')
        ->assertExitCode(0);

    expect($received->getArrayCopy())->toBe([null]);
});

it('refuse une valeur de --perimeter non numérique', function () {
    mockClientsForPerimeter();

    $this->artisan('glpi:sync', ['--perimeter' => 'abc'])
        ->expectsOutputToContain('Option --perimeter invalide')
        ->assertExitCode(1);
});

it('s\'arrête si le périmètre n\'existe pas dans Mercator', function () {
    mockClientsForPerimeter(perimeterExists: false);
    $received = spySyncPerimeter();

    $this->artisan('glpi:sync', ['--type' => ['workstations'], '--perimeter' => '99'])
        ->expectsOutputToContain("Le périmètre Mercator 99 n'existe pas")
        ->assertExitCode(1);

    expect($received->getArrayCopy())->toBe([]);
});

it('poursuit si l\'existence du périmètre n\'est pas vérifiable (permission configure absente)', function () {
    mockClientsForPerimeter(perimeterExists: null);
    $received = spySyncPerimeter();

    $this->artisan('glpi:sync', ['--type' => ['workstations'], '--perimeter' => '3', '--dry-run' => true])->assertExitCode(0);

    expect($received->getArrayCopy())->toBe([3]);
});
