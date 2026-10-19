<?php

use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Exceptions\GateServerException;
use App\LastWar\Game\Gsl\GslClient;
use App\LastWar\Game\Gsl\GslCrypto;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\GameFixtures;

/**
 * Decrypt a getserverlist request the way the gate server would.
 *
 * @return array{salt: string, form: string}
 */
function decryptGateRequest(Request $request): array
{
    openssl_private_decrypt(GslCrypto::base64UrlDecode($request['uuid']), $salt, GameFixtures::privateKey(), OPENSSL_PKCS1_PADDING);

    return [
        'salt' => $salt,
        'form' => openssl_decrypt(GslCrypto::base64UrlDecode($request['data']), 'aes-256-ecb', md5($salt), OPENSSL_RAW_DATA),
    ];
}

it('takes the rsa key from the first gate host that answers', function () {
    Http::fake([
        'lastwar-serverlist-cf.lastwarapp.net/*' => Http::response('down', 502),
        'lastwar-serverlist-us-aws-ali.lastwargame.com/*' => Http::response(['resMsg' => GameFixtures::resMsg(), 'updateType' => '0']),
    ]);

    $gate = app(GslClient::class)->checkVersion('APS783');

    expect($gate->host)->toBe('https://lastwar-serverlist-us-aws-ali.lastwargame.com')
        ->and(openssl_pkey_get_public($gate->publicKeyPem))->not->toBeFalse()
        ->and($gate->forcesUpdate())->toBeFalse();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'getlsu3dversion.php')
        && $request['appVersion'] === '1.0.364'
        && $request['buildId'] === '1892'
        && $request['server'] === 'APS783');
});

it('fails when every gate host fails', function () {
    Http::fake(['*' => Http::response(['code' => '1', 'msg' => 'nope'])]);

    app(GslClient::class)->checkVersion();
})->throws(GameClientException::class, 'Every gate host failed');

it('sends the form encrypted in the game field order and decrypts the bin reply', function () {
    $sentForm = null;

    Http::fake(function (Request $request) use (&$sentForm) {
        ['salt' => $salt, 'form' => $sentForm] = decryptGateRequest($request);

        $reply = json_encode([
            'code' => '0',
            'serverList' => [['id' => '783', 'ip' => 'a.test|b.test', 'port' => '10783', 'zone' => 'APS783', 'gameUid' => '1001']],
            'at' => ['token' => 'access', 'time' => '1790000000'],
            'rt' => ['token' => 'refresh', 'time' => '1790000000'],
        ]);

        return Http::response(['bin' => GslCrypto::base64UrlEncode(openssl_encrypt($reply, 'aes-256-ecb', md5($salt), OPENSSL_RAW_DATA))]);
    });

    $servers = app(GslClient::class)->serverList(GameFixtures::gate(), 'device_n3d', '', '1001', 'login', loginKey: 'key-1');

    expect($sentForm)->toBe('uuid=device_n3d&airKey=lwDid_'.base64_encode('device_n3d')
        .'&loginFlag=1&country=US&is3D=1&lang=en&simOp=&platform=Android&isSimulator=0&zone=&gameuid=1001'
        .'&newServer=1&openCountry=US&opt=login&loginKey=key-1')
        ->and($servers->accessToken)->toBe('access')
        ->and($servers->accessTokenTime)->toBe(1_790_000_000)
        ->and($servers->refreshToken)->toBe('refresh')
        ->and($servers->pickServer('1001'))->toBe(['id' => '783', 'ip' => 'a.test|b.test', 'port' => 10783, 'zone' => 'APS783', 'game_uid' => '1001']);
});

it('falls back to the login server for a new guest', function () {
    Http::fake(['*' => Http::response(['code' => '0', 'serverList' => [], 'loginServer' => ['ip' => 'login.test', 'port' => '10001']])]);

    $servers = app(GslClient::class)->serverList(GameFixtures::gate(), 'device_n3d', opt: 'new');

    expect($servers->servers)->toHaveCount(1)
        ->and($servers->servers[0]['ip'])->toBe('login.test');
});

it('flags rejected credentials as needing a re-bind', function () {
    Http::fake(['*' => Http::response(['code' => '211'])]);

    try {
        app(GslClient::class)->serverList(GameFixtures::gate(), 'device_n3d', opt: 'refresh', refreshToken: 'old')->ensureSuccessful();
    } catch (GateServerException $e) {
        expect($e->requiresReauth())->toBeTrue();

        return;
    }

    $this->fail('Expected a GateServerException.');
});
