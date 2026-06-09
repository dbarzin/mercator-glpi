<?php

namespace App\Commands;

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\ApplicationSyncHandler;
use App\Services\Glpi\Handlers\PeripheralSyncHandler;
use App\Services\Glpi\Handlers\PhoneSyncHandler;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use App\Services\Glpi\Handlers\NetworkDeviceSyncHandler;
use App\Services\Glpi\Handlers\LocationSyncHandler;

class GlpiSyncCommand extends Command
{
    protected $signature = 'glpi:sync
                            {--dry-run : Simule la synchronisation sans écrire}
                            {--type=* : Types à synchroniser (workstations). Défaut : tous}';

    protected $description = 'Synchronise les assets GLPI vers Mercator';

    private array $handlers = [
        'workstations' => WorkstationSyncHandler::class,
        'applications' => ApplicationSyncHandler::class,
        'peripherals'  => PeripheralSyncHandler::class,
        'phones'       => PhoneSyncHandler::class,
        'network_devices' => NetworkDeviceSyncHandler::class,
        'locations' => NetworkDeviceSyncHandler::class,
    ];

    public function handle(
        GlpiClientInterface     $glpi,
        MercatorClientInterface $mercator,
        GlpiSyncService         $syncService,
    ): int {
        $dryRun = $this->option('dry-run') || config('glpi.sync.dry_run');
        $start  = microtime(true);

        $this->line('');
        $this->line('<fg=cyan>╔══════════════════════════════════════╗</>');
        $this->line('<fg=cyan>║   Mercator ← GLPI Synchronisation    ║</>');
        $this->line('<fg=cyan>╚══════════════════════════════════════╝</>');

        if ($dryRun) {
            $this->line('  <fg=yellow>» Mode DRY-RUN — aucune écriture</>');
        }

        $this->line('');

        // ── Authentification ────────────────────────────────────────────────

        try {
            $this->line('  Authentification GLPI…');
            $glpi->authenticate();
            $this->line('  <fg=green>✔ GLPI connecté</>');

            $this->line('  Authentification Mercator…');
            $mercator->authenticate();
            $this->line('  <fg=green>✔ Mercator connecté</>');
        } catch (Throwable $e) {
            $this->error('Échec de l\'authentification : ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $globalStats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'marked_old' => 0, 'errors' => 0];

        // Par défaut : tous les handlers + liens
        $defaultTypes = [...array_keys($this->handlers), 'links'];
        $types        = $this->option('type') ?: $defaultTypes;

        // ── Synchronisation par type ─────────────────────────────────────────

        foreach ($types as $type) {

            // ── Liens workstation↔application (traitement spécial) ───────────
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
                    $globalStats['errors']  += $linkStats['errors'];
                } catch (Throwable $e) {
                    $this->error('  Erreur lors de la sync links : ' . $e->getMessage());
                    $globalStats['errors']++;
                }
                $this->line('');
                continue;
            }

            // ── Handlers SyncHandler (workstations, applications…) ───────────
            if (! isset($this->handlers[$type])) {
                $this->warn("  Type inconnu ou non actif : {$type}");
                continue;
            }

            $handler = app($this->handlers[$type]);

            $this->line("  <fg=cyan>─── {$type} ───</>");

            try {
                $stats = $syncService->sync($glpi, $mercator, $handler, $dryRun);

                $this->printStats($stats);
                $this->mergeStats($globalStats, $stats);
            } catch (Throwable $e) {
                $this->error("  Erreur lors de la sync {$type} : " . $e->getMessage());
                $globalStats['errors']++;
            }

            $this->line('');
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
        foreach ($stats as $key => $value) {
            $global[$key] += $value;
        }
    }
}
