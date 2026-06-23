<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiBay;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiLocationName;

class RouterMapper
{
    use AppendsUnmappedFields;
    use ResolvesGlpiBay;
    use ResolvesGlpiLocationName;

    /**
     * Mappe un NetworkEquipment GLPI (expand_dropdowns=1) vers un payload Mercator physical-routers.
     *
     * Mercator PhysicalRouter : name, description, type, site_id, building_id, bay_id.
     *
     * @param  array  $item     NetworkEquipment GLPI brut
     * @param  array  $context  ['buildings_map' => [...], 'item_rack_map' => [...], 'racks_map' => [...]]
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
            'building_id' => $building['id'] ?? null,
            'site_id'     => $building['site_id'] ?? null,
            'bay_id'      => $this->resolveBayId('NetworkEquipment', $item['id'], $context),
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

    private function nullable(mixed $value): mixed
    {
        return ($value === null || $value === 0 || $value === '0' || $value === '') ? null : $value;
    }
}
