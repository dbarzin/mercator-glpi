<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class CertificateMapper
{
    use AppendsUnmappedFields;

    /**
     * Mappe un Certificate GLPI vers un payload Mercator certificates.
     *
     * L'identifiant GLPI n'est plus porté par description : il est géré par
     * ext_refs (tag {GLPI}N), calculé de façon centralisée par GlpiSyncService.
     */
    public function map(array $item, array $context): array
    {
        return array_filter([
            'name' => $item['name'] ?? null,
            'description' => $this->buildDescription($item, [
                'certificatetypes_id', 'states_id', 'date_expiration',
            ]),
            'type' => $item['certificatetypes_id'] ?? null,
            'status' => $context['states'][$item['states_id']] ?? null,
            'expiration_date' => $item['date_expiration'] ?? null,
            'update_source' => 'GLPI',
        ], fn ($v) => $v !== null);
    }
}
