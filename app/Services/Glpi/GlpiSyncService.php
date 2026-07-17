<?php

namespace App\Services\Glpi;

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\Contracts\SupportsBayResolution;
use App\Services\Glpi\Contracts\SupportsExplicitEntityFilter;
use App\Services\Glpi\Contracts\SupportsGlpiItemDetail;
use App\Services\Glpi\Contracts\SupportsGlpiOperatingSystem;
use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class GlpiSyncService
{
    /**
     * Itemtypes GLPI ne possédant pas d'attribut "statut" (states_id).
     * Le filtrage par statut est ignoré pour ces types, quelle que soit
     * la config GLPI_ALLOWED_STATES / GLPI_ALLOWED_STATES_<TYPE>.
     * Software : le statut n'existe qu'au niveau SoftwareVersion, non synchronisé
     * (cf. issue #12).
     */
    private const STATELESS_ITEM_TYPES = ['Location', 'Domain', 'Software'];

    /**
     * Synchronise un type d'item GLPI vers Mercator.
     *
     * Retourne un tableau de stats. Si l'endpoint Mercator n'existe pas (HTTP 404),
     * retourne les stats vides avec 'endpoint_missing' => true afin que la commande
     * puisse afficher un avertissement sans comptabiliser d'erreur.
     *
     * @return array{created: int, updated: int, deleted: int, marked_old: int, errors: int, endpoint_missing: bool}
     */
    public function sync(
        GlpiClientInterface $glpi,
        MercatorClientInterface $mercator,
        SyncHandler $handler,
        bool $dryRun = false,
    ): array {
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'marked_old' => 0, 'errors' => 0, 'endpoint_missing' => false];
        $endpoint = $handler->mercatorEndpoint();

        // ── 1. Chargement des données ─────────────────────────────────────────

        $buildingsMap = $this->buildBuildingsMap($mercator);
        $sitesMap = $this->buildSitesMap($mercator);

        $glpiItems = $glpi->getItems(
            $handler->glpiItemType(),
            $handler->glpiQueryParams()
        );

        Log::debug("[{$endpoint}] {$handler->glpiItemType()} GLPI : ".count($glpiItems).' item(s) reçu(s)');

        // ── 2. Filtrage par statut (Évolution 2) ──────────────────────────────

        $itemType = $handler->glpiItemType();
        $isStateless = in_array($itemType, self::STATELESS_ITEM_TYPES, true);

        if ($isStateless && ! empty($this->resolveAllowedStates($itemType))) {
            $detail = $itemType === 'Software'
                ? ' (le statut existe au niveau SoftwareVersion, non synchronisé)'
                : '';
            Log::warning("[{$endpoint}] Filtre statut [{$itemType}] : configuration ignorée — {$itemType} ne possède pas d'attribut states_id dans GLPI{$detail}");
        }

        $allowedStates = $isStateless ? [] : $this->resolveAllowedStates($itemType);

        if (! empty($allowedStates)) {
            $before = count($glpiItems);
            $glpiItems = array_values(array_filter(
                $glpiItems,
                fn ($item) => $this->matchesState($item, $allowedStates)
            ));
            $filtered = $before - count($glpiItems);
            Log::debug("[{$endpoint}] Filtre statut [{$handler->glpiItemType()}] : {$filtered} item(s) exclus, ".count($glpiItems).' conservé(s)');
        }

        // ── 2b. Filtrage explicite par entité ─────────────────────────────────
        // GLPI restreint normalement les items retournés à l'entité active de la
        // session (changeActiveEntities, cf. GlpiClient::authenticate()). Certains
        // itemtypes (Domain) ignorent cette restriction côté serveur : on retente
        // alors un filtrage explicite ici, par comparaison de chemin (completename).

        if ($handler instanceof SupportsExplicitEntityFilter) {
            $entityId = $glpi->getEntityId();

            if ($entityId !== null) {
                $entityPath = $this->resolveEntityPath($glpi, $entityId);

                Log::debug("[{$endpoint}] Filtre entité [{$handler->glpiItemType()}] : entité configurée #{$entityId} → chemin résolu : ".($entityPath ?? '(non résolu)'));

                if ($entityPath !== null) {
                    $before = count($glpiItems);
                    $excludedNames = [];
                    $glpiItems = array_values(array_filter(
                        $glpiItems,
                        function ($item) use ($entityPath, &$excludedNames) {
                            $keep = $this->matchesEntity($item['entities_id'] ?? null, $entityPath);
                            if (! $keep) {
                                $excludedNames[] = ($item['name'] ?? '?').' (entities_id='.($item['entities_id'] ?? '?').')';
                            }

                            return $keep;
                        }
                    ));
                    $filtered = $before - count($glpiItems);
                    Log::debug("[{$endpoint}] Filtre entité [{$handler->glpiItemType()}] : {$filtered} item(s) exclus, ".count($glpiItems).' conservé(s)'.($excludedNames !== [] ? ' — exclus : '.implode(', ', $excludedNames) : ''));
                } else {
                    Log::warning("[{$endpoint}] Filtre entité [{$handler->glpiItemType()}] : entité #{$entityId} non résolue (getItem Entity vide) — filtrage explicite ignoré, tous les items sont conservés");
                }
            }
        }

        // ── 3. Filtrage par sous-type (handler::filterItem) ───────────────────

        $before = count($glpiItems);
        $glpiItems = array_values(array_filter($glpiItems, fn ($item) => $handler->filterItem($item)));
        $excluded = $before - count($glpiItems);

        if ($excluded > 0) {
            Log::debug("[{$endpoint}] Filtre sous-type : {$excluded} item(s) exclus, ".count($glpiItems).' conservé(s)');
        }

        // ── 3c. Tri hiérarchique (Location) ────────────────────────────────────
        // Sur une installation Mercator vierge, les Building doivent être créés
        // racine d'abord, puis enfants : sinon une Location de niveau 2 ne trouve
        // pas encore son Building parent dans buildings_map (cf. étape 6) et se
        // retrouve orpheline (building_id/site_id absents). GLPI fournit "level"
        // (profondeur dans l'arbre) sur chaque Location ; les autres itemtypes
        // n'ont pas ce champ, le tri est alors un no-op (usort est stable).
        usort($glpiItems, fn ($a, $b) => ($a['level'] ?? 0) <=> ($b['level'] ?? 0));

        // ── 3b. Enrichissement item par item (with_networkports, with_disks…) ─

        if ($handler instanceof SupportsGlpiItemDetail) {
            $detailParams = $handler->glpiDetailParams();

            foreach ($glpiItems as &$item) {
                $item = array_merge(
                    $item,
                    $glpi->getItem($handler->glpiItemType(), $item['id'], $detailParams)
                );
            }
            unset($item);

            Log::debug("[{$endpoint}] Enrichissement détaillé : ".count($glpiItems).' item(s)');
        }

        // ── 3c. Enrichissement système d'exploitation (Item_OperatingSystem) ───
        // operatingsystems_id n'est pas un champ natif de Computer depuis GLPI 10
        // (relation glpi_items_operatingsystems) : sous-endpoint dédié, item par item.

        if ($handler instanceof SupportsGlpiOperatingSystem) {
            foreach ($glpiItems as &$item) {
                $osItems = $glpi->getSubItems(
                    $handler->glpiItemType(),
                    $item['id'],
                    'Item_OperatingSystem',
                    ['expand_dropdowns' => 1]
                );
                $item['_os'] = $osItems[0] ?? null;
            }
            unset($item);

            Log::debug("[{$endpoint}] Enrichissement OS : ".count($glpiItems).' item(s)');
        }

        // ── 4. Chargement Mercator ────────────────────────────────────────────

        try {
            $mercatorItems = $mercator->getAll($endpoint);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), ': 404')) {
                Log::warning("[{$endpoint}] Endpoint non disponible dans Mercator (404) — synchronisation ignorée");
                $stats['endpoint_missing'] = true;

                return $stats;
            }
            throw $e;
        }

        Log::debug("[{$endpoint}] Mercator : ".count($mercatorItems).' item(s) existant(s)');

        // ── 5. Construction des index ─────────────────────────────────────────
        // La réconciliation se fait prioritairement via ext_refs ({GLPI}id) ; le nom
        // (en minuscules) ne sert que de filet de sécurité pour les items Mercator
        // pas encore tagués (migration, création manuelle déjà nommée à l'identique).

        $mercByGlpiId = [];
        $mercByName = [];
        foreach ($mercatorItems as $item) {
            $entry = [
                'id' => $item['id'],
                'name' => $item['name'],
                'ext_refs' => $item['ext_refs'] ?? null,
            ];

            $glpiId = $this->extractGlpiId($entry['ext_refs']);
            if ($glpiId !== null) {
                $mercByGlpiId[(string) $glpiId] = $entry;
            }

            $mercByName[strtolower($item['name'])] ??= $entry;
        }

        $context = ['buildings_map' => $buildingsMap, 'sites_map' => $sitesMap];

        // Résolution du bay_id (Item_Rack GLPI → Rack Mercator) : opt-in (cf.
        // SupportsBayResolution) car elle implique de charger tous les Item_Rack
        // GLPI et toutes les bays Mercator. Les Rack doivent avoir été
        // synchronisés (cf. ordre dans GlpiSyncCommand, après les buildings) pour
        // que racks_map contienne leur bay_id Mercator.
        if ($handler instanceof SupportsBayResolution) {
            $context['item_rack_map'] = $this->buildItemRackMap($glpi);
            $context['racks_map'] = $this->buildRacksMap($mercator);
        }

        // ── 6. GLPI → Mercator : créer ou mettre à jour ───────────────────────

        $matchedMercIds = [];

        foreach ($glpiItems as $glpiItem) {
            $glpiId = (string) $glpiItem['id'];
            $existing = $mercByGlpiId[$glpiId] ?? $mercByName[strtolower($glpiItem['name'])] ?? null;
            $action = $existing ? 'UPDATE' : 'CREATE';

            try {
                $payload = $handler->map($glpiItem, $context);
                $payload['ext_refs'] = $this->buildExtRefs($existing['ext_refs'] ?? null, $glpiItem['id']);

                // Pas de troncature : ce log n'est émis qu'en LOG_LEVEL=debug (opt-in) et
                // sert justement à inspecter des champs en fin de payload (cpu, memory, disk…).
                $payloadDebug = json_encode($payload, JSON_UNESCAPED_UNICODE);

                Log::debug("[{$endpoint}] {$action} {$glpiItem['name']} — payload: {$payloadDebug}");

                $mercId = null;

                if ($action === 'UPDATE') {
                    $matchedMercIds[$existing['id']] = true;
                    $mercId = $existing['id'];

                    if (! $dryRun) {
                        $mercator->update($endpoint, $existing['id'], $payload);
                    }
                    $stats['updated']++;
                    Log::info("[{$endpoint}] Mis à jour : {$glpiItem['name']}");
                } else {
                    if (! $dryRun) {
                        $created = $mercator->create($endpoint, $payload);
                        $mercId = $created['id'] ?? null;
                    } else {
                        // Pas d'ID réel en dry-run : un ID négatif local permet quand même
                        // aux Location enfants de ce même run de résoudre leur parent.
                        $mercId = -(int) $glpiItem['id'];
                    }
                    $stats['created']++;
                    Log::info("[{$endpoint}] Créé : {$glpiItem['name']}");
                }

                // Pour les Location (endpoint "buildings"), met à jour buildings_map en
                // mémoire pour ce run : les Location enfants traitées plus loin dans la
                // même boucle (cf. tri par "level" à l'étape 3c) doivent pouvoir résoudre
                // leur Building parent même s'il vient d'être créé à l'instant.
                if ($endpoint === 'buildings' && $mercId !== null) {
                    $context['buildings_map'][strtolower($glpiItem['name'])] = [
                        'id' => $mercId,
                        'site_id' => $payload['site_id'] ?? null,
                    ];
                }

                // Met à jour mercByGlpiId/mercByName en mémoire pour ce run : deux items
                // GLPI distincts peuvent partager le même nom (ex. un même logiciel
                // enregistré séparément dans deux entités GLPI, cf. issue #12). Sans cette
                // mise à jour, le second item ne "voit" pas l'item Mercator tout juste créé
                // par le premier (les index ont été construits une fois avant la boucle) et
                // tente une seconde création en doublon, rejetée par Mercator ("name" est
                // unique → HTTP 422 "already been taken"). En mettant à jour l'index ici,
                // le second item est réconcilié (UPDATE) sur le même enregistrement Mercator.
                if ($mercId !== null) {
                    $mercEntry = ['id' => $mercId, 'name' => $glpiItem['name'], 'ext_refs' => $payload['ext_refs']];
                    $mercByGlpiId[$glpiId] = $mercEntry;
                    $mercByName[strtolower($glpiItem['name'])] = $mercEntry;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::error("[{$endpoint}] Erreur sur {$glpiItem['name']} : ".$e->getMessage());
            }
        }

        // ── 7. Mercator → nettoyage : supprimer ou marquer OLD ────────────────

        if ($handler->processOrphans()) {
            foreach ($mercatorItems as $mercItem) {
                if (isset($matchedMercIds[$mercItem['id']])) {
                    continue;
                }

                $glpiTagId = $this->extractGlpiId($mercItem['ext_refs'] ?? null);

                try {
                    if ($glpiTagId !== null) {
                        if (! $dryRun) {
                            $mercator->delete($endpoint, $mercItem['id']);
                        }
                        $stats['deleted']++;
                        Log::info("[{$endpoint}] Supprimé : {$mercItem['name']}");
                    } else {
                        $oldName = $mercItem['name'];
                        if (! str_starts_with($oldName, '[OLD]')) {
                            if (! $dryRun) {
                                $mercator->update($endpoint, $mercItem['id'], ['name' => '[OLD] '.$oldName]);
                            }
                            $stats['marked_old']++;
                            Log::info("[{$endpoint}] Marqué OLD : {$oldName}");
                        }
                    }
                } catch (Throwable $e) {
                    $stats['errors']++;
                    Log::error("[{$endpoint}] Erreur nettoyage {$mercItem['name']} : ".$e->getMessage());
                }
            }
        }

        Log::debug(sprintf(
            '[%s] Stats : +%d créés, ~%d mis à jour, -%d supprimés, %d OLD, %d erreurs',
            $endpoint,
            $stats['created'],
            $stats['updated'],
            $stats['deleted'],
            $stats['marked_old'],
            $stats['errors'],
        ));

        return $stats;
    }

    /**
     * Synchronise les liens workstation↔application depuis GLPI vers Mercator.
     *
     * @return array{updated: int, skipped: int, errors: int}
     */
    public function syncLinks(
        GlpiClientInterface $glpi,
        MercatorClientInterface $mercator,
        bool $dryRun = false,
    ): array {
        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        // ── 1. Chargement ─────────────────────────────────────────────────────

        $computers = $glpi->getItems('Computer', [
            'range' => '0-999',
            'expand_dropdowns' => 1,
        ]);

        $wsItems = $mercator->getAll('workstations');
        $appItems = $mercator->getAll('applications');

        // ── 2. Index Mercator ─────────────────────────────────────────────────

        $wsMap = [];
        foreach ($wsItems as $ws) {
            $wsMap[strtolower($ws['name'])] = [
                'id' => $ws['id'],
                'name' => $ws['name'],
            ];
        }

        $appMap = [];
        foreach ($appItems as $app) {
            $appMap[strtolower($app['name'])] = $app['id'];
        }

        Log::info(sprintf(
            '[links] %d computers GLPI, %d workstations Mercator, %d applications Mercator',
            count($computers),
            count($wsMap),
            count($appMap),
        ));

        // ── 3. Pour chaque computer présent dans Mercator : récupérer ses logiciels ──

        foreach ($computers as $computer) {
            $computerName = strtolower(trim($computer['name'] ?? ''));

            if (! isset($wsMap[$computerName])) {
                continue;
            }

            $detail = $glpi->getItem('Computer', $computer['id'], [
                'with_softwares' => 1,
                'expand_dropdowns' => 1,
            ]);

            $softwares = $detail['_softwares']
                ?? $detail['softwares']
                ?? $detail['_Computer_SoftwareVersion']
                ?? [];

            $applicationIds = [];

            foreach ($softwares as $software) {
                $softwareName = $this->extractSoftwareName($software);

                if (! $softwareName) {
                    continue;
                }

                if (isset($appMap[$softwareName])) {
                    $applicationIds[] = $appMap[$softwareName];
                } else {
                    $stats['skipped']++;
                    Log::debug("[links] Logiciel absent de Mercator : {$softwareName}");
                }
            }

            if (empty($applicationIds)) {
                continue;
            }

            $uniqueAppIds = array_values(array_unique($applicationIds));
            $workstationId = $wsMap[$computerName]['id'];
            $workstationName = $wsMap[$computerName]['name'];

            try {
                if (! $dryRun) {
                    $payload = [
                        'name' => $workstationName,
                        'applications' => $uniqueAppIds,
                    ];

                    Log::debug(sprintf(
                        '[links] PUT workstations/%d payload: %s',
                        $workstationId,
                        json_encode($payload)
                    ));

                    $mercator->update('workstations', $workstationId, $payload);
                }
                $stats['updated']++;
                Log::info(sprintf('[links] %s → %d application(s) : [%s]',
                    $computerName,
                    count($uniqueAppIds),
                    implode(', ', $uniqueAppIds)
                ));
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::error("[links] Erreur pour {$computerName} : ".$e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Synchronise les liens activité↔application depuis GLPI vers Mercator.
     *
     * Chaque Appliance GLPI est récupérée individuellement avec with_items=1.
     * Les logiciels liés (Software) sont mis en correspondance avec les
     * Application Mercator par nom. La mise à jour passe par le côté Application
     * (ApplicationController.update → activities()->sync([...])).
     *
     * @return array{updated: int, skipped: int, errors: int}
     */
    public function syncActivityLinks(
        GlpiClientInterface $glpi,
        MercatorClientInterface $mercator,
        bool $dryRun = false,
    ): array {
        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        // ── 1. Chargement ─────────────────────────────────────────────────────

        $appliances = $glpi->getItems('Appliance', [
            'range' => '0-999',
            'expand_dropdowns' => 1,
        ]);

        $activityItems = $mercator->getAll('activities');
        $appItems = $mercator->getAll('applications');

        // ── 2. Index Mercator ─────────────────────────────────────────────────

        $activityMap = [];
        foreach ($activityItems as $act) {
            $activityMap[strtolower($act['name'])] = $act['id'];
        }

        $appMap = [];
        foreach ($appItems as $app) {
            $appMap[strtolower($app['name'])] = ['id' => $app['id'], 'name' => $app['name']];
        }

        Log::info(sprintf(
            '[activity_links] %d appliances GLPI, %d activités Mercator, %d applications Mercator',
            count($appliances),
            count($activityMap),
            count($appMap),
        ));

        // ── 3. Construire le map software_name → [activity_ids] ──────────────
        // Pour chaque Appliance, on récupère individuellement ses Software liés
        // (with_items=1 n'est pas garanti sur les requêtes de liste).

        $softwareToActivities = []; // lower(software_name) → [activity_id, ...]

        foreach ($appliances as $appliance) {
            $applianceKey = strtolower(trim($appliance['name'] ?? ''));
            $activityId = $activityMap[$applianceKey] ?? null;

            if ($activityId === null) {
                $stats['skipped']++;
                Log::debug("[activity_links] Appliance sans activité Mercator : {$appliance['name']}");

                continue;
            }

            $detail = $glpi->getItem('Appliance', $appliance['id'], [
                'with_items' => 1,
                'expand_dropdowns' => 1,
            ]);
            $softwareItems = $detail['_items']['Software'] ?? [];

            if (empty($softwareItems)) {
                Log::debug("[activity_links] Appliance sans logiciels liés : {$appliance['name']}");

                continue;
            }

            foreach ($softwareItems as $sw) {
                $swName = strtolower(trim($sw['name'] ?? ''));
                if ($swName === '') {
                    continue;
                }
                $softwareToActivities[$swName][] = $activityId;
            }
        }

        // ── 4. Pour chaque Application Mercator : synchroniser ses activités ──

        foreach ($appMap as $appName => $appEntry) {
            $activityIds = array_values(array_unique($softwareToActivities[$appName] ?? []));

            if (empty($activityIds)) {
                continue;
            }

            try {
                if (! $dryRun) {
                    $mercator->update('applications', $appEntry['id'], [
                        'name' => $appEntry['name'],
                        'activities' => $activityIds,
                    ]);
                }
                $stats['updated']++;
                Log::info(sprintf(
                    '[activity_links] %s → %d activité(s) : [%s]',
                    $appEntry['name'],
                    count($activityIds),
                    implode(', ', $activityIds),
                ));
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::error("[activity_links] Erreur pour {$appEntry['name']} : ".$e->getMessage());
            }
        }

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Helpers — filtrage statut (Évolution 2)
    // -------------------------------------------------------------------------

    /**
     * Retourne la liste des states_id autorisés pour un itemtype GLPI.
     * Priorité : config spécifique au type → config globale → [] (aucun filtre).
     */
    private function resolveAllowedStates(string $itemType): array
    {
        $typeStates = config("glpi.allowed_states.{$itemType}", []);

        if (! empty($typeStates)) {
            return array_map('strval', $typeStates);
        }

        $defaultStates = config('glpi.allowed_states.default', []);

        return array_map('strval', $defaultStates);
    }

    /**
     * Vérifie si l'item GLPI correspond à un statut autorisé.
     * Gère le cas où states_id est 0 (non renseigné) ou une chaîne expandée.
     */
    private function matchesState(array $item, array $allowedStates): bool
    {
        $stateValue = (string) ($item['states_id'] ?? '');

        // states_id = 0 ou vide = statut non défini dans GLPI
        if ($stateValue === '' || $stateValue === '0') {
            return in_array('0', $allowedStates, true);
        }

        return in_array($stateValue, $allowedStates, true);
    }

    // -------------------------------------------------------------------------
    // Helpers — filtrage explicite par entité (cf. SupportsExplicitEntityFilter)
    // -------------------------------------------------------------------------

    /**
     * Résout le chemin complet (completename, ex. "Entité racine > Filiale") de
     * l'entité configurée, pour comparaison avec le entities_id (expand_dropdowns=1,
     * donc déjà expansé en chemin) des items GLPI. Retourne null si non résolu
     * (ex. entité supprimée) : le filtrage est alors ignoré, pas plus permissif ni
     * restrictif que le comportement actuel.
     */
    private function resolveEntityPath(GlpiClientInterface $glpi, int $entityId): ?string
    {
        $entity = $glpi->getItem('Entity', $entityId);

        $path = $entity['completename'] ?? $entity['name'] ?? null;

        return $path !== null ? (string) $path : null;
    }

    /**
     * Vérifie que le entities_id (chemin complet) d'un item GLPI est l'entité
     * configurée elle-même ou une de ses entités filles (sémantique "is_recursive",
     * cf. GlpiClient::changeActiveEntities).
     */
    private function matchesEntity(mixed $itemEntityPath, string $entityPath): bool
    {
        if ($itemEntityPath === null || $itemEntityPath === '') {
            return false;
        }

        $itemEntityPath = html_entity_decode((string) $itemEntityPath, ENT_QUOTES | ENT_HTML5);

        return $itemEntityPath === $entityPath
            || str_starts_with($itemEntityPath, $entityPath.' > ');
    }

    // -------------------------------------------------------------------------
    // Helpers — liens
    // -------------------------------------------------------------------------

    /**
     * Extrait le nom du logiciel depuis un enregistrement _softwares GLPI.
     */
    private function extractSoftwareName(array $software): string
    {
        $softwaresId = $software['softwares_id'] ?? null;
        if (is_string($softwaresId) && ! is_numeric($softwaresId)) {
            return strtolower(trim($softwaresId));
        }

        if (! empty($software['softname'])) {
            return strtolower(trim($software['softname']));
        }

        if (! empty($software['name'])) {
            return strtolower(trim($software['name']));
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Helpers — buildings
    // -------------------------------------------------------------------------

    private function buildBuildingsMap(MercatorClientInterface $mercator): array
    {
        $map = [];

        foreach ($mercator->getBuildings() as $building) {
            $map[strtolower($building['name'])] = [
                'id' => $building['id'],
                'site_id' => $building['site_id'] ?? null,
            ];
        }

        return $map;
    }

    private function buildSitesMap(MercatorClientInterface $mercator): array
    {
        $map = [];

        foreach ($mercator->getSites() as $site) {
            $map[strtolower($site['name'])] = $site['id'];
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Helpers — bays (Rack GLPI → bays Mercator)
    // -------------------------------------------------------------------------

    /**
     * Indexe les Item_Rack GLPI (relation item↔rack, table glpi_items_racks) :
     * "{itemtype}_{items_id}" → racks_id. GLPI ne place pas cette relation sur
     * l'item lui-même, il faut donc l'itemtype Item_Rack pour la résoudre.
     */
    private function buildItemRackMap(GlpiClientInterface $glpi): array
    {
        $map = [];

        // expand_dropdowns=0 est indispensable ici : avec la valeur par défaut (1),
        // l'API GLPI renvoie items_id et racks_id sous forme de NOM au lieu d'ID,
        // ce qui brise les clés "{itemtype}_{id}" attendues par resolveBayId().
        foreach ($glpi->getItems('Item_Rack', ['range' => '0-999', 'expand_dropdowns' => 0]) as $itemRack) {
            $itemType = $itemRack['itemtype'] ?? null;
            $rawItemsId = $itemRack['items_id'] ?? null;
            $rawRacksId = $itemRack['racks_id'] ?? null;

            if ($itemType === null || $rawItemsId === null || $rawRacksId === null) {
                continue;
            }

            $map[$itemType.'_'.(int) $rawItemsId] = (int) $rawRacksId;
        }

        return $map;
    }

    /**
     * Indexe les bays Mercator déjà synchronisées par leur Rack GLPI d'origine
     * (tag {GLPI}id de ext_refs) : racks_id GLPI (chaîne) → bay_id Mercator.
     */
    private function buildRacksMap(MercatorClientInterface $mercator): array
    {
        $map = [];

        foreach ($mercator->getAll('bays') as $bay) {
            $glpiId = $this->extractGlpiId($bay['ext_refs'] ?? null);

            if ($glpiId !== null) {
                $map[(string) $glpiId] = $bay['id'];
            }
        }

        return $map;
    }

    /**
     * Extrait l'identifiant GLPI depuis le champ ext_refs Mercator (tag {GLPI}N).
     */
    private function extractGlpiId(?string $extRefs): ?int
    {
        if (! $extRefs) {
            return null;
        }

        preg_match('/\{GLPI\}(\d+)/', $extRefs, $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }

    /**
     * Construit la valeur ext_refs à envoyer à Mercator : remplace/ajoute le tag
     * {GLPI}id tout en préservant les éventuelles références d'autres sources.
     */
    private function buildExtRefs(?string $existingExtRefs, int|string $glpiId): string
    {
        $refs = [];

        if ($existingExtRefs) {
            foreach (explode('|', $existingExtRefs) as $ref) {
                $ref = trim($ref);
                if ($ref !== '' && ! str_starts_with($ref, '{GLPI}')) {
                    $refs[] = $ref;
                }
            }
        }

        $refs[] = '{GLPI}'.$glpiId;

        return implode('|', $refs);
    }
}
