<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\DatabaseMapper;
use Illuminate\Support\Facades\Log;

class DatabaseSyncHandler implements SyncHandler
{
    public function __construct(private readonly DatabaseMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Database';
    }

    public function mercatorEndpoint(): string
    {
        return 'databases';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range'            => '0-999',
            'expand_dropdowns' => 1,
            'with_items'       => 1,
        ];
    }

    public function processOrphans(): bool
    {
        // Supprime (ou marque [OLD]) les items Mercator absents de GLPI, cf. issue #13.
        return true;
    }

    public function filterItem(array $item): bool
    {
        // Opt-in : ne conserver que les bases actives (glpi_databases.is_active = 1), cf. issue #17.
        // Comportement historique (flag absent/false) : toutes les bases sont conservées.
        if (! config('glpi.sync.only_active_databases', false)) {
            return true;
        }

        // is_active reste un booléen 0/1 même avec expand_dropdowns=1 (ce n'est pas un dropdown).
        // Champ absent (API qui ne le renvoie pas) : on conserve pour éviter une exclusion
        // silencieuse, avec un avertissement journalisé (la colonne est NOT NULL côté GLPI).
        if (! array_key_exists('is_active', $item)) {
            Log::warning('[databases] GLPI_SYNC_ONLY_ACTIVE_DATABASES=true mais champ is_active absent — base "'.($item['name'] ?? '?').'" conservée');

            return true;
        }

        return (int) $item['is_active'] === 1;
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
