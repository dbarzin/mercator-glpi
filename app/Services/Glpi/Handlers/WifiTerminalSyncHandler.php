<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Handlers\Concerns\MatchesGlpiDropdownType;
use App\Services\Glpi\Mappers\WifiTerminalMapper;

class WifiTerminalSyncHandler implements SyncHandler
{
    use MatchesGlpiDropdownType;

    public function __construct(private readonly WifiTerminalMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'NetworkEquipment';
    }

    public function mercatorEndpoint(): string
    {
        return 'wifi-terminals';
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
        // Supprime (ou marque [OLD]) les items Mercator absents de GLPI, cf. issue #13.
        return true;
    }

    /**
     * Inclut uniquement les NetworkEquipment dont le networkequipmenttypes_id est
     * dans GLPI_NETWORK_DEVICE_TYPES_WIFI_TERMINALS. Vide = aucun (opt-in explicite).
     */
    public function filterItem(array $item): bool
    {
        $allowed = config('glpi.network_device_types.wifi_terminals', []);

        if (empty($allowed)) {
            return false;
        }

        return $this->matchesType($item['networkequipmenttypes_id'] ?? null, $allowed);
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
