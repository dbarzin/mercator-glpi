<?php

namespace App\Commands;

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\ApplianceSyncHandler;
use App\Services\Glpi\Handlers\ApplicationSyncHandler;
use App\Services\Glpi\Handlers\CertificateSyncHandler;
use App\Services\Glpi\Handlers\ClusterSyncHandler;
use App\Services\Glpi\Handlers\DatabaseSyncHandler;
use App\Services\Glpi\Handlers\DomainSyncHandler;
use App\Services\Glpi\Handlers\LocationSyncHandler;
use App\Services\Glpi\Handlers\LogicalServerSyncHandler;
use App\Services\Glpi\Handlers\NetworkDeviceSyncHandler;
use App\Services\Glpi\Handlers\PeripheralSyncHandler;
use App\Services\Glpi\Handlers\PhoneSyncHandler;
use App\Services\Glpi\Handlers\PhysicalSecurityDeviceSyncHandler;
use App\Services\Glpi\Handlers\PhysicalServerSyncHandler;
use App\Services\Glpi\Handlers\RackSyncHandler;
use App\Services\Glpi\Handlers\RouterSyncHandler;
use App\Services\Glpi\Handlers\SiteSyncHandler;
use App\Services\Glpi\Handlers\StorageDeviceSyncHandler;
use App\Services\Glpi\Handlers\WifiTerminalSyncHandler;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Glpi\VmLinkSyncService;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Support\Facades\Log;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class GlpiSyncCommand extends Command
{
    protected $signature = 'glpi:sync
                            {--dry-run : Simule la synchronisation sans écrire}
                            {--type=* : Types à synchroniser. Défaut : tous}
                            {--entity= : ID de l\'entité GLPI (priorité sur GLPI_ENTITY_ID)}';

    protected $description = 'Synchronise les assets GLPI vers Mercator';

    private array $handlers = [
        'workstations' => WorkstationSyncHandler::class,
        'applications' => ApplicationSyncHandler::class,
        'peripherals' => PeripheralSyncHandler::class,
        'phones' => PhoneSyncHandler::class,
        'network_devices' => NetworkDeviceSyncHandler::class,
        'routers' => RouterSyncHandler::class,
        'wifi_terminals' => WifiTerminalSyncHandler::class,
        'physical_security_devices' => PhysicalSecurityDeviceSyncHandler::class,
        'storage_devices' => StorageDeviceSyncHandler::class,
        'racks' => RackSyncHandler::class,
        'appliances' => ApplianceSyncHandler::class,
        'sites' => SiteSyncHandler::class,
        'locations' => LocationSyncHandler::class,
        'logical_servers' => LogicalServerSyncHandler::class,
        'physical_servers' => PhysicalServerSyncHandler::class,
        'certificates' => CertificateSyncHandler::class,
        'clusters' => ClusterSyncHandler::class,
        'domains' => DomainSyncHandler::class,
        'databases' => DatabaseSyncHandler::class,
    ];

    public function handle(
        GlpiClientInterface $glpi,
        MercatorClientInterface $mercator,
        GlpiSyncService $syncService,
        VmLinkSyncService $vmLinkSyncService,
    ): int {
        $dryRun = $this->option('dry-run') || config('glpi.sync.dry_run');
        $start = microtime(true);

        $this->line('');
        $this->line('<fg=cyan>╔══════════════════════════════════════╗</>');
        $this->line('<fg=cyan>║   Mercator ← GLPI Synchronisation    ║</>');
        $this->line('<fg=cyan>╚══════════════════════════════════════╝</>');

        if ($dryRun) {
            $this->line('  <fg=yellow>» Mode DRY-RUN — aucune écriture</>');
        }

        // ── Entité GLPI (Évolution 1) ────────────────────────────────────────

        $entityId = $this->option('entity') !== null
            ? (int) $this->option('entity')
            : config('glpi.glpi.entity_id');

        if ($entityId !== null) {
            $glpi->setEntityId($entityId);
            $this->line("  <fg=yellow>» Entité GLPI filtrée : {$entityId}</>");
        }

        $this->line('');

        // ── Authentification ─────────────────────────────────────────────────

        try {
            $this->line('  Authentification GLPI…');
            $glpi->authenticate();
            $this->line('  <fg=green>✔ GLPI connecté</>');

            $this->line('  Authentification Mercator…');
            $mercator->authenticate();
            $this->line('  <fg=green>✔ Mercator connecté</>');
        } catch (Throwable $e) {
            $this->error('Échec de l\'authentification : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $globalStats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'marked_old' => 0, 'errors' => 0];

        // Ordre intentionnel : sites/locations et applications en premier car les autres
        // types en dépendent (building_id/site_id, liens workstation/activity ↔ application).
        // sites avant locations : les Building (locations) racines référencent le Site
        // créé pour la même Location racine.
        // racks juste après locations (et avant les types réseau) : ces derniers
        // résolvent leur bay_id via les bays déjà synchronisées (cf. SupportsBayResolution).
        $defaultTypes = [
            'sites',
            'locations',
            'racks',
            'applications',
            'appliances',
            'workstations',
            'peripherals',
            'phones',
            'network_devices',
            'routers',
            'wifi_terminals',
            'physical_security_devices',
            'storage_devices',
            'logical_servers',
            'physical_servers',
            'links',
            'activity_links',
            'appliance_links',
        ];
        $types = $this->option('type') ?: $defaultTypes;

        // ── Synchronisation par type ─────────────────────────────────────────

        foreach ($types as $type) {

            if ($type === 'links') {
                $this->line('  <fg=cyan>─── links ───</>');
                try {
                    $linkStats = $syncService->syncLinks($glpi, $mercator, $dryRun);
                    $this->line(sprintf(
                        '  <fg=yellow>~%d mis à jour</>  <fg=gray>%d ignorés</>  <fg=red>%d erreurs</>',
                        $linkStats['updated'],
                        $linkStats['skipped'],
                        $linkStats['errors'],
                    ));
                    $globalStats['updated'] += $linkStats['updated'];
                    $globalStats['errors'] += $linkStats['errors'];
                } catch (Throwable $e) {
                    $this->error('  Erreur lors de la sync links : '.$e->getMessage());
                    $globalStats['errors']++;
                }
                $this->line('');

                continue;
            }

            if ($type === 'activity_links') {
                $this->line('  <fg=cyan>─── activity_links ───</>');
                try {
                    $linkStats = $syncService->syncActivityLinks($glpi, $mercator, $dryRun);
                    $this->line(sprintf(
                        '  <fg=yellow>~%d mis à jour</>  <fg=gray>%d ignorés</>  <fg=red>%d erreurs</>',
                        $linkStats['updated'],
                        $linkStats['skipped'],
                        $linkStats['errors'],
                    ));
                    $globalStats['updated'] += $linkStats['updated'];
                    $globalStats['errors'] += $linkStats['errors'];
                } catch (Throwable $e) {
                    $this->error('  Erreur lors de la sync activity_links : '.$e->getMessage());
                    $globalStats['errors']++;
                }
                $this->line('');

                continue;
            }

            if ($type === 'appliance_links') {
                $this->line('  <fg=cyan>─── appliance_links ───</>');
                try {
                    $linkStats = $syncService->syncApplianceLinks($glpi, $mercator, $dryRun);
                    $this->line(sprintf(
                        '  <fg=yellow>~%d mis à jour</>  <fg=gray>%d ignorés</>  <fg=red>%d erreurs</>',
                        $linkStats['updated'],
                        $linkStats['skipped'],
                        $linkStats['errors'],
                    ));
                    $globalStats['updated'] += $linkStats['updated'];
                    $globalStats['errors'] += $linkStats['errors'];
                } catch (Throwable $e) {
                    $this->error('  Erreur lors de la sync appliance_links : '.$e->getMessage());
                    $globalStats['errors']++;
                }
                $this->line('');

                continue;
            }

            if (! isset($this->handlers[$type])) {
                $this->warn("  Type inconnu ou non actif : {$type}");

                continue;
            }

            $handler = app($this->handlers[$type]);

            $this->line("  <fg=cyan>─── {$type} ───</>");

            try {
                $stats = $syncService->sync($glpi, $mercator, $handler, $dryRun);

                if ($stats['endpoint_missing'] ?? false) {
                    $this->warn('  Endpoint non disponible dans Mercator — type ignoré');
                } else {
                    $this->printStats($stats);
                    $this->mergeStats($globalStats, $stats);
                }
            } catch (Throwable $e) {
                $this->error("  Erreur lors de la sync {$type} : ".$e->getMessage());
                $globalStats['errors']++;
            }

            $this->line('');
        }

        // ── Liens VM → serveur physique (opt-in, GLPI_SYNC_VM_LINKS) ─────────
        // Nécessite que logical_servers ET physical_servers aient été traités dans
        // ce run (résolution ext_refs des deux côtés) : ni un SyncHandler ni un
        // --type sélectionnable, la synchronisation est purement config-gated.

        if (config('glpi.sync.vm_links')) {
            if (in_array('logical_servers', $types, true) && in_array('physical_servers', $types, true)) {
                $this->line('  <fg=cyan>─── vm_links ───</>');
                try {
                    $vmStats = $vmLinkSyncService->sync($glpi, $mercator, $dryRun);
                    $this->line(sprintf(
                        '  <fg=yellow>~%d mis à jour</>  <fg=gray>%d ignorés</>  <fg=yellow>%d ambigus</>  <fg=red>%d erreurs</>',
                        $vmStats['updated'],
                        $vmStats['skipped'],
                        $vmStats['ambiguous'],
                        $vmStats['errors'],
                    ));
                    $globalStats['updated'] += $vmStats['updated'];
                    $globalStats['errors'] += $vmStats['errors'];
                } catch (Throwable $e) {
                    $this->error('  Erreur lors de la sync vm_links : '.$e->getMessage());
                    $globalStats['errors']++;
                }
                $this->line('');
            } else {
                Log::info('[vm-links] GLPI_SYNC_VM_LINKS=true mais logical_servers et/ou physical_servers non inclus dans ce run — synchronisation des liens VM ignorée');
            }
        }

        // ── Fermeture session GLPI ───────────────────────────────────────────

        $glpi->killSession();

        // ── Résumé final ─────────────────────────────────────────────────────

        $duration = round(microtime(true) - $start, 2);

        $this->line('<fg=cyan>─── Résumé ───────────────────────────────</>');
        $this->printStats($globalStats);
        $this->line("  <fg=gray>Durée : {$duration}s</>");
        $this->line('');

        return $globalStats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printStats(array $stats): void
    {
        $this->line(
            sprintf(
                '  <fg=green>+%d créés</>  <fg=yellow>~%d mis à jour</>  <fg=red>-%d supprimés</>  <fg=gray>%d OLD</>  <fg=red>%d erreurs</>',
                $stats['created'],
                $stats['updated'],
                $stats['deleted'],
                $stats['marked_old'],
                $stats['errors'],
            )
        );
    }

    private function mergeStats(array &$global, array $stats): void
    {
        foreach (['created', 'updated', 'deleted', 'marked_old', 'errors'] as $key) {
            $global[$key] += ($stats[$key] ?? 0);
        }
    }
}
