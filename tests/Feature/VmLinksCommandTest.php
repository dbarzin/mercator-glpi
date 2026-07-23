<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Support\Facades\Log;

// ── Tests de câblage GlpiSyncCommand ↔ VmLinkSyncService (GLPI_SYNC_VM_LINKS) ──
// La logique métier (résolution uuid/nom, GLPI 10/11, nettoyage...) est testée
// dans VmLinkSyncServiceTest ; ces tests vérifient seulement que la commande
// invoque (ou non) le service selon la config et les --type demandés.

it('n\'invoque jamais VmLinkSyncService quand GLPI_SYNC_VM_LINKS est absent/false (défaut)', function () {
    config(['glpi.sync.vm_links' => false]);

    $this->mock(GlpiClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getItems')->andReturn([]);
        $mock->shouldReceive('getEntityId')->andReturn(null);
        $mock->shouldReceive('killSession');
        $mock->shouldNotReceive('getSubItems');
    });

    $this->mock(MercatorClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getBuildings')->andReturn([]);
        $mock->shouldReceive('getSites')->andReturn([]);
        $mock->shouldReceive('getAll')->andReturn([]);
        $mock->shouldNotReceive('update');
    });

    $this->artisan('glpi:sync')->assertExitCode(0);
});

it('invoque VmLinkSyncService quand GLPI_SYNC_VM_LINKS=true et que logical_servers/physical_servers sont synchronisés', function () {
    config([
        'glpi.sync.vm_links' => true,
        'glpi.computer_types.physical_servers' => ['Serveur physique'],
        'glpi.computer_types.logical_servers' => ['Machine virtuelle'],
    ]);

    $this->mock(GlpiClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getEntityId')->andReturn(null);
        $mock->shouldReceive('killSession');
        $mock->shouldReceive('getItems')->with('Computer', Mockery::type('array'))->andReturn([]);
        // PhysicalServerSyncHandler implémente SupportsBayResolution (résolution bay_id).
        $mock->shouldReceive('getItems')->with('Item_Rack', Mockery::type('array'))->andReturn([]);
        // VmLinkSyncService::fetchAllVmEntries() : collection VM complète (issue #15).
        $mock->shouldReceive('getItems')->with('ItemVirtualMachine', Mockery::type('array'))->andReturn([]);
    });

    $this->mock(MercatorClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getBuildings')->andReturn([]);
        $mock->shouldReceive('getSites')->andReturn([]);
        // Un appel par handler (logical_servers, physical_servers) + un appel par
        // VmLinkSyncService::sync() = 2 appels chacun. Si le service n'était pas
        // invoqué, l'expectation ->twice() échouerait (un seul appel constaté).
        $mock->shouldReceive('getAll')->with('logical-servers')->twice()->andReturn([]);
        $mock->shouldReceive('getAll')->with('physical-servers')->twice()->andReturn([]);
        // Résolution bay_id (SupportsBayResolution) : bays Mercator.
        $mock->shouldReceive('getAll')->with('bays')->andReturn([]);
    });

    $this->artisan('glpi:sync --type=logical_servers --type=physical_servers')->assertExitCode(0);
});

it('ignore VmLinkSyncService (avec log explicite) quand un seul des deux types est synchronisé', function () {
    config(['glpi.sync.vm_links' => true]);
    Log::spy();

    $this->mock(GlpiClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getItems')->andReturn([]);
        $mock->shouldReceive('getEntityId')->andReturn(null);
        $mock->shouldReceive('killSession');
        $mock->shouldNotReceive('getSubItems');
    });

    $this->mock(MercatorClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getBuildings')->andReturn([]);
        $mock->shouldReceive('getSites')->andReturn([]);
        $mock->shouldReceive('getAll')->andReturn([]);
    });

    $this->artisan('glpi:sync --type=logical_servers')->assertExitCode(0);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $m) => str_contains($m, 'vm-links') && str_contains($m, 'ignorée'))
        ->atLeast()->once();
});
