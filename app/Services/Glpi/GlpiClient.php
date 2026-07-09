<?php

namespace App\Services\Glpi;

use App\Services\Glpi\Contracts\GlpiClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GlpiClient implements GlpiClientInterface
{
    private ?string $sessionToken = null;

    private ?int $entityId;

    public function __construct(private readonly array $config)
    {
        $this->entityId = $config['entity_id'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Entité
    // -------------------------------------------------------------------------

    public function setEntityId(?int $entityId): void
    {
        $this->entityId = $entityId;

        if ($this->sessionToken !== null && $entityId !== null) {
            $this->changeActiveEntities($entityId);
        }
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    // -------------------------------------------------------------------------
    // Session
    // -------------------------------------------------------------------------

    public function authenticate(): void
    {
        $url = $this->url('initSession');
        Log::debug('[GLPI] GET '.$url);

        $response = Http::withHeaders([
            'Authorization' => 'user_token '.$this->config['user_token'],
            'App-Token' => $this->config['app_token'],
        ])->get($url);

        Log::debug('[GLPI] initSession → HTTP '.$response->status());

        if ($response->failed()) {
            Log::debug('[GLPI] Erreur initSession : '.$response->body());
            throw new RuntimeException(
                'Authentification GLPI échouée : '.$response->status()
            );
        }

        $this->sessionToken = $response->json('session_token');

        // L'API GLPI ignore "entities_id"/"is_recursive" passés en query string sur les
        // endpoints de listing : la restriction d'entité doit être appliquée sur la
        // session via changeActiveEntities, sans quoi tous les items (toutes entités) sont retournés.
        if ($this->entityId !== null) {
            $this->changeActiveEntities($this->entityId);
        }
    }

    private function changeActiveEntities(int $entityId): void
    {
        $url = $this->url('changeActiveEntities');
        Log::debug('[GLPI] POST changeActiveEntities', ['entities_id' => $entityId, 'is_recursive' => true]);

        $response = $this->request()->post($url, [
            'entities_id' => $entityId,
            'is_recursive' => true,
        ]);

        Log::debug('[GLPI] changeActiveEntities → HTTP '.$response->status());

        if ($response->failed()) {
            Log::debug('[GLPI] Erreur changeActiveEntities : '.$response->body());
            throw new RuntimeException(
                'Changement d\'entité active GLPI échoué : '.$response->status()
            );
        }
    }

    public function killSession(): void
    {
        if (! $this->sessionToken) {
            return;
        }

        $this->request()->get($this->url('killSession'));
        $this->sessionToken = null;
    }

    // -------------------------------------------------------------------------
    // Items
    // -------------------------------------------------------------------------

    /**
     * Récupère un item GLPI par son ID avec ses données annexes (with_softwares, etc.)
     */
    public function getItem(string $itemType, int $id, array $params = []): array
    {
        $url = $this->url("{$itemType}/{$id}");
        Log::debug("[GLPI] GET {$itemType}/{$id}", ['params' => $params]);

        $response = $this->request()->get($url, $params);

        Log::debug("[GLPI] {$itemType}/{$id} → HTTP {$response->status()}");

        if ($response->failed()) {
            Log::debug("[GLPI] Erreur {$itemType}/{$id} : ".$response->body());
            throw new RuntimeException(
                "Erreur lors de la récupération de {$itemType}/{$id} : ".$response->status()
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Récupère les sous-items liés à un item (ex: Computer/{id}/Item_OperatingSystem).
     * Certaines relations GLPI (OS, licences…) ne sont exposées que via ce
     * sous-endpoint, pas via un paramètre with_* sur getItem().
     */
    public function getSubItems(string $itemType, int $id, string $subItemType, array $params = []): array
    {
        $url = $this->url("{$itemType}/{$id}/{$subItemType}");
        Log::debug("[GLPI] GET {$itemType}/{$id}/{$subItemType}", ['params' => $params]);

        $response = $this->request()->get($url, $params);

        Log::debug("[GLPI] {$itemType}/{$id}/{$subItemType} → HTTP {$response->status()}");

        if ($response->failed()) {
            Log::debug("[GLPI] Erreur {$itemType}/{$id}/{$subItemType} : ".$response->body());
            throw new RuntimeException(
                "Erreur lors de la récupération de {$itemType}/{$id}/{$subItemType} : ".$response->status()
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Récupère les items d'un itemtype donné (Phone, Peripheral, Computer…)
     */
    public function getItems(string $itemType, array $extraParams = []): array
    {
        $params = array_merge([
            'range' => '0-999',
            'expand_dropdowns' => 1,
        ], $extraParams);

        $params = $this->withEntityParams($params);

        $url = $this->url($itemType);
        Log::debug("[GLPI] GET {$itemType}", ['url' => $url, 'params' => $params]);

        $response = $this->request()->get($url, $params);

        Log::debug("[GLPI] {$itemType} → HTTP {$response->status()}");

        if ($response->failed() && $response->status() !== 206) {
            Log::debug("[GLPI] Erreur {$itemType} : ".$response->body());
            throw new RuntimeException(
                "Erreur lors de la récupération de {$itemType} : ".$response->status()
            );
        }

        $items = $response->json() ?? [];
        Log::debug("[GLPI] {$itemType} → {$response->status()}, ".count($items).' item(s) reçu(s)');

        return $items;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function withEntityParams(array $params): array
    {
        if ($this->entityId !== null) {
            $params['entities_id'] = $this->entityId;
            $params['is_recursive'] = 1;
        }

        return $params;
    }

    private function request(): PendingRequest
    {
        if (! $this->sessionToken) {
            throw new RuntimeException('GlpiClient non authentifié. Appeler authenticate() d\'abord.');
        }

        return Http::withHeaders([
            'Session-Token' => $this->sessionToken,
            'App-Token' => $this->config['app_token'],
            'Content-Type' => 'application/json',
        ]);
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->config['url'], '/').'/apirest.php/'.$endpoint;
    }
}
