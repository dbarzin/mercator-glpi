<?php

namespace App\Services\Glpi\Contracts;

/**
 * Marqueur pour les handlers dont l'endpoint Mercator cible est configurable et peut
 * donc entrer en collision avec un autre handler sur le même endpoint (ex. Appliance
 * et Software peuvent tous deux cibler "applications" via
 * GLPI_APPLIANCE_MERCATOR_ENDPOINT, cf. issue #12). Le tag ext_refs ({GLPI}<id> par
 * défaut) doit alors être distinct par handler, sans quoi :
 * - un Appliance id N serait réconcilié à tort avec le Software {GLPI}N (collision
 *   d'ids entre itemtypes différents) ;
 * - le nettoyage des orphelins (étape 7 de GlpiSyncService::sync()) supprimerait ou
 *   marquerait [OLD] les items créés par l'autre handler.
 */
interface SupportsCustomExtRefsTag
{
    /**
     * Tag ext_refs utilisé par ce handler, ex. "{GLPI}" ou "{GLPI-Appliance}".
     */
    public function extRefsTag(): string;
}
