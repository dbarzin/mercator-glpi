<?php

namespace App\Services\Glpi\Contracts;

/**
 * Marqueur pour les handlers dont le mapper a besoin du système d'exploitation.
 * Depuis GLPI 10, operatingsystems_id n'est plus un champ natif de l'item
 * (relation glpi_items_operatingsystems) : il faut appeler explicitement
 * {itemtype}/{id}/Item_OperatingSystem, d'où un appel item par item dédié
 * plutôt qu'un paramètre with_* sur la requête de détail.
 */
interface SupportsGlpiOperatingSystem {}
