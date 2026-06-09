<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\NetworkDeviceMapper;

class NetworkDeviceSyncHandler implements SyncHandler
{
    public function __construct(private NetworkDeviceMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'NetworkEquipment';
    }

    public function mercatorEndpoint(): string
    {
        return 'network_devices';
    }

    public function processOrphans(): bool
    {
        return false; // Ne pas supprimer les actifs absents de GLPI
    }

    public function glpiQueryParams(): array
    {
        return [
            'range' => '0-9999',
            'expand_dropdowns' => 1,
            'with_connections' => 1,
        ];
    }

    public function map(array $item, array $context): array
    {
        return $this->mapper->map($item, $context);
    }
}