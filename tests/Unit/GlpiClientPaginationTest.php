<?php

use App\Services\Glpi\GlpiClient;
use Illuminate\Support\Facades\Http;

// ── Tests pagination GlpiClient::getItems() (issue #12 follow-up : collections > 1000 items) ──

function makeGlpiClientForPagination(): GlpiClient
{
    return new GlpiClient([
        'url' => 'http://glpi.test',
        'app_token' => 'APP',
        'user_token' => 'USR',
        'entity_id' => null,
    ]);
}

it('parcourt toutes les pages quand Content-Range indique plus d\'items que la taille de page', function () {
    $calls = 0;

    Http::fake(function ($request) use (&$calls) {
        if (str_contains($request->url(), 'initSession')) {
            return Http::response(['session_token' => 'tok'], 200);
        }

        if (str_contains($request->url(), 'Software')) {
            $calls++;

            return match ($calls) {
                1 => Http::response(array_fill(0, 1000, ['id' => 1]), 206, ['Content-Range' => '0-999/2500']),
                2 => Http::response(array_fill(0, 1000, ['id' => 2]), 206, ['Content-Range' => '1000-1999/2500']),
                default => Http::response(array_fill(0, 500, ['id' => 3]), 200, ['Content-Range' => '2000-2499/2500']),
            };
        }

        return Http::response([], 200);
    });

    $client = makeGlpiClientForPagination();
    $client->authenticate();

    $items = $client->getItems('Software', ['range' => '0-999']);

    expect($items)->toHaveCount(2500);
    expect($calls)->toBe(3);
});

it('ne fait qu\'une requête si le serveur ne renvoie pas de Content-Range (comportement historique)', function () {
    Http::fake([
        '*/initSession*' => Http::response(['session_token' => 'tok'], 200),
        '*/Phone*' => Http::response([['id' => 1]], 200),
    ]);

    $client = makeGlpiClientForPagination();
    $client->authenticate();

    $items = $client->getItems('Phone', []);

    expect($items)->toHaveCount(1);
    Http::assertSentCount(2);
});

it('s\'arrête dès que le Content-Range indique que le total est atteint', function () {
    $calls = 0;

    Http::fake(function ($request) use (&$calls) {
        if (str_contains($request->url(), 'initSession')) {
            return Http::response(['session_token' => 'tok'], 200);
        }

        if (str_contains($request->url(), 'Computer')) {
            $calls++;

            return Http::response(array_fill(0, 3, ['id' => 1]), 200, ['Content-Range' => '0-2/3']);
        }

        return Http::response([], 200);
    });

    $client = makeGlpiClientForPagination();
    $client->authenticate();

    $items = $client->getItems('Computer', ['range' => '0-999']);

    expect($items)->toHaveCount(3);
    expect($calls)->toBe(1);
});
