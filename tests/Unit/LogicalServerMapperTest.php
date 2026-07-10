<?php

use App\Services\Glpi\Mappers\LogicalServerMapper;
use App\Services\Glpi\Mappers\WorkstationMapper;

// ── LogicalServerMapper hérite du CPU/OS de WorkstationMapper ───────────────
// Régression pour l'issue #11 (CPU non remonté) : LogicalServerMapper délègue
// entièrement à WorkstationMapper, donc le correctif doit s'appliquer sans
// modification supplémentaire ici.

it('mappe le CPU et l\'OS via WorkstationMapper', function () {
    $payload = (new LogicalServerMapper(new WorkstationMapper))->map(
        glpiComputer(),
        ['buildings_map' => [], 'sites_map' => []]
    );

    expect($payload['cpu'])->toBe('Intel Core i7-1165G7 — 2800 MHz — 4 cœurs');
    expect($payload['operating_system'])->toBe('Windows 11 Pro — 23H2');
});
