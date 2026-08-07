<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Mercator\Contracts\MercatorClientInterface;

// ── Tests de câblage GlpiSyncCommand ↔ GlpiSyncService::syncDatabaseLinks() ────
// La logique métier (résolution ext_refs/nom, host Computer...) est testée dans
// GlpiDatabaseLinksTest ; ces tests vérifient seulement le câblage --type.

it('exécute database_links et affiche la ligne de stats quand demandé explicitement', function () {
    $this->mock(GlpiClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getEntityId')->andReturn(null);
        $mock->shouldReceive('killSession');
        $mock->shouldReceive('getItems')->with('Database', Mockery::type('array'))->andReturn([]);
        $mock->shouldReceive('getItems')->with('DatabaseInstance', Mockery::type('array'))->andReturn([]);
    });

    $this->mock(MercatorClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getBuildings')->andReturn([]);
        $mock->shouldReceive('getSites')->andReturn([]);
        $mock->shouldReceive('getAll')->with('databases')->andReturn([]);
        $mock->shouldReceive('getAll')->with('logical-servers')->andReturn([]);
    });

    $this->artisan('glpi:sync --type=databases --type=database_links')
        ->expectsOutputToContain('database_links')
        ->assertExitCode(0);
});

it('n\'appelle jamais DatabaseInstance lors d\'une synchronisation complète par défaut (database_links non inclus)', function () {
    $this->mock(GlpiClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getEntityId')->andReturn(null);
        $mock->shouldReceive('killSession');
        $mock->shouldReceive('getItems')->andReturn([]);
        $mock->shouldNotReceive('getItems')->with('DatabaseInstance', Mockery::type('array'));
    });

    $this->mock(MercatorClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getBuildings')->andReturn([]);
        $mock->shouldReceive('getSites')->andReturn([]);
        $mock->shouldReceive('getAll')->andReturn([]);
    });

    $this->artisan('glpi:sync')->assertExitCode(0);
});
