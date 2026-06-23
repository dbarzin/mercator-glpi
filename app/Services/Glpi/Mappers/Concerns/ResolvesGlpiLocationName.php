<?php

namespace App\Services\Glpi\Mappers\Concerns;

trait ResolvesGlpiLocationName
{
    /**
     * GLPI (expand_dropdowns=1) renvoie le chemin complet d'une Location imbriquée,
     * ex. "Siège Social > Bâtiment A > Salle 101", et pas seulement son nom propre.
     * Mercator ne connaît les buildings que par leur nom propre : on ne garde donc
     * que le dernier segment du chemin pour la recherche dans buildings_map.
     */
    private function locationLeafName(mixed $value): ?string
    {
        if (! $value || is_int($value)) {
            // 0 ou null = pas de localisation dans GLPI
            return null;
        }

        // GLPI encode les entités HTML dans les chemins (ex. "A &#62; B" pour "A > B").
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5);
        $pos = strrpos($value, ' > ');

        return $pos === false ? $value : substr($value, $pos + 3);
    }
}
