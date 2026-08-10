<?php

namespace App\Services\Glpi\Contracts;

/**
 * Marqueur pour les handlers dont le nom GLPI n'identifie pas un item de façon unique
 * (ex. Database : deux DatabaseInstance distinctes peuvent chacune porter une base
 * nommée "prod", cf. issue #17). Pour ces handlers, GlpiSyncService::sync() ignore le
 * repli par nom (byName) lors de la réconciliation : seul ext_refs ({GLPI}<id>) fait
 * foi, sans quoi une seconde Database GLPI portant le même nom qu'une première déjà
 * créée dans ce même run se réconcilierait à tort sur l'enregistrement Mercator de la
 * première (perte silencieuse : la seconde n'est jamais créée, la première voit son
 * ext_refs écrasé au run suivant).
 */
interface DisablesNameFallbackMatching {}
