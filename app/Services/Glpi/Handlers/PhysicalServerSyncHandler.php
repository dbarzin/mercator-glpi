<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SupportsBayResolution;
use App\Services\Glpi\Contracts\SupportsGlpiItemDetail;
use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Handlers\Concerns\MatchesGlpiDropdownType;
use App\Services\Glpi\Mappers\PhysicalServerMapper;

class PhysicalServerSyncHandler implements SyncHandler, SupportsGlpiItemDetail, SupportsBayResolution
{
    use MatchesGlpiDropdownType;

    public function __construct(private readonly PhysicalServerMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Computer';
    }

    public function mercatorEndpoint(): string
    {
        return 'physical-servers';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range'            => '0-999',
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
            'expand_dropdowns'  => 1,
            'with_networkports' => 1,
            'with_devices'      => 1,
            'with_disks'        => 1,
            'with_infocoms'     => 1,
        ];
    }

    public function processOrphans(): bool
    {
        return false;
    }

    /**
     * Inclut uniquement les Computer dont le computertypes_id est dans GLPI_COMPUTER_TYPES_PHYSICAL_SERVERS.
     * Si la config est vide, aucun Computer n'est synchronisé (sécurité : opt-in explicite).
     */
    public function filterItem(array $item): bool
    {
        $allowed = config('glpi.computer_types.physical_servers', []);

        if (empty($allowed)) {
            return false;
        }

        return $this->matchesType($item['computertypes_id'] ?? null, $allowed);
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
