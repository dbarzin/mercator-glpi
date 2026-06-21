<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiLocationName;

class PhoneMapper
{
    use AppendsUnmappedFields;
    use ResolvesGlpiLocationName;
    /**
     * Mappe un Phone GLPI (expand_dropdowns=1) vers un payload Mercator.
     *
     * @param  array  $item     Phone GLPI brut
     * @param  array  $context  ['buildings_map' => ['nom salle (lower)' => ['id' => X, 'site_id' => Y]]]
     */
    public function map(array $item, array $context): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $sitesMap     = $context['sites_map'] ?? [];
        $building     = $this->resolveBuilding($item['locations_id'] ?? null, $buildingsMap, $sitesMap);

        return array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, [
                'phonetypes_id', 'manufacturers_id', 'phonemodels_id', 'locations_id',
            ]),
            'type'        => $this->nullable($item['phonetypes_id'] ?? null),
            'vendor'      => $this->nullable($item['manufacturers_id'] ?? null),
            'product'     => $this->nullable($item['phonemodels_id'] ?? null),
            'building_id' => $building['id'] ?? null,
            'site_id'     => $building['site_id'] ?? null,
            'address_ip'  => $this->extractIp($item),
        ], fn($v) => $v !== null);
    }

    // -------------------------------------------------------------------------
    // Résolution building_id / site_id
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Réseau
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    private function nullable(mixed $value): mixed
    {
        if ($value === null || $value === 0 || $value === '0' || $value === '') {
            return null;
        }

        return $value;
    }
}
