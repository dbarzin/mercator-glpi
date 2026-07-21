<?php

namespace App\Services\Glpi\Mappers;


class DatabaseMapper
{

    /**
     * Mappe une Database GLPI vers un payload Mercator databases.
     *
     * L'identifiant GLPI n'est plus porté par description : il est géré par
     * ext_refs (tag {GLPI}N), calculé de façon centralisée par GlpiSyncService.
     */
    public function map(array $item, array $context): array
    {
        return array_filter([
            'name' => $item['name'] ?? null,
            'update_source' => 'GLPI',
        ], fn ($v) => $v !== null);
    }
}
