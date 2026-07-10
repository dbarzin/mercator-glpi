<?php

use App\Services\Glpi\Mappers\DomainMapper;

function glpiDomain(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_domains.json'), true)[0],
        $overrides
    );
}

it('mappe le nom du domaine', function () {
    $result = (new DomainMapper)->map(glpiDomain(), []);

    expect($result['name'])->toBe('example.com');
});

it('mappe le type depuis domaintypes_id', function () {
    $result = (new DomainMapper)->map(glpiDomain(), []);

    expect($result['type'])->toBe('Interne');
});

it('mappe la date d\'expiration', function () {
    $result = (new DomainMapper)->map(glpiDomain(), []);

    expect($result['expiration_date'])->toBe('2027-01-15 00:00:00');
});

it('mappe le commentaire dans la description', function () {
    $result = (new DomainMapper)->map(glpiDomain(['id' => 9, 'comment' => 'Domaine secondaire']), []);

    expect($result['description'])->toStartWith('Domaine secondaire');
});

it('sérialise les champs GLPI non mappés dans la description', function () {
    $result = (new DomainMapper)->map(glpiDomain(), []);

    expect($result['description'])->toContain('"date_domaincreation" : "2015-01-15 00:00:00"');
});

it('ne porte pas de statut (Domain n\'a pas de states_id côté GLPI)', function () {
    $result = (new DomainMapper)->map(glpiDomain(), []);

    expect($result)->not->toHaveKey('status');
});

it('renseigne update_source à GLPI', function () {
    $result = (new DomainMapper)->map(glpiDomain(), []);

    expect($result['update_source'])->toBe('GLPI');
});
