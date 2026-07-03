<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiLocationName;

class WorkstationMapper
{
    use AppendsUnmappedFields;
    use ResolvesGlpiLocationName;

    /**
     * Mappe un Computer GLPI (expand_dropdowns=1) vers un payload Workstation Mercator.
     *
     * @param  array  $item  Computer GLPI brut
     * @param  array  $context  ['buildings_map' => ['nom salle (lower)' => id]]
     */
    public function map(array $item, array $context): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $sitesMap = $context['sites_map'] ?? [];
        $building = $this->resolveBuilding($item['locations_id'] ?? null, $buildingsMap, $sitesMap);

        return array_filter([
            'name' => $item['name'],
            'description' => $this->buildDescription($item, [
                'computertypes_id', 'manufacturers_id', 'computermodels_id', 'serial',
                'operatingsystems_id', 'states_id', 'users_id', 'locations_id', 'ram', 'date_last_boot',
            ]),
            'type' => $this->nullable($item['computertypes_id'] ?? null),
            'manufacturer' => $this->nullable($item['manufacturers_id'] ?? null),
            'model' => $this->nullable($item['computermodels_id'] ?? null),
            'serial_number' => $this->nullable($item['serial'] ?? null),
            'operating_system' => $this->extractOperatingSystem($item),
            'status' => $this->nullable($item['states_id'] ?? null),
            'other_user' => $this->nullable($item['users_id'] ?? null),
            'building_id' => $building['id'] ?? null,
            'site_id' => $building['site_id'] ?? null,
            'address_ip' => $this->extractIp($item),
            'mac_address' => $this->extractMac($item),
            'network_port_type' => $this->extractPortType($item),
            'cpu' => $this->extractCpu($item),
            'memory' => $this->formatRam($item['ram'] ?? null),
            'disk' => $this->extractDiskTotal($item),
            'last_inventory_date' => $this->parseDate($item['date_last_boot'] ?? null),
            'purchase_date' => $this->parseDate($item['_infocoms']['buy_date'] ?? null),
            'warranty_start_date' => $this->parseDate($item['_infocoms']['order_date'] ?? null),
            'warranty_end_date' => $this->parseDate($item['_infocoms']['warranty_expiration'] ?? null),
            'warranty_period' => $this->formatWarrantyPeriod($item['_infocoms']['warranty_duration'] ?? null),
            'fin_value' => isset($item['_infocoms']['value'])
                ? (float) $item['_infocoms']['value']
                : null,
            'update_source' => 'GLPI',
        ], fn ($v) => $v !== null);
    }

    // -------------------------------------------------------------------------
    // Résolution building_id
    // -------------------------------------------------------------------------

    /**
     * Résout le building (ou, à défaut, le site) depuis le nom de la salle GLPI.
     *
     * La Location GLPI d'un Computer peut désigner soit un Building (salle, étage…),
     * soit directement une Location racine devenue un Site (cf. LocationMapper) :
     * on cherche donc d'abord dans buildings_map, puis dans sites_map.
     *
     * Retourne ['id' => int|null, 'site_id' => int|null] ou null si non trouvé.
     */
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
    // Ports réseau
    // -------------------------------------------------------------------------

    private function extractIp(array $item): ?string
    {
        foreach ($this->iteratePorts($item) as $port) {
            foreach ($port['NetworkName']['IPAddress'] ?? [] as $addr) {
                $ip = $addr['name'] ?? '';
                if ($ip && $ip !== '0.0.0.0' && ! str_starts_with($ip, '127.')) {
                    return $ip;
                }
            }
        }

        return null;
    }

    private function extractMac(array $item): ?string
    {
        foreach ($this->iteratePorts($item) as $port) {
            if (! empty($port['mac'])) {
                return strtoupper($port['mac']);
            }
        }

        return null;
    }

    private function extractPortType(array $item): ?string
    {
        $ports = $item['_networkports'] ?? [];

        if (! empty($ports['NetworkPortEthernet'])) {
            return 'Ethernet';
        }
        if (! empty($ports['NetworkPortWifi'])) {
            return 'Wifi';
        }

        return null;
    }

    /**
     * Itère sur tous les ports réseau (Ethernet en priorité, puis Wifi).
     */
    private function iteratePorts(array $item): iterable
    {
        $ports = $item['_networkports'] ?? [];

        yield from $ports['NetworkPortEthernet'] ?? [];
        yield from $ports['NetworkPortWifi'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Système d'exploitation (Item_OperatingSystem, cf. GlpiSyncService::sync())
    // -------------------------------------------------------------------------

    private function extractOperatingSystem(array $item): ?string
    {
        $os = $item['_os'] ?? null;

        if (empty($os)) {
            return null;
        }

        $name = $this->nullable($os['operatingsystems_id'] ?? null);

        if ($name === null) {
            return null;
        }

        $parts = [$name];

        $version = $this->nullable($os['operatingsystemversions_id'] ?? null);
        if ($version !== null) {
            $parts[] = $version;
        }

        return implode(' — ', $parts);
    }

    // -------------------------------------------------------------------------
    // CPU
    // -------------------------------------------------------------------------

    private function extractCpu(array $item): ?string
    {
        $processors = $item['_devices']['Item_DeviceProcessor'] ?? [];

        if (empty($processors)) {
            return null;
        }

        $first = reset($processors);
        $designation = $first['deviceprocessors_id'] ?? '';

        if (! $designation) {
            return null;
        }

        $parts = [$designation];

        if (! empty($first['frequency'])) {
            $parts[] = $first['frequency'].' MHz';
        }
        if (! empty($first['nbcores'])) {
            $parts[] = $first['nbcores'].' cœurs';
        }

        return implode(' — ', $parts);
    }

    // -------------------------------------------------------------------------
    // Disque (somme de toutes les partitions, en Mo)
    // -------------------------------------------------------------------------

    private function extractDiskTotal(array $item): ?int
    {
        $disks = $item['_disks'] ?? [];

        if (empty($disks)) {
            return null;
        }

        $total = array_sum(array_column($disks, 'totalsize'));

        return $total > 0 ? (int) $total : null;
    }

    // -------------------------------------------------------------------------
    // RAM
    // -------------------------------------------------------------------------

    private function formatRam(mixed $ramMb): ?string
    {
        if (! $ramMb) {
            return null;
        }

        $ramMb = (int) $ramMb;

        return $ramMb >= 1024
            ? round($ramMb / 1024).' Go'
            : $ramMb.' Mo';
    }

    // -------------------------------------------------------------------------
    // Garantie
    // -------------------------------------------------------------------------

    private function formatWarrantyPeriod(mixed $months): ?string
    {
        if ($months === null || $months === '' || $months === 0) {
            return null;
        }

        return (int) $months.' mois';
    }

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    private function parseDate(?string $date): ?string
    {
        if (! $date || $date === '0000-00-00') {
            return null;
        }

        return $date;
    }

    /**
     * Retourne null si la valeur est vide, 0, ou "0".
     * GLPI retourne 0 pour les dropdowns non renseignés même avec expand_dropdowns.
     */
    private function nullable(mixed $value): mixed
    {
        if ($value === null || $value === 0 || $value === '0' || $value === '') {
            return null;
        }

        return $value;
    }
}
