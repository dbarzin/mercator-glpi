<?php

namespace App\Services\Glpi\Contracts;

/**
 * Marqueur pour les handlers dont plusieurs items GLPI distincts peuvent
 * légitimement partager le même nom et représenter la même ressource côté
 * Mercator (ex. Database : deux DatabaseInstance distinctes peuvent chacune
 * porter une base nommée "prod", cf. issue #17). Pour ces handlers,
 * GlpiSyncService::sync() réconcilie ces items GLPI homonymes sur UN SEUL
 * enregistrement Mercator (pas de doublon, pas de suffixe de désambiguïsation
 * dans le nom) et cumule leurs id GLPI dans ext_refs au lieu de remplacer le
 * tag {GLPI} existant (ex. "{GLPI}4|{GLPI}80"), afin qu'aucun des deux ne soit
 * perdu de vue lors des synchronisations suivantes.
 */
interface SupportsMultipleGlpiIds {}
