<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SupportsBayResolution;
use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Handlers\Concerns\MatchesGlpiDropdownType;
use App\Services\Glpi\Mappers\NetworkDeviceMapper;

class NetworkDeviceSyncHandler implements SyncHandler, SupportsBayResolution
{
    use MatchesGlpiDropdownType;

    public function __construct(private readonly NetworkDeviceMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'NetworkEquipment';
    }

    public function mercatorEndpoint(): string
    {
        return 'physical-switches';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range'             => '0-999',
            'expand_dropdowns'  => 1,
            'with_networkports' => 1,
        ];
    }

    public function processOrphans(): bool
    {
        return false;
    }

    /**
     * Vide = comportement historique (tous les NetworkEquipment deviennent des
     * physical-switches). Sinon, filtre par GLPI_NETWORK_DEVICE_TYPES_SWITCHES,
     * de façon symétrique aux autres sous-types de NetworkEquipment (routers,
     * wifi_terminals, physical_security_devices, storage_devices).
     */
    public function filterItem(array $item): bool
    {
        $allowed = config('glpi.network_device_types.switches', []);

        if (empty($allowed)) {
            return true;
        }

        return $this->matchesType($item['networkequipmenttypes_id'] ?? null, $allowed);
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
