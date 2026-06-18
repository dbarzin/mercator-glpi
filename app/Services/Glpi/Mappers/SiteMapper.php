<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class SiteMapper
{
    use AppendsUnmappedFields;

    /**
     * Mappe une Location GLPI racine (locations_id = 0) vers un payload Mercator sites.
     *
     * Mercator Site : name, description.
     *
     * @param  array  $item  Location GLPI brut (racine)
     */
    public function map(array $item): array
    {
        return [
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, ['locations_id']),
        ];
    }
}
