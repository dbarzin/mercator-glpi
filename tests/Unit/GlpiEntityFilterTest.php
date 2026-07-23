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

// ── Tests withoutEntityRestriction() (cf. VmLinkSyncService, issue #15) ───────

it('withoutEntityRestriction bascule sur "toutes entités" (entities_id absent) puis restaure l\'entité configurée', function () {
    Http::fake([
        '*/initSession*' => Http::response(['session_token' => 'tok'], 200),
        '*/changeActiveEntities*' => Http::response(['result' => true], 200),
        '*/ComputerVirtualMachine*' => Http::response([], 200),
    ]);

    $client = new GlpiClient([
        'url' => 'http://glpi.test',
        'app_token' => 'APP',
        'user_token' => 'USR',
        'entity_id' => 1,
    ]);
    $client->authenticate();

    $result = $client->withoutEntityRestriction(fn () => $client->getItems('ComputerVirtualMachine', []));

    expect($result)->toBe([]);

    $changeActiveEntitiesCalls = Http::recorded(fn ($request) => str_contains($request->url(), 'changeActiveEntities'))->values();

    // authenticate() (entité 1) + bascule "toutes entités" (pas de entities_id) + restauration (entité 1).
    expect($changeActiveEntitiesCalls)->toHaveCount(3);
    expect($changeActiveEntitiesCalls[0][0]->data()['entities_id'] ?? null)->toBe(1);
    expect($changeActiveEntitiesCalls[1][0]->data())->not->toHaveKey('entities_id');
    expect($changeActiveEntitiesCalls[2][0]->data()['entities_id'] ?? null)->toBe(1);
});

it('withoutEntityRestriction ne fait rien (no-op) quand aucune entité n\'est configurée', function () {
    Http::fake([
        '*/initSession*' => Http::response(['session_token' => 'tok'], 200),
        '*/ComputerVirtualMachine*' => Http::response([], 200),
    ]);

    $client = new GlpiClient([
        'url' => 'http://glpi.test',
        'app_token' => 'APP',
        'user_token' => 'USR',
        'entity_id' => null,
    ]);
    $client->authenticate();

    $client->withoutEntityRestriction(fn () => $client->getItems('ComputerVirtualMachine', []));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'changeActiveEntities'));
});

it('withoutEntityRestriction restaure l\'entité même si le callback lève une exception', function () {
    Http::fake([
        '*/initSession*' => Http::response(['session_token' => 'tok'], 200),
        '*/changeActiveEntities*' => Http::response(['result' => true], 200),
    ]);

    $client = new GlpiClient([
        'url' => 'http://glpi.test',
        'app_token' => 'APP',
        'user_token' => 'USR',
        'entity_id' => 1,
    ]);
    $client->authenticate();

    expect(fn () => $client->withoutEntityRestriction(function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    $changeActiveEntitiesCalls = Http::recorded(fn ($request) => str_contains($request->url(), 'changeActiveEntities'))->values();

    expect($changeActiveEntitiesCalls)->toHaveCount(3);
    expect($changeActiveEntitiesCalls[2][0]->data()['entities_id'] ?? null)->toBe(1);
});
