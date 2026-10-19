<?php

namespace Tests\Support;

use App\LastWar\Game\GameConnection;
use App\LastWar\Game\GameSession;
use App\LastWar\Game\Gsl\GateHandshake;
use App\LastWar\Game\Gsl\ServerList;
use App\LastWar\Game\Sfs\SfsObject;
use OpenSSLAsymmetricKey;

/**
 * Shared builders for native game client tests.
 */
class GameFixtures
{
    private static ?OpenSSLAsymmetricKey $key = null;

    public static function privateKey(): OpenSSLAsymmetricKey
    {
        return self::$key ??= openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    /**
     * The public key as check-version's `resMsg` carries it: base64 DER, no PEM armor.
     */
    public static function resMsg(): string
    {
        $pem = openssl_pkey_get_details(self::privateKey())['key'];

        return implode('', array_slice(explode("\n", trim($pem)), 1, -1));
    }

    public static function gate(): GateHandshake
    {
        return new GateHandshake('https://gate.test', openssl_pkey_get_details(self::privateKey())['key']);
    }

    public static function servers(
        ?string $accessToken = null,
        ?string $refreshToken = null,
        string $code = '0',
        string $gameUid = '1001',
        string $zone = 'APS783',
    ): ServerList {
        return new ServerList(
            code: $code,
            servers: $code === '0' ? [[
                'id' => '783',
                'ip' => 'game-a.test|game-b.test',
                'port' => 10783,
                'zone' => $zone,
                'game_uid' => $gameUid,
            ]] : [],
            accessToken: $accessToken,
            accessTokenTime: $accessToken ? 1_790_000_000 : null,
            refreshToken: $refreshToken,
        );
    }

    public static function init(string $allianceId = 'alliance-1'): SfsObject
    {
        return (new SfsObject)
            ->putObject('alliance', (new SfsObject)->putUtfString('uid', $allianceId))
            ->putObject('user', (new SfsObject)->putUtfString('name', 'Commander'));
    }

    public static function session(FakeGameTransport $transport, ?SfsObject $init = null): GameSession
    {
        return new GameSession(new GameConnection($transport), 'APS783', '1001', new SfsObject, $init);
    }
}
