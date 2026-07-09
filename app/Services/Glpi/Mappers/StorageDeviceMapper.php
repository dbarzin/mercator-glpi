<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiBay;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiLocationName;

class StorageDeviceMapper
{
    use AppendsUnmappedFields;
    use ResolvesGlpiBay;
    use ResolvesGlpiLocationName;

    /**
     * Mappe un NetworkEquipment GLPI (expand_dropdowns=1, with_networkports=1) vers
     * un payload Mercator storage-devices.
     *
     * Mercator StorageDevice : name, type, description, address_ip, site_id,
     * building_id, bay_id.
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
            'type'        => $this->nullable($item['networkequipmenttypes_id'] ?? null),
            'address_ip'  => $this->extractIp($item),
            'bay_id'      => $this->resolveBayId('NetworkEquipment', $item['id'], $context),
        ], fn($v) => $v !== null);

        // building_id/site_id toujours inclus (même null), cf. WorkstationMapper::map().
        $payload['building_id'] = $building['id'] ?? null;
        $payload['site_id'] = $building['site_id'] ?? null;

        return $payload;
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
