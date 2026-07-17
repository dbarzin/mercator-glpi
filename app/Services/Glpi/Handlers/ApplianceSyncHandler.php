<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SupportsCustomExtRefsTag;
use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\ApplianceMapper;
use Illuminate\Support\Facades\Log;

class ApplianceSyncHandler implements SupportsCustomExtRefsTag, SyncHandler
{
    /**
     * Endpoints Mercator valides pour GLPI_APPLIANCE_MERCATOR_ENDPOINT (cf. issue #12).
     */
    private const VALID_ENDPOINTS = ['activities', 'applications'];

    public function __construct(private readonly ApplianceMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Appliance';
    }

    public function mercatorEndpoint(): string
    {
        return $this->resolveEndpoint();
    }

    /**
     * Tag ext_refs dépendant de l'endpoint cible : {GLPI} (comportement historique,
     * inchangé) en mode activities, {GLPI-Appliance} en mode applications — pour ne
     * pas entrer en collision avec les Software déjà tagués {GLPI} sur ce même
     * endpoint (cf. SupportsCustomExtRefsTag).
     */
    public function extRefsTag(): string
    {
        return $this->resolveEndpoint() === 'applications' ? '{GLPI-Appliance}' : '{GLPI}';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range' => '0-999',
            'expand_dropdowns' => 1,
            'with_items' => 1,
        ];
    }

    public function processOrphans(): bool
    {
        // Supprime (ou marque [OLD]) les items Mercator absents de GLPI, cf. issue #13.
        return true;
    }

    public function filterItem(array $item): bool
    {
        return true;
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }

    /**
     * Valide GLPI_APPLIANCE_MERCATOR_ENDPOINT : retombe sur "activities" (comportement
     * historique) si la config est absente ou invalide, sans jamais planter — le
     * connecteur peut tourner en cron et ne doit pas s'arrêter sur une faute de frappe.
     */
    private function resolveEndpoint(): string
    {
        $configured = config('glpi.appliance_mercator_endpoint', 'activities');

        if (! in_array($configured, self::VALID_ENDPOINTS, true)) {
            Log::warning("[appliances] GLPI_APPLIANCE_MERCATOR_ENDPOINT invalide (\"{$configured}\") — repli sur \"activities\" (valeurs acceptées : ".implode(', ', self::VALID_ENDPOINTS).')');

            return 'activities';
        }

        return $configured;
    }
}
