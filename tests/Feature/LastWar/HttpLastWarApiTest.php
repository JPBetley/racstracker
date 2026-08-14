<?php

use App\LastWar\Contracts\LastWarApi;
use App\LastWar\LastWarApiException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.lastwar.key', 'test-key');
    config()->set('services.lastwar.session_key', null);

    $this->api = app(LastWarApi::class);
});

it('returns the fields the roster sync needs', function () {
    Http::fake([
        'api.lastwar.tools/alliance/*' => Http::response([
            'alliance_id' => 'abc',
            'member_count' => 1,
            'members' => [[
                'uid' => 'uid-1',
                'name' => '  Alpha  ',
                'rank' => 1,
                'power' => 12_345,
                'hq_level' => 30,
                'x' => null,
            ]],
        ]),
    ]);

    expect($this->api->allianceMembers('abc'))->toBe([
        ['uid' => 'uid-1', 'name' => 'Alpha', 'rank' => 1, 'power' => 12_345],
    ]);
});

it('sends the api key as a header and the alliance id in the path', function () {
    Http::fake(['api.lastwar.tools/*' => Http::response(['members' => []])]);

    $this->api->allianceMembers('deadbeef');

    Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'test-key')
        && str_starts_with($request->url(), 'https://api.lastwar.tools/alliance/deadbeef/members'));
});

it('passes the session key as a query parameter when one is configured', function () {
    config()->set('services.lastwar.session_key', 'session-123');

    Http::fake(['api.lastwar.tools/*' => Http::response(['members' => []])]);

    $this->api->allianceMembers('abc');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'session_key=session-123'));
});

it('omits the session key entirely when none is configured', function () {
    Http::fake(['api.lastwar.tools/*' => Http::response(['members' => []])]);

    $this->api->allianceMembers('abc');

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'session_key'));
});

it('skips malformed member rows', function () {
    Http::fake([
        'api.lastwar.tools/*' => Http::response([
            'members' => [
                ['uid' => 'uid-1', 'name' => 'Alpha', 'rank' => 1, 'power' => 1],
                ['name' => 'No UID', 'rank' => 2],
                'not-an-array',
            ],
        ]),
    ]);

    expect($this->api->allianceMembers('abc'))->toHaveCount(1);
});

it('reports the plain string error shape', function () {
    Http::fake([
        'api.lastwar.tools/*' => Http::response(['detail' => 'Invalid API key.'], 401),
    ]);

    expect(fn () => $this->api->allianceMembers('abc'))
        ->toThrow(LastWarApiException::class, 'Invalid API key.');
});

it('flattens the validation error shape', function () {
    Http::fake([
        'api.lastwar.tools/*' => Http::response([
            'detail' => [
                ['loc' => ['query', 'session_key'], 'msg' => 'Field required', 'type' => 'missing'],
            ],
        ], 422),
    ]);

    expect(fn () => $this->api->allianceMembers('abc'))
        ->toThrow(LastWarApiException::class, 'Field required');
});
