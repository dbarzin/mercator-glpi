<?php

namespace App\Services\Glpi\Contracts;

/**
 * Marqueur pour les handlers dont le mapper résout un bay_id Mercator (Item_Rack
 * GLPI → Rack Mercator). Coûteux (charge tous les Item_Rack + bays Mercator),
 * donc opt-in plutôt qu'inconditionnel dans GlpiSyncService::sync().
 */
interface SupportsBayResolution {}
