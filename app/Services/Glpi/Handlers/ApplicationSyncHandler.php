<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Handlers\Concerns\MatchesGlpiDropdownType;
use App\Services\Glpi\Mappers\ApplicationMapper;
use Illuminate\Support\Facades\Log;

class ApplicationSyncHandler implements SyncHandler
{
    use MatchesGlpiDropdownType;

    public function __construct(private readonly ApplicationMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Software';
    }

    public function mercatorEndpoint(): string
    {
        return 'applications';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range' => '0-999',
            'expand_dropdowns' => 1,
        ];
    }

    public function processOrphans(): bool
    {
        // Supprime (ou marque [OLD]) les items Mercator absents de GLPI, cf. issue #13.
        return true;
    }

    public function filterItem(array $item): bool
    {
        $allowed = config('glpi.software_categories', []);

        // Vide = tous les Software sont acceptés (comportement historique)
        if (empty($allowed)) {
            return true;
        }

        $categoryValue = $item['softwarecategories_id'] ?? null;
        $matches = $this->matchesType($categoryValue, $allowed);

        if (! $matches) {
            // GLPI_SOFTWARE_CATEGORIES filtre sur softwarecategories_id : un logiciel
            // importé automatiquement par un agent d'inventaire n'a en général AUCUNE
            // catégorie assignée (0/vide) — un filtre configuré exclut alors tous les
            // logiciels non catégorisés manuellement dans GLPI. Log en debug pour que
            // ce cas (souvent une surprise) soit diagnosticable sans deviner.
            Log::debug("[applications] Filtre catégorie [Software] : {$item['name']} exclu — softwarecategories_id=".json_encode($categoryValue).' ne correspond à aucune valeur autorisée ('.implode(', ', $allowed).')');
        }

        return $matches;
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
