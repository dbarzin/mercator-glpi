<?php

namespace App\Services\Glpi\Contracts;

/**
 * Marqueur pour les handlers dont l'itemtype GLPI n'applique pas la restriction
 * d'entité de la session (changeActiveEntities) côté serveur GLPI : contrairement
 * aux autres itemtypes, l'API retourne les items de toutes les entités quel que
 * soit le scope actif (cf. Domain, issue #14). GlpiSyncService retente alors un
 * filtrage explicite côté connecteur, par comparaison de chemin (completename)
 * avec l'entité configurée (GLPI_ENTITY_ID / --entity).
 */
interface SupportsExplicitEntityFilter {}
