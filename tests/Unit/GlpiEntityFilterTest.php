<?php

use App\Services\Glpi\GlpiClient;
use Illuminate\Support\Facades\Http;

// ── Tests filtrage par entité (Évolution 1) ───────────────────────────────────

it('ajoute entities_id et is_recursive quand une entité est configurée', function () {
    $capturedParams = [];

    Http::fake(function ($request) use (&$capturedParams) {
        parse_str($request->url(), $parsed);
        $capturedParams = $request->data();

        return Http::response(['session_token' => 'tok'], 200);
    });

    Http::fake([
        '*/initSession*' => Http::response(['session_token' => 'tok'], 200),
        '*/changeActiveEntities*' => Http::response(['result' => true], 200),
        '*/Computer*' => Http::response([], 200),
    ]);

    $client = new GlpiClient([
        'url' => 'http://glpi.test',
        'app_token' => 'APP',
        'user_token' => 'USR',
        'entity_id' => 3,
    ]);
    $client->authenticate();
    $client->getItems('Computer', []);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'Computer')) {
            return false;
        }
        $params = $request->data();

        return ($params['entities_id'] ?? null) == 3
            && ($params['is_recursive'] ?? null) == 1;
    });
});

it('n\'ajoute pas entities_id si entity_id est null', function () {
    Http::fake([
        '*/initSession*' => Http::response(['session_token' => 'tok'], 200),
        '*/Phone*' => Http::response([], 200),
    ]);

    $client = new GlpiClient([
        'url' => 'http://glpi.test',
        'app_token' => 'APP',
        'user_token' => 'USR',
        'entity_id' => null,
    ]);
    $client->authenticate();
    $client->getItems('Phone', []);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'Phone')) {
            return false;
        }

        return ! array_key_exists('entities_id', $request->data());
    });
});

it('setEntityId remplace la valeur issue de la config', function () {
    Http::fake([
        '*/initSession*' => Http::response(['session_token' => 'tok'], 200),
        '*/changeActiveEntities*' => Http::response(['result' => true], 200),
        '*/Peripheral*' => Http::response([], 200),
    ]);

    $client = new GlpiClient([
        'url' => 'http://glpi.test',
        'app_token' => 'APP',
        'user_token' => 'USR',
        'entity_id' => null,
    ]);
    $client->authenticate();
    $client->setEntityId(7);
    $client->getItems('Peripheral', []);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'Peripheral')) {
            return false;
        }

        return ($request->data()['entities_id'] ?? null) == 7;
    });

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'changeActiveEntities')) {
            return false;
        }

        return ($request->data()['entities_id'] ?? null) == 7;
    });
});
