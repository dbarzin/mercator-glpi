<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SupportsGlpiItemDetail;
use App\Services\Glpi\Contracts\SupportsGlpiOperatingSystem;
use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Handlers\Concerns\MatchesGlpiDropdownType;
use App\Services\Glpi\Mappers\WorkstationMapper;

class WorkstationSyncHandler implements SupportsGlpiItemDetail, SupportsGlpiOperatingSystem, SyncHandler
{
    use MatchesGlpiDropdownType;

    public function __construct(private readonly WorkstationMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Computer';
    }

    public function mercatorEndpoint(): string
    {
        return 'workstations';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range' => '0-999',
            'expand_dropdowns' => 1,
        ];
    }

    /**
     * with_networkports/with_devices/with_disks/with_infocoms ne sont pas fiables
     * sur la requête de collection (GLPI peut renvoyer 0 item) : ils sont demandés
     * item par item après filtrage.
     */
    public function glpiDetailParams(): array
    {
        return [
            'expand_dropdowns' => 1,
            'with_networkports' => 1,
            'with_devices' => 1,
            'with_disks' => 1,
            'with_infocoms' => 1,
        ];
    }

    public function processOrphans(): bool
    {
        return false;
    }

    public function filterItem(array $item): bool
    {
        $allowed = config('glpi.computer_types.workstations', []);

        // Vide = tous les Computer sont acceptés comme workstations
        if (empty($allowed)) {
            return true;
        }

        return $this->matchesType($item['computertypes_id'] ?? null, $allowed);
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
