<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;
use App\Services\Glpi\Mappers\Concerns\ResolvesGlpiLocationName;

class LocationMapper
{
    use AppendsUnmappedFields;
    use ResolvesGlpiLocationName;

    /**
     * Mappe une Location GLPI non racine (expand_dropdowns=1) vers un payload
     * Mercator buildings. Les Location racines deviennent des Site (cf. SiteMapper)
     * et ne passent pas par ce mapper (cf. LocationSyncHandler::filterItem).
     *
     * Mercator Building : name, description, building_id (parent), site_id.
     *
     * - Si le parent (locations_id) est une Location racine : le Building est
     *   rattaché directement au Site créé pour cette racine (building_id = null,
     *   site_id = id de ce Site).
     * - Si le parent est lui-même une Location non racine (donc un Building) : le
     *   Building est rattaché à ce Building parent (building_id = id du parent) et
     *   hérite de son site_id, propagé transitivement depuis la racine.
     *
     * @param  array  $item  Location GLPI brut (non racine)
     * @param  array  $context  ['buildings_map' => ['nom (lower)' => ['id' => X, 'site_id' => Y]],
     *                          'sites_map'     => ['nom (lower)' => Y]]
     */
    public function map(array $item, array $context = []): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $sitesMap = $context['sites_map'] ?? [];
        $parentName = $this->locationLeafName($item['locations_id'] ?? null);

        $buildingId = null;
        $siteId = null;

        if ($parentName !== null) {
            $parentKey = strtolower($parentName);

            if (isset($sitesMap[$parentKey])) {
                $siteId = $sitesMap[$parentKey];
            } elseif (isset($buildingsMap[$parentKey])) {
                $buildingId = $buildingsMap[$parentKey]['id'] ?? null;
                $siteId = $buildingsMap[$parentKey]['site_id'] ?? null;
            }
        }

        return array_filter([
            'name' => $item['name'],
            'description' => $this->buildDescription($item, ['locations_id']),
            'building_id' => $buildingId,
            'site_id' => $siteId,
        ], fn ($v) => $v !== null);
    }
}
