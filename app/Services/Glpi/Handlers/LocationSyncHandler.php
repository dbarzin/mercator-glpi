<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SupportsExplicitEntityFilter;
use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\LocationMapper;

class LocationSyncHandler implements SupportsExplicitEntityFilter, SyncHandler
{
    public function __construct(private readonly LocationMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Location';
    }

    public function mercatorEndpoint(): string
    {
        return 'buildings';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range' => '0-999',
            'expand_dropdowns' => 1,
        ];
    }

    public function processOrphans(): bool
    {
        // Un Building supprimé côté GLPI doit être supprimé (ou marqué [OLD] s'il n'a
        // pas été créé par ce connecteur) côté Mercator (cf. issue #13).
        return true;
    }

    /**
     * Les Location racines (sans parent) deviennent un Site (cf. SiteSyncHandler) :
     * seules les Location non racines passent par ce handler pour devenir un Building.
     */
    public function filterItem(array $item): bool
    {
        $parent = $item['locations_id'] ?? 0;

        return ! ($parent === 0 || $parent === '0' || $parent === null || $parent === '');
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
