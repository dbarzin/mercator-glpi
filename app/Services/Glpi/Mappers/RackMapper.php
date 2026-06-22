<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiLocationName;

class RackMapper
{
    use AppendsUnmappedFields;
    use ResolvesGlpiLocationName;
    /**
     * Mappe un Rack GLPI (expand_dropdowns=1) vers un payload Mercator bays.
     *
     * Mercator Bay : name, description, building_id (FK vers buildings), site_id.
     *
     * @param  array  $item     Rack GLPI brut
     * @param  array  $context  ['buildings_map' => [...], 'sites_map' => [...]]
     */
    public function map(array $item, array $context): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $sitesMap     = $context['sites_map'] ?? [];
        $building     = $this->resolveBuilding($item['locations_id'] ?? null, $buildingsMap, $sitesMap);

        return array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, ['locations_id']),
            'building_id' => $building['id'] ?? null,
            'site_id'     => $building['site_id'] ?? null,
        ], fn($v) => $v !== null);
    }

    /**
     * Résout le building (ou, à défaut, le site) depuis le nom de la salle GLPI.
     *
     * La Location GLPI d'un Rack peut désigner soit un Building (salle, étage…),
     * soit directement une Location racine devenue un Site (cf. LocationMapper) :
     * on cherche donc d'abord dans buildings_map, puis dans sites_map.
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
}
