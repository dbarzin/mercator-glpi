<?php

use App\Services\Glpi\Contracts\SupportsBayResolution;
use App\Services\Glpi\Handlers\PhysicalServerSyncHandler;
use App\Services\Glpi\Mappers\LogicalServerMapper;
use App\Services\Glpi\Mappers\PhysicalServerMapper;
use App\Services\Glpi\Mappers\WorkstationMapper;

// ── Test 6 : PhysicalServerSyncHandler implémente SupportsBayResolution ──────
// Sans cette interface, GlpiSyncService ne chargera jamais item_rack_map /
// racks_map dans le contexte et bay_id ne sera jamais résolu.

it('PhysicalServerSyncHandler implémente SupportsBayResolution', function () {
    expect(new PhysicalServerSyncHandler(new PhysicalServerMapper(new WorkstationMapper)))
        ->toBeInstanceOf(SupportsBayResolution::class);
});

// ── Test 7 : PhysicalServerMapper ajoute bay_id quand le Computer est en baie ─

it('PhysicalServerMapper ajoute bay_id quand le serveur est dans un rack', function () {
    $context = [
        'buildings_map' => [],
        'sites_map' => [],
        'item_rack_map' => ['Computer_42' => 8],
        'racks_map' => ['8' => 99],
    ];

    $item = ['id' => 42, 'name' => 'SRV-01'];

    $payload = (new PhysicalServerMapper(new WorkstationMapper))->map($item, $context);

    expect($payload['bay_id'])->toBe(99);
});

// ── Test 8 : PhysicalServerMapper n'ajoute PAS bay_id si le Computer n'est ──
// dans aucun rack (resolveBayId retourne null → clé absente du payload).

it('PhysicalServerMapper n\'ajoute pas bay_id quand le serveur n\'est dans aucun rack', function () {
    $context = [
        'buildings_map' => [],
        'sites_map' => [],
        'item_rack_map' => [],
        'racks_map' => [],
    ];

    $item = ['id' => 42, 'name' => 'SRV-01'];

    $payload = (new PhysicalServerMapper(new WorkstationMapper))->map($item, $context);

    expect(array_key_exists('bay_id', $payload))->toBeFalse();
});

// ── Test 9 : Régression — WorkstationMapper et LogicalServerMapper ne ────────
// produisent jamais bay_id, même si le contexte contient item_rack_map /
// racks_map (contexte qui leur serait absent en production, mais simulé ici
// pour garantir l'isolation).

it('WorkstationMapper ne produit jamais bay_id', function () {
    $context = [
        'buildings_map' => [],
        'sites_map' => [],
        'item_rack_map' => ['Computer_42' => 8],
        'racks_map' => ['8' => 99],
    ];

    $payload = (new WorkstationMapper)->map(['id' => 42, 'name' => 'WS-01'], $context);

    expect(array_key_exists('bay_id', $payload))->toBeFalse();
});

it('LogicalServerMapper ne produit jamais bay_id', function () {
    $context = [
        'buildings_map' => [],
        'sites_map' => [],
        'item_rack_map' => ['Computer_42' => 8],
        'racks_map' => ['8' => 99],
    ];

    $payload = (new LogicalServerMapper(new WorkstationMapper))->map(['id' => 42, 'name' => 'VM-01'], $context);

    expect(array_key_exists('bay_id', $payload))->toBeFalse();
});

// ── Test 10 : PhysicalServerMapper hérite du CPU/OS de WorkstationMapper ────
// Régression pour l'issue #11 (CPU non remonté) : PhysicalServerMapper délègue
// entièrement à WorkstationMapper, donc le correctif doit s'appliquer sans
// modification supplémentaire ici.

it('PhysicalServerMapper mappe le CPU et l\'OS via WorkstationMapper', function () {
    $payload = (new PhysicalServerMapper(new WorkstationMapper))->map(
        glpiComputer(),
        ['buildings_map' => [], 'sites_map' => [], 'item_rack_map' => [], 'racks_map' => []]
    );

    expect($payload['cpu'])->toBe('Intel Core i7-1165G7 — 2800 MHz — 4 cœurs');
    expect($payload['operating_system'])->toBe('Windows 11 Pro — 23H2');
});
