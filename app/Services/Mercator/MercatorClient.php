<?php

namespace App\Services\Mercator;

use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MercatorClient implements MercatorClientInterface
{
    private ?string $accessToken = null;

    public function __construct(private readonly array $config) {}

    // -------------------------------------------------------------------------
    // Session
    // -------------------------------------------------------------------------

    public function authenticate(): void
    {
        $url = $this->url('login');
        Log::debug('[Mercator] POST ' . $url);

        $response = Http::post($url, [
            'login'    => $this->config['login'],
            'password' => $this->config['password'],
        ]);

        Log::debug('[Mercator] login → HTTP ' . $response->status());

        if ($response->failed()) {
            Log::debug('[Mercator] Erreur login : ' . $response->body());
            throw new RuntimeException(
                'Authentification Mercator échouée : ' . $response->status()
            );
        }

        $this->accessToken = $response->json('access_token');
    }

    // -------------------------------------------------------------------------
    // Buildings (pour résolution building_id)
    // -------------------------------------------------------------------------

    public function getBuildings(): array
    {
        return $this->getAll('buildings');
    }

    public function getSites(): array
    {
        return $this->getAll('sites');
    }

    // -------------------------------------------------------------------------
    // CRUD générique (workstations, phones, peripherals…)
    // -------------------------------------------------------------------------

    public function getAll(string $endpoint): array
    {
        $url = $this->url($endpoint);
        Log::debug("[Mercator] GET {$endpoint}");

        $response = $this->request()->get($url, ['per_page' => 1000]);

        Log::debug("[Mercator] {$endpoint} → HTTP {$response->status()}");

        if ($response->failed()) {
            Log::debug("[Mercator] Erreur GET {$endpoint} : " . $response->body());
            throw new RuntimeException(
                "Erreur lors de la récupération de {$endpoint} : " . $response->status()
            );
        }

        $items = $this->extractItems($response);
        Log::debug("[Mercator] {$endpoint} → " . count($items) . ' item(s) reçu(s)');

        return $items;
    }

    public function create(string $endpoint, array $payload): array
    {
        Log::debug("[Mercator] POST {$endpoint}", ['payload' => $this->truncatePayload($payload)]);

        $response = $this->request()->post($this->url($endpoint), $payload);

        Log::debug("[Mercator] POST {$endpoint} → HTTP {$response->status()}");

        if ($response->failed()) {
            Log::debug("[Mercator] Erreur POST {$endpoint} : " . $response->body());
            throw new RuntimeException(
                "Erreur lors de la création dans {$endpoint} : " . $response->status()
                . ' — ' . $response->body()
            );
        }

        return $response->json() ?? [];
    }

    public function update(string $endpoint, int $id, array $payload): array
    {
        Log::debug("[Mercator] PUT {$endpoint}/{$id}", ['payload' => $this->truncatePayload($payload)]);

        $response = $this->request()->put($this->url("{$endpoint}/{$id}"), $payload);

        Log::debug("[Mercator] PUT {$endpoint}/{$id} → HTTP {$response->status()}");

        if ($response->failed()) {
            Log::debug("[Mercator] Erreur PUT {$endpoint}/{$id} : " . $response->body());
            throw new RuntimeException(
                "Erreur lors de la mise à jour de {$endpoint}/{$id} : " . $response->status()
                . ' — ' . $response->body()
            );
        }

        return $response->json() ?? [];
    }

    public function delete(string $endpoint, int $id): void
    {
        Log::debug("[Mercator] DELETE {$endpoint}/{$id}");

        $response = $this->request()->delete($this->url("{$endpoint}/{$id}"));

        Log::debug("[Mercator] DELETE {$endpoint}/{$id} → HTTP {$response->status()}");

        if ($response->failed()) {
            Log::debug("[Mercator] Erreur DELETE {$endpoint}/{$id} : " . $response->body());
            throw new RuntimeException(
                "Erreur lors de la suppression de {$endpoint}/{$id} : " . $response->status()
            );
        }
    }

    // -------------------------------------------------------------------------
    // Périmètres
    // -------------------------------------------------------------------------

    public function perimeterExists(int $id): ?bool
    {
        Log::debug("[Mercator] GET perimeters/{$id}");

        $response = $this->request()->get($this->url("perimeters/{$id}"));

        Log::debug("[Mercator] GET perimeters/{$id} → HTTP {$response->status()}");

        return match (true) {
            $response->successful() => true,
            $response->status() === 404 => false,
            default => null,
        };
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extrait le tableau d'items depuis la réponse Mercator.
     *
     * Gère les deux formats possibles :
     *   - Paginé Laravel  : {"data": [...], "links": {...}, "meta": {...}}
     *   - Tableau direct  : [{...}, {...}, ...]
     */
    private function extractItems(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            return [];
        }

        if (array_key_exists('data', $json) && is_array($json['data'])) {
            return $json['data'];
        }

        if (array_is_list($json)) {
            return $json;
        }

        return [];
    }

    private function truncatePayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return strlen($json) > 500 ? substr($json, 0, 500) . '…' : $json;
    }

    private function request(): PendingRequest
    {
        if (! $this->accessToken) {
            throw new RuntimeException('MercatorClient non authentifié. Appeler authenticate() d\'abord.');
        }

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ]);
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->config['url'], '/') . '/api/' . $endpoint;
    }
}
