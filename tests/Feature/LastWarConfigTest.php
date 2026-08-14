<?php

use Illuminate\Support\Facades\Http;

it('exposes the Last War API service configuration', function () {
    expect(config('services.lastwar'))
        ->toBeArray()
        ->toHaveKeys(['base_url', 'key', 'session_key', 'alliance_id', 'server_id', 'timeout']);
});

it('defaults to the public Last War API base url', function () {
    expect(config('services.lastwar.base_url'))->toBe('https://api.lastwar.tools');
});

it('maps each credential to its expected environment variable', function (string $key, string $variable) {
    expect(config("services.lastwar.{$key}"))->toBe(env($variable));
})->with([
    ['key', 'LASTWAR_API_KEY'],
    ['session_key', 'LASTWAR_SESSION_KEY'],
    ['alliance_id', 'LASTWAR_ALLIANCE_ID'],
    ['server_id', 'LASTWAR_SERVER_ID'],
]);

it('sends the api key as the X-API-Key header', function () {
    Http::fake([
        'api.lastwar.tools/*' => Http::response(['status' => 'ok']),
    ]);

    config()->set('services.lastwar.key', 'test-key');

    Http::baseUrl(config('services.lastwar.base_url'))
        ->withHeaders(['X-API-Key' => config('services.lastwar.key')])
        ->get('/auth/validate');

    Http::assertSent(
        fn ($request) => $request->hasHeader('X-API-Key', 'test-key')
            && $request->url() === 'https://api.lastwar.tools/auth/validate'
    );
});
