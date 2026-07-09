<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiBay;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiLocationName;

class NetworkDeviceMapper
{
    use AppendsUnmappedFields;
    use ResolvesGlpiBay;
    use ResolvesGlpiLocationName;
    /**
     * Mappe un NetworkEquipment GLPI (expand_dropdowns=1) vers un payload Mercator physical-switches.
     *
     * Mercator PhysicalSwitch : name, type, description, site_id, building_id, bay_id.
     *
     * @param  array  $item     NetworkEquipment GLPI brut
     * @param  array  $context  ['buildings_map' => [...], 'item_rack_map' => [...], 'racks_map' => [...]]
     */
    public function map(array $item, array $context): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $sitesMap     = $context['sites_map'] ?? [];
        $building     = $this->resolveBuilding($item['locations_id'] ?? null, $buildingsMap, $sitesMap);

        $payload = array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, [
                'networkequipmenttypes_id', 'manufacturers_id', 'networkequipmentmodels_id', 'locations_id',
            ]),
            'type'        => $this->mapType($item['networkequipmenttypes_id'] ?? null),
            'vendor'      => $this->nullable($item['manufacturers_id'] ?? null),
            'product'     => $this->nullable($item['networkequipmentmodels_id'] ?? null),
            'bay_id'      => $this->resolveBayId('NetworkEquipment', $item['id'], $context),
        ], fn($v) => $v !== null);

        // building_id/site_id toujours inclus (même null), cf. WorkstationMapper::map().
        $payload['building_id'] = $building['id'] ?? null;
        $payload['site_id'] = $building['site_id'] ?? null;

        return $payload;
    }

    /**
     * Normalise le type d'équipement réseau.
     *
     * Accepte indifféremment une chaîne (expand_dropdowns=1) ou un entier (ID brut GLPI).
     */
    private function mapType(int|string|null $typeId): ?string
    {
        if ($typeId === null || $typeId === 0 || $typeId === '0' || $typeId === '') {
            return null;
        }

        if (is_string($typeId)) {
            return $typeId;
        }

        return match ($typeId) {
            1 => 'Router',
            2 => 'Switch',
            3 => 'Firewall',
            default => null,
        };
    }

    private function resolveBuilding(mixed $locationName, array $buildingsMap, array $sitesMap = []): ?array
    {
        $leafName = $this->locationLeafName($locationName);

        if ($leafName === null) {
            return null;
        }

        $key = strtolower($leafName);

        if (isset($buildingsMap[$key])) {
            return $buildingsMap[$key];
        }

        if (isset($sitesMap[$key])) {
            return ['id' => null, 'site_id' => $sitesMap[$key]];
        }

        return null;
    }

    /**
     * Extrait la première adresse IP depuis les ports réseau GLPI (_networkports).
     */
    public function getFirstIpAddress(array $item): ?string
    {
        foreach ($item['_networkports'] ?? [] as $ports) {
            foreach ($ports as $port) {
                $ip = $port['NetworkName']['IPAddress'][0]['name'] ?? null;
                if ($ip) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Extrait la première adresse MAC depuis les ports réseau GLPI (_networkports).
     */
    public function getFirstMacAddress(array $item): ?string
    {
        foreach ($item['_networkports'] ?? [] as $ports) {
            foreach ($ports as $port) {
                $mac = $port['mac'] ?? null;
                if ($mac) {
                    return strtoupper($mac);
                }
            }
        }

        return null;
    }

    private function nullable(mixed $value): mixed
    {
        return ($value === null || $value === 0 || $value === '0' || $value === '') ? null : $value;
    }
}
