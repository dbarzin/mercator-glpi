<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Mercator\Contracts\MercatorClientInterface;

// Les Feature tests vérifient l'intégration de la commande (auth, options, exit code).
// La logique métier (create/update/delete/OLD) est testée dans GlpiSyncServiceTest.

it('s\'authentifie sur GLPI et Mercator', function () {
    $this->mock(GlpiClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getItems')->andReturn([]);
        $mock->shouldReceive('getEntityId')->andReturn(null);
        $mock->shouldReceive('killSession')->once();
    });

    $this->mock(MercatorClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getBuildings')->andReturn([]);
        $mock->shouldReceive('getSites')->andReturn([]);
        $mock->shouldReceive('getAll')->andReturn([]);
    });

    $this->artisan('glpi:sync')->assertExitCode(0);
});

it('ferme la session GLPI après la sync', function () {
    $this->mock(GlpiClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getItems')->andReturn([]);
        $mock->shouldReceive('getEntityId')->andReturn(null);
        $mock->shouldReceive('killSession')->once();
    });

    $this->mock(MercatorClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getBuildings')->andReturn([]);
        $mock->shouldReceive('getSites')->andReturn([]);
        $mock->shouldReceive('getAll')->andReturn([]);
    });

    $this->artisan('glpi:sync')->assertExitCode(0);
});

it('retourne un code d\'erreur si l\'authentification GLPI échoue', function () {
    $this->mock(GlpiClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate')
            ->andThrow(new RuntimeException('Connexion refusée'));
        $mock->shouldReceive('getEntityId')->zeroOrMoreTimes()->andReturn(null);
        $mock->shouldReceive('killSession')->zeroOrMoreTimes();
    });

    $this->mock(MercatorClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate')->zeroOrMoreTimes();
        $mock->shouldReceive('getBuildings')->zeroOrMoreTimes()->andReturn([]);
        $mock->shouldReceive('getSites')->zeroOrMoreTimes()->andReturn([]);
        $mock->shouldReceive('getAll')->zeroOrMoreTimes()->andReturn([]);
    });

    $this->artisan('glpi:sync')->assertExitCode(1);
});

it('accepte l\'option --dry-run sans écrire', function () {
    $this->mock(GlpiClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getItems')->andReturn([]);
        $mock->shouldReceive('getEntityId')->andReturn(null);
        $mock->shouldReceive('killSession');
    });

    $this->mock(MercatorClientInterface::class, function ($mock) {
        $mock->shouldReceive('authenticate');
        $mock->shouldReceive('getBuildings')->andReturn([]);
        $mock->shouldReceive('getSites')->andReturn([]);
        $mock->shouldReceive('getAll')->andReturn([]);
        $mock->shouldNotReceive('create');
        $mock->shouldNotReceive('update');
        $mock->shouldNotReceive('delete');
    });

    $this->artisan('glpi:sync --dry-run')->assertExitCode(0);
});
