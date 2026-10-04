<?php

namespace App\Services\Mercator\Contracts;

interface MercatorClientInterface
{
    public function authenticate(): void;
    public function getBuildings(): array;
    public function getSites(): array;
    public function getAll(string $endpoint): array;
    public function create(string $endpoint, array $payload): array;
    public function update(string $endpoint, int $id, array $payload): array;
    public function delete(string $endpoint, int $id): void;

    /**
     * true : le périmètre existe ; false : inconnu (404) ; null : vérification
     * impossible (ex. compte API sans permission "configure").
     */
    public function perimeterExists(int $id): ?bool;
}
