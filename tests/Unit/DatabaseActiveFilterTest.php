<?php

use App\Services\Glpi\Handlers\DatabaseSyncHandler;
use App\Services\Glpi\Mappers\DatabaseMapper;

// ── Tests filtrage par statut actif (GLPI_SYNC_ONLY_ACTIVE_DATABASES) ─────────

function databaseWith(mixed $isActive = null): array
{
    $item = ['name' => 'db01', 'databaseinstances_id' => 1];
    if ($isActive !== null) {
        $item['is_active'] = $isActive;
    }

    return $item;
}

function makeDatabaseHandler(): DatabaseSyncHandler
{
    return new DatabaseSyncHandler(new DatabaseMapper);
}

it('conserve toutes les bases si le flag est désactivé (défaut)', function () {
    config(['glpi.sync.only_active_databases' => false]);

    $handler = makeDatabaseHandler();

    expect($handler->filterItem(databaseWith(1)))->toBeTrue();
    expect($handler->filterItem(databaseWith(0)))->toBeTrue();
    expect($handler->filterItem(databaseWith()))->toBeTrue();
});

it('conserve les bases actives quand le flag est activé', function () {
    config(['glpi.sync.only_active_databases' => true]);

    $handler = makeDatabaseHandler();

    expect($handler->filterItem(databaseWith(1)))->toBeTrue();
});

it('exclut les bases inactives quand le flag est activé', function () {
    config(['glpi.sync.only_active_databases' => true]);

    $handler = makeDatabaseHandler();

    expect($handler->filterItem(databaseWith(0)))->toBeFalse();
});

it('gère les valeurs is_active sous forme de chaîne', function () {
    config(['glpi.sync.only_active_databases' => true]);

    $handler = makeDatabaseHandler();

    expect($handler->filterItem(databaseWith('1')))->toBeTrue();
    expect($handler->filterItem(databaseWith('0')))->toBeFalse();
});

it('conserve la base si is_active est absent, même flag activé', function () {
    config(['glpi.sync.only_active_databases' => true]);

    $handler = makeDatabaseHandler();

    expect($handler->filterItem(databaseWith()))->toBeTrue();
});
