<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiLocationName;

class WifiTerminalMapper
{
    use AppendsUnmappedFields;
    use ResolvesGlpiLocationName;

    /**
     * Mappe un NetworkEquipment GLPI (expand_dropdowns=1, with_networkports=1) vers
     * un payload Mercator wifi-terminals.
     *
     * Mercator WifiTerminal : name, description, type, address_ip, vendor, product,
     * site_id, building_id.
     *
     * @param  array  $item     NetworkEquipment GLPI brut
     * @param  array  $context  ['buildings_map' => [...]]
     */
    public function map(array $item, array $context): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $sitesMap     = $context['sites_map'] ?? [];
        $building     = $this->resolveBuilding($item['locations_id'] ?? null, $buildingsMap, $sitesMap);

        return array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, [
                'networkequipmenttypes_id', 'manufacturers_id', 'networkequipmentmodels_id', 'locations_id',
            ]),
            'type'        => $this->nullable($item['networkequipmenttypes_id'] ?? null),
            'vendor'      => $this->nullable($item['manufacturers_id'] ?? null),
            'product'     => $this->nullable($item['networkequipmentmodels_id'] ?? null),
            'building_id' => $building['id'] ?? null,
            'site_id'     => $building['site_id'] ?? null,
            'address_ip'  => $this->extractIp($item),
        ], fn($v) => $v !== null);
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

    private function extractIp(array $item): ?string
    {
        $ports = $item['_networkports'] ?? [];

        foreach ([...$ports['NetworkPortEthernet'] ?? [], ...$ports['NetworkPortWifi'] ?? []] as $port) {
            foreach ($port['NetworkName']['IPAddress'] ?? [] as $addr) {
                $ip = $addr['name'] ?? '';
                if ($ip && $ip !== '0.0.0.0' && ! str_starts_with($ip, '127.')) {
                    return $ip;
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
