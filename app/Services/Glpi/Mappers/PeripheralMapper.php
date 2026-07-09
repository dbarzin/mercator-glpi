<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiLocationName;

class PeripheralMapper
{
    use AppendsUnmappedFields;
    use ResolvesGlpiLocationName;
    /**
     * Mappe un Peripheral GLPI (expand_dropdowns=1) vers un payload Mercator.
     *
     * @param  array  $item     Peripheral GLPI brut
     * @param  array  $context  ['buildings_map' => ['nom salle (lower)' => ['id' => X, 'site_id' => Y]]]
     */
    public function map(array $item, array $context): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $sitesMap     = $context['sites_map'] ?? [];
        $building     = $this->resolveBuilding($item['locations_id'] ?? null, $buildingsMap, $sitesMap);

        $payload = array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, [
                'peripheraltypes_id', 'manufacturers_id', 'peripheralmodels_id', 'users_id_tech', 'locations_id',
            ]),
            'type'        => $this->nullable($item['peripheraltypes_id'] ?? null),
            'vendor'      => $this->nullable($item['manufacturers_id'] ?? null),
            'product'     => $this->nullable($item['peripheralmodels_id'] ?? null),
            'responsible' => $this->nullable($item['users_id_tech'] ?? null),
            'address_ip'  => $this->extractIp($item),
        ], fn($v) => $v !== null);

        // building_id/site_id toujours inclus (même null), cf. WorkstationMapper::map().
        $payload['building_id'] = $building['id'] ?? null;
        $payload['site_id'] = $building['site_id'] ?? null;

        return $payload;
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
