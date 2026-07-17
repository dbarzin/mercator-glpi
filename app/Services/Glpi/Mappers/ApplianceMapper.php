<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;
use Illuminate\Support\Facades\Log;

class ApplianceMapper
{
    use AppendsUnmappedFields;

    /**
     * Endpoints Mercator valides pour GLPI_APPLIANCE_MERCATOR_ENDPOINT — dupliqué
     * depuis ApplianceSyncHandler::VALID_ENDPOINTS (même liste, pas de dépendance
     * croisée entre Mapper et Handler).
     */
    private const VALID_ENDPOINTS = ['activities', 'applications'];

    /**
     * Longueur max du champ "name" côté Mercator (applications.name), cf.
     * ApplicationMapper::MAX_NAME_LENGTH. Ne s'applique qu'en mode "applications" :
     * le payload "activities" n'a pas cette contrainte côté Mercator.
     */
    private const MAX_NAME_LENGTH = 64;

    /**
     * Mappe une Appliance GLPI (expand_dropdowns=1) vers un payload Mercator.
     *
     * L'endpoint cible ("activities" historique, ou "applications" — cf.
     * GLPI_APPLIANCE_MERCATOR_ENDPOINT, issue #12) est lu directement en config,
     * cohérent avec ApplianceSyncHandler::mercatorEndpoint() : le choix du payload
     * est piloté par la configuration, pas par une détection heuristique du contenu
     * de l'item.
     *
     * @param  array  $item  Appliance GLPI brut
     * @param  array  $context  Réservé
     */
    public function map(array $item, array $context = []): array
    {
        return $this->resolveEndpoint() === 'applications'
            ? $this->mapToApplication($item)
            : $this->mapToActivity($item);
    }

    // -------------------------------------------------------------------------
    // Payloads
    // -------------------------------------------------------------------------

    /**
     * Payload Mercator Activity (comportement historique, inchangé) : name,
     * description, responsible.
     */
    private function mapToActivity(array $item): array
    {
        return array_filter([
            'name' => $item['name'],
            'description' => $this->buildDescription($item, ['users_id_tech']),
            'responsible' => $this->nullable($item['users_id_tech'] ?? null),
        ], fn ($v) => $v !== null);
    }

    /**
     * Payload Mercator Application. Contrairement à Software (cf. ApplicationMapper),
     * l'Appliance GLPI n'a pas de manufacturers_id ni de date d'installation fiable :
     * vendor, editor et install_date ne sont donc pas mappés.
     */
    private function mapToApplication(array $item): array
    {
        return array_filter([
            'name' => $this->truncateName($item['name']),
            'description' => $this->buildDescription($item, ['appliancetypes_id', 'users_id_tech']),
            'type' => $this->nullable($item['appliancetypes_id'] ?? null),
            'responsible' => $this->nullable($item['users_id_tech'] ?? null),
        ], fn ($v) => $v !== null);
    }

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    private function truncateName(string $name): string
    {
        if (mb_strlen($name) <= self::MAX_NAME_LENGTH) {
            return $name;
        }

        $truncated = mb_substr($name, 0, self::MAX_NAME_LENGTH);

        Log::debug('[appliances] Nom tronqué à '.self::MAX_NAME_LENGTH." caractères : \"{$name}\" → \"{$truncated}\"");

        return $truncated;
    }

    private function resolveEndpoint(): string
    {
        $configured = config('glpi.appliance_mercator_endpoint', 'activities');

        return in_array($configured, self::VALID_ENDPOINTS, true) ? $configured : 'activities';
    }

    /**
     * Retourne null si la valeur est vide, 0, ou "0".
     * GLPI retourne 0 pour les dropdowns non renseignés avec expand_dropdowns.
     */
    private function nullable(mixed $value): mixed
    {
        if ($value === null || $value === 0 || $value === '0' || $value === '') {
            return null;
        }

        return $value;
    }
}
