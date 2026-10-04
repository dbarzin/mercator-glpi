<?php

use App\Services\Mercator\MercatorClient;
use Illuminate\Support\Facades\Http;

// ── MercatorClient::perimeterExists() : vérification de --perimeter au démarrage ──

it('indique si un périmètre Mercator existe (200 → true, 404 → false, 403 → non vérifiable)', function (int $status, ?bool $expected) {
    Http::fake([
        '*/api/login' => Http::response(['access_token' => 'tok']),
        '*/api/perimeters/3' => Http::response(['id' => 3], $status),
    ]);

    $client = new MercatorClient(['url' => 'http://mercator.test', 'login' => 'a', 'password' => 'b']);
    $client->authenticate();

    expect($client->perimeterExists(3))->toBe($expected);
})->with([
    'existe' => [200, true],
    'inconnu' => [404, false],
    'sans permission configure' => [403, null],
]);
