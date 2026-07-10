<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\DomainSyncHandler;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Glpi\Mappers\DomainMapper;
use App\Services\Glpi\Mappers\WorkstationMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Mockery\MockInterface;

// ── Filtrage explicite par entité (SupportsExplicitEntityFilter) ─────────────
// GLPI ne restreint pas toujours les Domain à l'entité active de la session
// (contrairement aux autres itemtypes) : GlpiSyncService retente un filtrage
// explicite côté connecteur pour les handlers qui implémentent ce marqueur.

function domainsAcrossEntities(): array
{
    return [
        ['id' => 1, 'name' => 'siege.example.com', 'domaintypes_id' => 'Interne', 'entities_id' => 'Entité racine > Filiale A'],
        ['id' => 2, 'name' => 'sous-site.example.com', 'domaintypes_id' => 'Interne', 'entities_id' => 'Entité racine > Filiale A > Site 1'],
        ['id' => 3, 'name' => 'autre-filiale.example.com', 'domaintypes_id' => 'Interne', 'entities_id' => 'Entité racine > Filiale B'],
        ['id' => 4, 'name' => 'racine.example.com', 'domaintypes_id' => 'Interne', 'entities_id' => 'Entité racine'],
    ];
}

function glpiMockForEntityFilter(array $items, ?int $entityId, ?array $entity): MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);
    $mock->shouldReceive('getItems')->andReturn($items);
    $mock->shouldReceive('getSubItems')->andReturn([]);
    $mock->shouldReceive('getEntityId')->andReturn($entityId);

    if ($entityId !== null) {
        $mock->shouldReceive('getItem')->with('Entity', $entityId)
            ->andReturn($entity ?? []);
    }
    $mock->shouldReceive('getItem')->andReturn([]);

    return $mock;
}

function mercatorMockForEntityFilter(): MockInterface
{
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getBuildings')->andReturn([]);
    $mock->shouldReceive('getSites')->andReturn([]);
    $mock->shouldReceive('getAll')->andReturn([]);

    return $mock;
}

it('ne conserve que les domaines de l\'entité configurée et de ses sous-entités', function () {
    $created = [];

    $mercator = mercatorMockForEntityFilter();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 100];
        });

    $glpi = glpiMockForEntityFilter(
        domainsAcrossEntities(),
        entityId: 2,
        entity: ['completename' => 'Entité racine > Filiale A'],
    );

    (new GlpiSyncService)->sync($glpi, $mercator, new DomainSyncHandler(new DomainMapper));

    expect($created)->toContain('siege.example.com');       // entité exacte
    expect($created)->toContain('sous-site.example.com');   // sous-entité
    expect($created)->not->toContain('autre-filiale.example.com'); // entité différente
    expect($created)->not->toContain('racine.example.com'); // entité parente, pas fille
});

it('ne filtre pas si aucune entité n\'est configurée', function () {
    $created = [];

    $mercator = mercatorMockForEntityFilter();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 100];
        });

    $glpi = glpiMockForEntityFilter(domainsAcrossEntities(), entityId: null, entity: null);

    (new GlpiSyncService)->sync($glpi, $mercator, new DomainSyncHandler(new DomainMapper));

    expect($created)->toHaveCount(4);
});

it('ne filtre pas si l\'entité configurée ne résout à rien (getItem vide)', function () {
    $created = [];

    $mercator = mercatorMockForEntityFilter();
    $mercator->shouldReceive('create')
        ->andReturnUsing(function (string $ep, array $payload) use (&$created) {
            $created[] = $payload['name'];

            return ['id' => 100];
        });

    $glpi = glpiMockForEntityFilter(domainsAcrossEntities(), entityId: 2, entity: []);

    (new GlpiSyncService)->sync($glpi, $mercator, new DomainSyncHandler(new DomainMapper));

    expect($created)->toHaveCount(4);
});

it('n\'appelle jamais getEntityId pour un handler qui n\'implémente pas SupportsExplicitEntityFilter', function () {
    $mock = Mockery::mock(GlpiClientInterface::class);
    $mock->shouldReceive('getItems')->andReturn([]);
    $mock->shouldReceive('getItem')->andReturn([]);
    $mock->shouldReceive('getSubItems')->andReturn([]);
    // Pas de shouldReceive('getEntityId') : un appel ferait échouer le test (BadMethodCallException).

    (new GlpiSyncService)->sync(
        $mock,
        mercatorMockForEntityFilter(),
        new WorkstationSyncHandler(new WorkstationMapper),
    );

    expect(true)->toBeTrue();
});
