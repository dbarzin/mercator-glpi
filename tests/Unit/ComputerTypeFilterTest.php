<?php

use App\Services\Glpi\Handlers\LogicalServerSyncHandler;
use App\Services\Glpi\Handlers\PhysicalServerSyncHandler;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Glpi\Mappers\LogicalServerMapper;
use App\Services\Glpi\Mappers\PhysicalServerMapper;
use App\Services\Glpi\Mappers\WorkstationMapper;

// ── Tests filtrage par sous-type Computer (Évolution 5) ───────────────────────

function computerWithType(mixed $typeValue): array
{
    return ['computertypes_id' => $typeValue, 'name' => 'PC-TEST'];
}

// ── WorkstationSyncHandler ────────────────────────────────────────────────────

it('WorkstationSyncHandler accepte tous les types si la config est vide', function () {
    config(['glpi.computer_types.workstations' => []]);

    $handler = new WorkstationSyncHandler(new WorkstationMapper);

    expect($handler->filterItem(computerWithType('Poste de travail')))->toBeTrue();
    expect($handler->filterItem(computerWithType('Laptop')))->toBeTrue();
    expect($handler->filterItem(computerWithType(0)))->toBeTrue();
});

it('WorkstationSyncHandler filtre par nom de type', function () {
    config(['glpi.computer_types.workstations' => ['Poste de travail', 'Laptop']]);

    $handler = new WorkstationSyncHandler(new WorkstationMapper);

    expect($handler->filterItem(computerWithType('Poste de travail')))->toBeTrue();
    expect($handler->filterItem(computerWithType('Laptop')))->toBeTrue();
    expect($handler->filterItem(computerWithType('Serveur physique')))->toBeFalse();
});

it('WorkstationSyncHandler filtre par ID numérique', function () {
    config(['glpi.computer_types.workstations' => ['1', '4']]);

    $handler = new WorkstationSyncHandler(new WorkstationMapper);

    expect($handler->filterItem(computerWithType('1')))->toBeTrue();
    expect($handler->filterItem(computerWithType(4)))->toBeTrue();
    expect($handler->filterItem(computerWithType('2')))->toBeFalse();
});

it('WorkstationSyncHandler est insensible à la casse', function () {
    config(['glpi.computer_types.workstations' => ['Poste de travail']]);

    $handler = new WorkstationSyncHandler(new WorkstationMapper);

    expect($handler->filterItem(computerWithType('POSTE DE TRAVAIL')))->toBeTrue();
    expect($handler->filterItem(computerWithType('poste de travail')))->toBeTrue();
});

// ── LogicalServerSyncHandler ──────────────────────────────────────────────────

it('LogicalServerSyncHandler exclut tout si la config est vide', function () {
    config(['glpi.computer_types.logical_servers' => []]);

    $handler = new LogicalServerSyncHandler(
        new LogicalServerMapper(new WorkstationMapper)
    );

    expect($handler->filterItem(computerWithType('Machine virtuelle')))->toBeFalse();
    expect($handler->filterItem(computerWithType(0)))->toBeFalse();
});

it('LogicalServerSyncHandler accepte les types configurés', function () {
    config(['glpi.computer_types.logical_servers' => ['Machine virtuelle', 'Conteneur']]);

    $handler = new LogicalServerSyncHandler(
        new LogicalServerMapper(new WorkstationMapper)
    );

    expect($handler->filterItem(computerWithType('Machine virtuelle')))->toBeTrue();
    expect($handler->filterItem(computerWithType('Conteneur')))->toBeTrue();
    expect($handler->filterItem(computerWithType('Poste de travail')))->toBeFalse();
});

// ── PhysicalServerSyncHandler ─────────────────────────────────────────────────

it('PhysicalServerSyncHandler exclut tout si la config est vide', function () {
    config(['glpi.computer_types.physical_servers' => []]);

    $handler = new PhysicalServerSyncHandler(
        new PhysicalServerMapper(new WorkstationMapper)
    );

    expect($handler->filterItem(computerWithType('Serveur physique')))->toBeFalse();
});

it('PhysicalServerSyncHandler accepte les types configurés', function () {
    config(['glpi.computer_types.physical_servers' => ['Serveur physique', '5']]);

    $handler = new PhysicalServerSyncHandler(
        new PhysicalServerMapper(new WorkstationMapper)
    );

    expect($handler->filterItem(computerWithType('Serveur physique')))->toBeTrue();
    expect($handler->filterItem(computerWithType(5)))->toBeTrue();
    expect($handler->filterItem(computerWithType('Laptop')))->toBeFalse();
});
