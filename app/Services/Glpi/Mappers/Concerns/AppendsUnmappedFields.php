<?php

namespace App\Services\Glpi\Mappers\Concerns;

trait AppendsUnmappedFields
{
    /**
     * Champs GLPI toujours exclus de la description : métadonnées internes et champs
     * portés nativement dans le payload Mercator par tous les mappers (name, comment).
     */
    private static array $glpiSkipAlways = [
        'id', 'name', 'comment',
        'date_mod', 'date_creation',
        'links',
        'is_deleted', 'is_template', 'template_name',
        'entities_id', 'is_recursive',
    ];

    /**
     * Construit la description Mercator.
     *
     * Format : "[glpi_id:N] commentaire\n"nom_champ" : "valeur"\n…"
     *
     * Les champs GLPI non mappés vers un champ Mercator dédié sont sérialisés
     * à la suite du tag glpi_id, à raison d'un par ligne, dans le format demandé.
     *
     * @param  array  $item          Item GLPI brut
     * @param  array  $mappedFields  Clés GLPI déjà portées par un champ Mercator dédié
     */
    protected function buildDescription(array $item, array $mappedFields = []): string
    {
        $tag     = '[glpi_id:' . $item['id'] . ']';
        $comment = trim($item['comment'] ?? '');
        $base    = $comment ? "{$tag} {$comment}" : $tag;

        $skip = array_merge(self::$glpiSkipAlways, $mappedFields);

        $extras = [];
        foreach ($item as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            if (str_starts_with($key, '_') || is_array($value) || is_bool($value)) {
                continue;
            }
            if ($value === null || $value === 0 || $value === '0' || $value === '') {
                continue;
            }

            $extras[] = '"' . $key . '" : "' . $value . '"';
        }

        return empty($extras) ? $base : $base . "\n" . implode("\n", $extras);
    }
}
