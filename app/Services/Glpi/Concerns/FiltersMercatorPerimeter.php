<?php

namespace App\Services\Glpi\Concerns;

/**
 * Restriction des objets Mercator au périmètre de la synchronisation (option
 * --perimeter / MERCATOR_PERIMETER_ID), cf. README « Périmètre Mercator ».
 */
trait FiltersMercatorPerimeter
{
    /**
     * Un item Mercator sans perimeter_id (Mercator sans gestion des périmètres) est
     * considéré comme appartenant à tout périmètre.
     */
    private function inPerimeter(array $item, int $perimeterId): bool
    {
        $itemPerimeter = $item['perimeter_id'] ?? null;

        return $itemPerimeter === null || (int) $itemPerimeter === $perimeterId;
    }

    /**
     * Ne garde que les items Mercator du périmètre (tous si $perimeterId est null).
     */
    private function onlyPerimeter(array $items, ?int $perimeterId): array
    {
        if ($perimeterId === null) {
            return $items;
        }

        return array_values(array_filter($items, fn (array $item) => $this->inPerimeter($item, $perimeterId)));
    }
}
