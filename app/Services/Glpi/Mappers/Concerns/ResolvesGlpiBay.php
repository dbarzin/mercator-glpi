<?php

namespace App\Services\Glpi\Mappers\Concerns;

trait ResolvesGlpiBay
{
    /**
     * Résout le bay_id Mercator d'un item GLPI placé dans un Rack.
     *
     * GLPI ne stocke pas la relation item↔rack sur l'item lui-même (table de
     * jointure glpi_items_racks / itemtype Item_Rack) : on passe donc par
     * 'item_rack_map' (clé "{itemtype}_{items_id}" → racks_id GLPI), construit
     * par GlpiSyncService, puis par 'racks_map' (racks_id GLPI → bay_id
     * Mercator, résolu via le tag {GLPI} d'ext_refs des bays déjà synchronisées).
     */
    private function resolveBayId(string $glpiItemType, int|string $glpiItemId, array $context): ?int
    {
        $itemRackMap = $context['item_rack_map'] ?? [];
        $racksMap = $context['racks_map'] ?? [];

        $racksId = $itemRackMap[$glpiItemType.'_'.$glpiItemId] ?? null;

        if ($racksId === null) {
            return null;
        }

        return $racksMap[(string) $racksId] ?? null;
    }
}
