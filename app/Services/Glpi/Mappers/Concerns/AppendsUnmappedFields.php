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
     * Format : "commentaire\n"nom_champ" : "valeur"\n…"
     *
     * Les champs GLPI non mappés vers un champ Mercator dédié sont sérialisés
     * à raison d'un par ligne, dans le format demandé. L'identifiant GLPI n'est
     * plus porté par la description : il est désormais dans le champ ext_refs
     * (tag {GLPI}N), géré par GlpiSyncService.
     *
     * @param  array  $item  Item GLPI brut
     * @param  array  $mappedFields  Clés GLPI déjà portées par un champ Mercator dédié
     */
    protected function buildDescription(array $item, array $mappedFields = []): ?string
    {
        $base = trim($item['comment'] ?? '');

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

            $extras[] = '"'.$key.'" : "'.$value.'"';
        }

        $description = empty($extras) ? $base : trim($base."<br>".implode("<br>", $extras));

        return $description === '' ? null : $description;
    }
}
