<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\RackMapper;

class RackSyncHandler implements SyncHandler
{
    public function __construct(private readonly RackMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Rack';
    }

    public function mercatorEndpoint(): string
    {
        return 'bays';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range'            => '0-999',
            'expand_dropdowns' => 1,
        ];
    }

    public function processOrphans(): bool
    {
        // Supprime (ou marque [OLD]) les items Mercator absents de GLPI, cf. issue #13.
        return true;
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
