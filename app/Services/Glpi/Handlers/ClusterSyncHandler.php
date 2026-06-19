<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\ClusterMapper;

class ClusterSyncHandler implements SyncHandler
{
    public function __construct(private readonly ClusterMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Cluster';
    }

    public function mercatorEndpoint(): string
    {
        return 'clusters';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range'            => '0-999',
            'expand_dropdowns' => 1,
            'with_items'       => 1,
        ];
    }

    public function processOrphans(): bool
    {
        return false;
    }

    public function filterItem(array $item): bool
    {
        return true;
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
