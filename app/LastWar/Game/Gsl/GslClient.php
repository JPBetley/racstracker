<?php

namespace App\LastWar\Game\Gsl;

use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Exceptions\GateServerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTPS client for the gate server list (GSL): check-version and getserverlist.
 */
class GslClient
{
    /**
     * Fields of the getserverlist form, in the order the game client sends them.
     */
    private const array FORM_ORDER = [
        'uuid', 'airKey', 'loginFlag', 'country', 'is3D', 'lang', 'simOp', 'platform',
        'isSimulator', 'zone', 'gameuid', 'newServer', 'openCountry', 'opt', 'loginKey', 'rt',
    ];

    /**
     * Ask each gate host in turn for the current RSA key, returning the first that answers.
     *
     * @throws GameClientException
     */
    public function checkVersion(string $zone = ''): GateHandshake
    {
        $errors = [];

        foreach (config('services.lastwar.game.gate_hosts') as $host) {
            try {
                $response = Http::timeout((int) config('services.lastwar.game.timeout'))
                    ->get("{$host}/gameservice/getlsu3dversion.php", [
                        'packageName' => config('services.lastwar.game.package_name'),
                        'platform' => 'Android',
                        'appVersion' => config('services.lastwar.game.app_version'),
                        'gm' => '0',
                        'server' => $zone,
                        'uid' => '',
                        'deviceId' => '',
                        'table_env' => 'table_online',
                        'buildId' => config('services.lastwar.game.version_code'),
                        'returnJson' => '1',
                        'unityVersion' => config('services.lastwar.game.unity_version'),
                    ]);
            } catch (ConnectionException $e) {
                $errors[] = "{$host}: {$e->getMessage()}";

                continue;
            }

            if ($response->failed() || ! is_array($response->json())) {
                $errors[] = "{$host}: HTTP {$response->status()}";

                continue;
            }

            if (filled($response->json('code'))) {
                $errors[] = "{$host}: code {$response->json('code')} ({$response->json('msg')})";

                continue;
            }

            if (blank($response->json('resMsg'))) {
                $errors[] = "{$host}: no public key in reply";

                continue;
            }

            return new GateHandshake(
                host: $host,
                publicKeyPem: GslCrypto::pemFromDer((string) $response->json('resMsg')),
                updateType: (string) $response->json('updateType', ''),
            );
        }

        throw new GameClientException('Every gate host failed check-version: '.implode('; ', $errors));
    }

    /**
     * Request the account's server list, optionally issuing or refreshing tokens.
     *
     * `opt` selects the flow: `new` creates a guest, `login` trades a login key for a
     * token pair, `refresh` trades a refresh token for a new access token, and no opt
     * just looks up which server hosts the role.
     *
     * @throws GameClientException
     */
    public function serverList(
        GateHandshake $gate,
        string $deviceId,
        string $zone = '',
        string $gameUid = '',
        ?string $opt = null,
        ?string $loginKey = null,
        ?string $refreshToken = null,
    ): ServerList {
        $form = [
            'uuid' => $deviceId,
            'airKey' => self::airKey($deviceId),
            'loginFlag' => '1',
            'country' => 'US',
            'is3D' => '1',
            'lang' => 'en',
            'simOp' => '',
            'platform' => 'Android',
            'isSimulator' => '0',
            'zone' => $zone,
            'gameuid' => $gameUid,
            'newServer' => '1',
            'openCountry' => 'US',
        ];

        if ($opt !== null) {
            $form['opt'] = $opt;
        }

        if ($opt === 'login') {
            $form['loginKey'] = (string) $loginKey;
        }

        if ($opt === 'refresh') {
            $form['rt'] = (string) $refreshToken;
        }

        $crypto = new GslCrypto($gate->publicKeyPem);

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.lastwar.game.timeout'))
                ->post("{$gate->host}/gameservice/getserverlist.php", $crypto->encryptRequest(self::encodeForm($form)));
        } catch (ConnectionException $e) {
            throw new GameClientException("getserverlist.php is unreachable: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed() || ! is_array($response->json())) {
            throw new GameClientException("getserverlist.php failed with HTTP {$response->status()}.");
        }

        $payload = $response->json();

        if (array_key_exists('bin', $payload)) {
            if (blank($payload['bin'])) {
                throw new GameClientException('getserverlist.php returned an empty encrypted body.');
            }

            $payload = json_decode($crypto->decryptResponse((string) $payload['bin']), true);

            if (! is_array($payload)) {
                throw new GameClientException('getserverlist.php returned an undecodable encrypted body.');
            }
        }

        return ServerList::fromPayload($payload, fallBackToLoginServer: $opt === 'new');
    }

    /**
     * The device's "air key", which accompanies the device id everywhere.
     */
    public static function airKey(string $deviceId): string
    {
        return 'lwDid_'.base64_encode($deviceId);
    }

    /**
     * Join the form in the game's field order without URL-encoding, as the game does.
     *
     * @param  array<string, string>  $form
     */
    public static function encodeForm(array $form): string
    {
        $pairs = [];

        foreach (self::FORM_ORDER as $key) {
            if (! array_key_exists($key, $form)) {
                continue;
            }

            if (str_contains($form[$key], '&')) {
                throw new GateServerException("Gate server form field [{$key}] contains '&'.");
            }

            $pairs[] = "{$key}={$form[$key]}";
        }

        return implode('&', $pairs);
    }
}
