<?php

namespace App\Services\Glpi\Contracts;

/**
 * Marqueur pour les handlers dont le mapper résout le type de technologie d'une
 * Database GLPI via sa DatabaseInstance parente (databaseinstances_id →
 * databaseinstancetypes_id). Coûteux (charge toutes les DatabaseInstance/Database
 * GLPI), donc opt-in plutôt qu'inconditionnel dans GlpiSyncService::sync().
 */
interface SupportsDatabaseInstanceResolution {}
