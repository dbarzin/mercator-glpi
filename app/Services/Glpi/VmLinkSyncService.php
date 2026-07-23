<?php

namespace App\Services\Glpi;

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\Handlers\Concerns\MatchesGlpiDropdownType;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronise les liens serveur logique (VM) ↔ serveur(s) physique(s) hôte(s), à partir
 * des entrées de machine virtuelle portées par chaque Computer hôte GLPI
 * (table glpi_itemvirtualmachines). Fonctionnalité opt-in (GLPI_SYNC_VM_LINKS),
 * demandée par un contributeur pour importer les liens VM → hyperviseur déjà
 * renseignés dans GLPI.
 *
 * N'est PAS un SyncHandler : la liaison suppose que logical_servers ET
 * physical_servers ont déjà été synchronisés côté Mercator (résolution ext_refs des
 * deux côtés). Invoqué par GlpiSyncCommand après la boucle des handlers, uniquement
 * si les deux types ont été traités dans le run courant.
 */
class VmLinkSyncService
{
    use MatchesGlpiDropdownType;

    /**
     * Itemtype GLPI portant les entrées VM : "ItemVirtualMachine" (GLPI 11, champs
     * polymorphes itemtype/items_id) ou "ComputerVirtualMachine" (GLPI 10, champ
     * computers_id). Mémorisé après le premier appel réussi (utile pour les tests/logs).
     */
    private ?string $vmSubItemType = null;

    /**
     * @return array{updated: int, skipped: int, ambiguous: int, errors: int}
     */
    public function sync(
        GlpiClientInterface $glpi,
        MercatorClientInterface $mercator,
        bool $dryRun = false,
    ): array {
        $stats = ['updated' => 0, 'skipped' => 0, 'ambiguous' => 0, 'errors' => 0];

        // ── 1. Chargement des Computer GLPI (hôtes + serveurs logiques candidats) ──

        $hostAllowed = config('glpi.computer_types.physical_servers', []);
        $lsAllowed = config('glpi.computer_types.logical_servers', []);

        $computers = $glpi->getItems('Computer', [
            'range' => '0-999',
            'expand_dropdowns' => 1,
        ]);

        $hosts = array_values(array_filter(
            $computers,
            fn ($c) => $this->matchesType($c['computertypes_id'] ?? null, $hostAllowed)
        ));

        $logicalComputers = array_values(array_filter(
            $computers,
            fn ($c) => $this->matchesType($c['computertypes_id'] ?? null, $lsAllowed)
        ));

        Log::info(sprintf(
            '[vm-links] %d Computer hôte(s) (physical_servers), %d Computer serveur(s) logique(s) candidat(s)',
            count($hosts),
            count($logicalComputers),
        ));

        // ── 2. Index des serveurs logiques GLPI par uuid (+ variante endianness ─
        //      inversée) et par nom (lowercase, trim ; multi-valeurs pour détecter
        //      les ambiguïtés de repli par nom).

        $lsByUuid = [];
        $lsByName = [];

        foreach ($logicalComputers as $lc) {
            $uuid = $this->normalizeUuid($lc['uuid'] ?? null);

            if ($uuid !== null) {
                $lsByUuid[$uuid] ??= $lc;

                $swapped = $this->swapUuidEndianness($uuid);
                if ($swapped !== null) {
                    $lsByUuid[$swapped] ??= $lc;
                }
            }

            $name = strtolower(trim((string) ($lc['name'] ?? '')));
            if ($name !== '') {
                $lsByName[$name][] = $lc;
            }
        }

        // ── 3. Récupération des entrées VM (un seul appel, cf. fetchAllVmEntries) ──
        //      puis résolution du Computer serveur logique correspondant pour
        //      chaque entrée (uuid en priorité, nom en repli).

        $vmEntriesByHost = $this->fetchAllVmEntries($glpi);

        $logicalToPhysical = []; // glpi_id serveur logique → [glpi_id hôte, ...]

        foreach ($hosts as $host) {
            $vmEntries = $vmEntriesByHost[(int) $host['id']] ?? [];

            foreach ($vmEntries as $vm) {
                if ((int) ($vm['is_deleted'] ?? 0) === 1) {
                    continue;
                }

                $match = $this->resolveVmMatch($vm, $lsByUuid, $lsByName, (string) $host['id']);

                if ($match === 'ambiguous') {
                    $stats['ambiguous']++;

                    continue;
                }

                if ($match === null) {
                    $stats['skipped']++;
                    Log::debug('[vm-links] VM '.($vm['name'] ?? '?')." (hôte #{$host['id']}) sans Computer serveur logique correspondant");

                    continue;
                }

                $logicalToPhysical[(int) $match['id']][] = (int) $host['id'];
            }
        }

        foreach ($logicalToPhysical as $lsGlpiId => $hostIds) {
            $logicalToPhysical[$lsGlpiId] = array_values(array_unique($hostIds));
        }

        // ── 4. Résolution Mercator via ext_refs ({GLPI}<id>) ──────────────────

        $lsMercByGlpiId = $this->indexByGlpiId($mercator->getAll('logical-servers'));
        $psMercByGlpiId = $this->indexByGlpiId($mercator->getAll('physical-servers'));

        // ── 5. PUT logical-servers/{id} avec physical_servers ─────────────────
        // Union des serveurs logiques résolus côté GLPI et des serveurs logiques
        // Mercator déjà tagués {GLPI} : un serveur logique tagué sans hôte résolu
        // reçoit quand même un PUT physical_servers=[] (nettoyage des liens
        // obsolètes) ; un serveur logique Mercator SANS tag {GLPI} n'apparaît
        // jamais dans lsMercByGlpiId et n'est donc jamais modifié.

        $allLsGlpiIds = array_values(array_unique(array_merge(
            array_keys($logicalToPhysical),
            array_map('intval', array_keys($lsMercByGlpiId))
        )));

        foreach ($allLsGlpiIds as $lsGlpiId) {
            $lsMercEntry = $lsMercByGlpiId[(string) $lsGlpiId] ?? null;

            if ($lsMercEntry === null) {
                Log::warning("[vm-links] Serveur logique GLPI #{$lsGlpiId} résolu via VM mais sans correspondance Mercator (ext_refs {GLPI}{$lsGlpiId} absent) — liaison ignorée");
                $stats['skipped']++;

                continue;
            }

            $hostGlpiIds = $logicalToPhysical[$lsGlpiId] ?? [];
            $mercatorPhysicalIds = [];

            foreach ($hostGlpiIds as $hostGlpiId) {
                $psEntry = $psMercByGlpiId[(string) $hostGlpiId] ?? null;

                if ($psEntry === null) {
                    Log::warning("[vm-links] Serveur physique GLPI #{$hostGlpiId} sans correspondance Mercator (ext_refs {GLPI}{$hostGlpiId} absent) — hôte ignoré pour {$lsMercEntry['name']}");

                    continue;
                }

                $mercatorPhysicalIds[] = $psEntry['id'];
            }

            $mercatorPhysicalIds = array_values(array_unique($mercatorPhysicalIds));

            try {
                if (! $dryRun) {
                    $mercator->update('logical-servers', $lsMercEntry['id'], [
                        'name' => $lsMercEntry['name'],
                        'physical_servers' => $mercatorPhysicalIds,
                    ]);
                }
                $stats['updated']++;
                Log::info(sprintf(
                    '[vm-links] %s → %d serveur(s) physique(s) : [%s]',
                    $lsMercEntry['name'],
                    count($mercatorPhysicalIds),
                    implode(', ', $mercatorPhysicalIds),
                ));
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::error("[vm-links] Erreur pour {$lsMercEntry['name']} : ".$e->getMessage());
            }
        }

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Helpers — entrées VM (détection GLPI 10 / GLPI 11)
    // -------------------------------------------------------------------------

    /**
     * Récupère la collection complète des entrées VM en un seul appel — plutôt que
     * la route sous-item "/Computer/{id}/…" appelée une fois par hôte — puis les
     * indexe par Computer hôte GLPI. Certaines instances GLPI renvoient une erreur
     * HTTP 500 sur cette route sous-item pour ComputerVirtualMachine alors que la
     * recherche à plat sur la collection fonctionne (cf. issue #15, retour d'un
     * contributeur) ; on évite ainsi ce chemin de code fragile, et on gagne en
     * performance (1 appel au lieu de N). Même principe que buildItemRackMap()
     * (Item_Rack, issue #8) : charger la collection de la relation une bonne fois
     * pour toutes plutôt que de la requêter par parent.
     *
     * Tente "ItemVirtualMachine" (GLPI 11, champs polymorphes itemtype/items_id)
     * puis retombe sur "ComputerVirtualMachine" (GLPI 10, champ computers_id) en
     * cas d'échec HTTP.
     *
     * @return array<int, array> glpi_id du Computer hôte → [entrée VM, ...]
     */
    private function fetchAllVmEntries(GlpiClientInterface $glpi): array
    {
        $params = ['range' => '0-999', 'expand_dropdowns' => 0];

        try {
            $entries = $glpi->getItems('ItemVirtualMachine', $params);
            $this->vmSubItemType = 'ItemVirtualMachine';

            return $this->indexVmEntriesByHost($entries, 'ItemVirtualMachine');
        } catch (Throwable $e) {
            Log::debug('[vm-links] ItemVirtualMachine indisponible ('.$e->getMessage().'), repli sur ComputerVirtualMachine (GLPI 10)');
        }

        $entries = $glpi->getItems('ComputerVirtualMachine', $params);
        $this->vmSubItemType = 'ComputerVirtualMachine';

        return $this->indexVmEntriesByHost($entries, 'ComputerVirtualMachine');
    }

    /**
     * Indexe les entrées VM par Computer hôte : "items_id" filtré sur
     * itemtype="Computer" pour ItemVirtualMachine (relation polymorphe, d'autres
     * itemtypes hôtes que Computer sont ignorés), "computers_id" pour
     * ComputerVirtualMachine (relation directe, un seul itemtype hôte possible).
     *
     * @return array<int, array>
     */
    private function indexVmEntriesByHost(array $entries, string $subItemType): array
    {
        $byHost = [];

        foreach ($entries as $entry) {
            if ($subItemType === 'ItemVirtualMachine') {
                if (($entry['itemtype'] ?? null) !== 'Computer') {
                    continue;
                }
                $hostId = (int) ($entry['items_id'] ?? 0);
            } else {
                $hostId = (int) ($entry['computers_id'] ?? 0);
            }

            if ($hostId > 0) {
                $byHost[$hostId][] = $entry;
            }
        }

        return $byHost;
    }

    // -------------------------------------------------------------------------
    // Helpers — résolution VM ↔ Computer serveur logique
    // -------------------------------------------------------------------------

    /**
     * Résout le Computer serveur logique correspondant à une entrée VM : uuid
     * (normalisé, avec variante endianness inversée) en priorité, nom (lowercase,
     * trim) en repli. Retourne 'ambiguous' si plusieurs Computer candidats partagent
     * le même nom sans qu'aucun n'ait matché par uuid (aucune liaison n'est alors
     * faite, un warning est journalisé).
     *
     * @return array|string|null
     */
    private function resolveVmMatch(array $vm, array $lsByUuid, array $lsByName, string $hostId)
    {
        $uuid = $this->normalizeUuid($vm['uuid'] ?? null);

        if ($uuid !== null && isset($lsByUuid[$uuid])) {
            Log::debug('[vm-links] VM '.($vm['name'] ?? '?')." (hôte #{$hostId}) résolue via uuid {$uuid}");

            return $lsByUuid[$uuid];
        }

        $name = strtolower(trim((string) ($vm['name'] ?? '')));

        if ($name === '') {
            return null;
        }

        $candidates = $lsByName[$name] ?? [];

        if (count($candidates) === 1) {
            Log::debug("[vm-links] VM {$vm['name']} (hôte #{$hostId}) résolue via nom : {$name}");

            return $candidates[0];
        }

        if (count($candidates) > 1) {
            Log::warning("[vm-links] VM {$vm['name']} (hôte #{$hostId}) : plusieurs Computer serveur logique candidats pour le nom '{$name}' — ambiguïté, aucune liaison faite");

            return 'ambiguous';
        }

        return null;
    }

    /**
     * Normalise un uuid GLPI : minuscules, accolades retirées, trim. Les tirets sont
     * conservés (le format "8-4-4-4-12" sert à la permutation d'endianness).
     */
    private function normalizeUuid(?string $uuid): ?string
    {
        if ($uuid === null || trim($uuid) === '') {
            return null;
        }

        return strtolower(str_replace(['{', '}'], '', trim($uuid)));
    }

    /**
     * Permute l'endianness des 3 premiers groupes d'un uuid (8-4-4-4-12) : certains
     * hyperviseurs inventorient l'uuid vu par le guest avec ces groupes en ordre
     * d'octets inversé par rapport à l'uuid vu par l'hôte. GLPI gère lui-même les
     * deux variantes ; on génère donc la forme permutée pour l'indexation.
     */
    private function swapUuidEndianness(string $uuid): ?string
    {
        $parts = explode('-', $uuid);

        if (count($parts) !== 5) {
            return null;
        }

        $swapBytes = fn (string $hex): string => implode('', array_reverse(str_split($hex, 2)));

        $parts[0] = $swapBytes($parts[0]);
        $parts[1] = $swapBytes($parts[1]);
        $parts[2] = $swapBytes($parts[2]);

        return implode('-', $parts);
    }

    // -------------------------------------------------------------------------
    // Helpers — index Mercator par ext_refs
    // -------------------------------------------------------------------------

    /**
     * Indexe une collection Mercator (logical-servers, physical-servers) par
     * l'identifiant GLPI porté par ext_refs (tag {GLPI}<id>) : items pas encore
     * tagués ignorés (jamais créés ni modifiés par ce service).
     *
     * @return array<string, array{id: mixed, name: string}>
     */
    private function indexByGlpiId(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $glpiId = $this->extractGlpiId($item['ext_refs'] ?? null);

            if ($glpiId !== null) {
                $map[(string) $glpiId] = ['id' => $item['id'], 'name' => $item['name']];
            }
        }

        return $map;
    }

    private function extractGlpiId(?string $extRefs): ?int
    {
        if (! $extRefs) {
            return null;
        }

        preg_match('/\{GLPI\}(\d+)/', $extRefs, $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }
}
