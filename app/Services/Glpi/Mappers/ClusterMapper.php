<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class ClusterMapper
{
    use AppendsUnmappedFields;

    /**
     * Mappe un Cluster GLPI vers un payload Mercator clusters.
     *
     * L'identifiant GLPI n'est plus porté par description : il est géré par
     * ext_refs (tag {GLPI}N), calculé de façon centralisée par GlpiSyncService.
     */
    public function map(array $item, array $context): array
    {
        return array_filter([
            'name' => $item['name'] ?? null,
            'description' => $this->buildDescription($item, [
                'clustertypes_id', 'states_id',
            ]),
            'type' => $item['clustertypes_id'] ?? null,
            'status' => $context['states'][$item['states_id']] ?? null,
            'update_source' => 'GLPI',
        ], fn ($v) => $v !== null);
    }
}
