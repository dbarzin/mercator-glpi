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
        // Type de technologie résolu via la DatabaseInstance parente (cf.
        // GlpiSyncService::buildDatabaseTypesMap(), SupportsDatabaseInstanceResolution).
        // Absent du contexte ou vide : la clé 'type' est omise (pas envoyée à null/'')
        // afin de ne jamais écraser une valeur déjà présente côté Mercator.
        $type = $context['database_types'][$item['id']] ?? null;

        return array_filter([
            'name' => $this->padName($item['name'] ?? null),
            'type' => $type !== '' ? $type : null,
            'update_source' => 'GLPI',
        ], fn ($v) => $v !== null);
    }

    /**
     * Mercator exige un nom d'au moins 3 caractères : complète avec des "_" les noms
     * GLPI plus courts (ex. "db" → "db_", "x" → "x__") plutôt que de rejeter la base.
     */
    private function padName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return $name;
        }

        $missing = 3 - mb_strlen($name);

        return $missing > 0 ? $name.str_repeat('_', $missing) : $name;
    }
}
