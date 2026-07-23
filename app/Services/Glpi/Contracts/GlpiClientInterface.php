<?php

namespace App\Services\Glpi\Contracts;

interface GlpiClientInterface
{
    public function authenticate(): void;

    public function killSession(): void;

    public function getItem(string $itemType, int $id, array $params = []): array;

    public function getItems(string $itemType, array $extraParams = []): array;

    public function getSubItems(string $itemType, int $id, string $subItemType, array $params = []): array;

    public function setEntityId(?int $entityId): void;

    public function getEntityId(): ?int;

    /**
     * Exécute $callback avec la restriction d'entité de session temporairement levée
     * ("toutes entités"), puis la restaure. Cf. GlpiClient::withoutEntityRestriction().
     */
    public function withoutEntityRestriction(callable $callback): mixed;
}
