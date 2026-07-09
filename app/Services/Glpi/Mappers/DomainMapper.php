<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class DomainMapper
{
    use AppendsUnmappedFields;

    /**
     * Mappe un Domain GLPI vers un payload Mercator domains.
     *
     * L'identifiant GLPI n'est plus porté par description : il est géré par
     * ext_refs (tag {GLPI}N), calculé de façon centralisée par GlpiSyncService.
     *
     * Domain ne possède pas d'attribut "statut" (states_id) côté GLPI, contrairement
     * à Certificate/Cluster : cf. GlpiSyncService::STATELESS_ITEM_TYPES.
     */
    public function map(array $item, array $context): array
    {
        return array_filter([
            'name' => $item['name'] ?? null,
            'description' => $this->buildDescription($item, [
                'domaintypes_id', 'date_expiration',
            ]),
            'type' => $item['domaintypes_id'] ?? null,
            'expiration_date' => $item['date_expiration'] ?? null,
            'update_source' => 'GLPI',
        ], fn ($v) => $v !== null);
    }
}
