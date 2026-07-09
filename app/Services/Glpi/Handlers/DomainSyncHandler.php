<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SupportsExplicitEntityFilter;
use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Handlers\Concerns\MatchesGlpiDropdownType;
use App\Services\Glpi\Mappers\DomainMapper;

class DomainSyncHandler implements SupportsExplicitEntityFilter, SyncHandler
{
    use MatchesGlpiDropdownType;

    public function __construct(private readonly DomainMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Domain';
    }

    public function mercatorEndpoint(): string
    {
        return 'domains';
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
        // Supprime (ou marque [OLD]) les items Mercator absents de GLPI, cf. issue #13.
        return true;
    }

    public function filterItem(array $item): bool
    {
        $allowed = config('glpi.domain_types', []);

        // Vide = tous les Domain sont acceptés (comportement historique)
        if (empty($allowed)) {
            return true;
        }

        return $this->matchesType($item['domaintypes_id'] ?? null, $allowed);
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
