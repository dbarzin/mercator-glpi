<?php

use App\Services\Glpi\Mappers\DatabaseMapper;

it('mappe le type depuis context[database_types] résolu par id', function () {
    $mapper = new DatabaseMapper;

    $payload = $mapper->map(
        ['id' => 42, 'name' => 'prod-db'],
        ['database_types' => [42 => 'MariaDB']],
    );

    expect($payload['type'])->toBe('MariaDB');
    expect($payload['name'])->toBe('prod-db');
    expect($payload['update_source'])->toBe('GLPI');
});

it('omet type quand l\'id est absent du context[database_types]', function () {
    $mapper = new DatabaseMapper;

    $payload = $mapper->map(
        ['id' => 42, 'name' => 'prod-db'],
        ['database_types' => [99 => 'MariaDB']],
    );

    expect($payload)->not->toHaveKey('type');
});

it('omet type quand la valeur résolue est une chaîne vide', function () {
    $mapper = new DatabaseMapper;

    $payload = $mapper->map(
        ['id' => 42, 'name' => 'prod-db'],
        ['database_types' => [42 => '']],
    );

    expect($payload)->not->toHaveKey('type');
});

it('mappe toujours name + update_source sans context de type (rétrocompatibilité)', function () {
    $mapper = new DatabaseMapper;

    $payload = $mapper->map(['id' => 42, 'name' => 'prod-db'], []);

    expect($payload)->toBe(['name' => 'prod-db', 'update_source' => 'GLPI']);
});

it('complète avec deux "_" un nom d\'un seul caractère', function () {
    $mapper = new DatabaseMapper;

    $payload = $mapper->map(['id' => 42, 'name' => 'x'], []);

    expect($payload['name'])->toBe('x__');
});

it('complète avec un "_" un nom de deux caractères', function () {
    $mapper = new DatabaseMapper;

    $payload = $mapper->map(['id' => 42, 'name' => 'db'], []);

    expect($payload['name'])->toBe('db_');
});

it('ne modifie pas un nom de 3 caractères ou plus', function () {
    $mapper = new DatabaseMapper;

    $payload = $mapper->map(['id' => 42, 'name' => 'abc'], []);

    expect($payload['name'])->toBe('abc');
});
