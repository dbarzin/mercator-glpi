<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\SiteMapper;

class SiteSyncHandler implements SyncHandler
{
    public function __construct(private readonly SiteMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Location';
    }

    public function mercatorEndpoint(): string
    {
        return 'sites';
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

    /**
     * Seules les Location racines (sans parent) deviennent un Site Mercator.
     */
    public function filterItem(array $item): bool
    {
        $parent = $item['locations_id'] ?? 0;

        return $parent === 0 || $parent === '0' || $parent === null || $parent === '';
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem);
    }
}
