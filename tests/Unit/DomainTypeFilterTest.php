<?php

use App\Services\Glpi\Handlers\DomainSyncHandler;
use App\Services\Glpi\Mappers\DomainMapper;

// ── Tests filtrage par type de domaine (GLPI_DOMAIN_TYPES) ────────────────────

function domainWithType(mixed $typeValue): array
{
    return ['domaintypes_id' => $typeValue, 'name' => 'example.com'];
}

function makeDomainHandler(): DomainSyncHandler
{
    return new DomainSyncHandler(new DomainMapper);
}

it('accepte tous les types si la config est vide', function () {
    config(['glpi.domain_types' => []]);

    $handler = makeDomainHandler();

    expect($handler->filterItem(domainWithType('Interne')))->toBeTrue();
    expect($handler->filterItem(domainWithType('Externe')))->toBeTrue();
    expect($handler->filterItem(domainWithType(0)))->toBeTrue();
});

it('filtre par nom de type si configuré', function () {
    config(['glpi.domain_types' => ['Interne']]);

    $handler = makeDomainHandler();

    expect($handler->filterItem(domainWithType('Interne')))->toBeTrue();
    expect($handler->filterItem(domainWithType('Externe')))->toBeFalse();
});

it('filtre par ID numérique', function () {
    config(['glpi.domain_types' => ['1']]);

    $handler = makeDomainHandler();

    expect($handler->filterItem(domainWithType('1')))->toBeTrue();
    expect($handler->filterItem(domainWithType('2')))->toBeFalse();
});

it('est insensible à la casse', function () {
    config(['glpi.domain_types' => ['Interne']]);

    $handler = makeDomainHandler();

    expect($handler->filterItem(domainWithType('INTERNE')))->toBeTrue();
    expect($handler->filterItem(domainWithType('interne')))->toBeTrue();
});
