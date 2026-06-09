// app/Services/Glpi/Handlers/LocationSyncHandler.php
<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\LocationMapper;

class LocationSyncHandler implements SyncHandler
{
    public function __construct(private LocationMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Location';
    }

    public function mercatorEndpoint(): string
    {
        return 'locations';
    }

    public function processOrphans(): bool
    {
        return false;
    }

    public function glpiQueryParams(): array
    {
        return [
            'range' => '0-9999',
        ];
    }

    public function map(array $item, array $context): array
    {
        return $this->mapper->map($item, $context);
    }
}