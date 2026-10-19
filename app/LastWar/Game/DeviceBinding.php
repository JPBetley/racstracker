<?php

namespace App\LastWar\Game;

use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Gsl\GslClient;
use App\LastWar\Game\Sfs\SfsObject;
use App\Models\GameAccount;
use App\Models\Team;

/**
 * Binds this app to a game account as its own device, via an emailed code.
 *
 * A fresh device logs in as a guest, asks for a verification code for the account's
 * email, and trades that code for the account's long-lived login key. The gate
 * server then issues the device its own access/refresh token pair. The account
 * receives a "New Device Alert" email as a side effect.
 */
class DeviceBinding
{
    private const float REPLY_TIMEOUT = 15.0;

    public function __construct(
        private readonly GslClient $gsl,
        private readonly GameClient $client,
    ) {}

    /**
     * Start a guest session and have the server email a verification code.
     *
     * @throws GameClientException
     */
    public function begin(string $email): PendingBinding
    {
        $deviceId = bin2hex(random_bytes(16)).'_n3d';
        $gate = $this->gsl->checkVersion();
        $servers = $this->gsl->serverList($gate, $deviceId, opt: 'new')->ensureSuccessful();
        $server = $servers->pickServer(null);

        $session = $this->client->zoneLogin(
            hosts: $server['ip'],
            port: $server['port'],
            zone: $server['zone'],
            deviceId: $deviceId,
            gameUid: '',
            accessToken: $servers->accessToken,
        );

        try {
            $session->connection->sendExtension('account.login.send.verify.code', (new SfsObject)
                ->putUtfString('mail', $email)
                ->putUtfString('lang', 'en'));
            $session->connection->waitForCommand(['account.login.send.verify.code', 'push.account.send.verify.code'], self::REPLY_TIMEOUT);
        } catch (GameClientException $e) {
            $session->close();

            throw $e;
        }

        return new PendingBinding($session, $gate, $deviceId, $email);
    }

    /**
     * Finish the bind with the emailed code and store the account's credentials.
     *
     * @throws GameClientException
     */
    public function complete(PendingBinding $pending, string $code, Team $team): GameAccount
    {
        $connection = $pending->session->connection;

        try {
            $connection->sendExtension('account.login.new', (new SfsObject)
                ->putInt('type', 0)
                ->putUtfString('mail', $pending->email)
                ->putUtfString('verifyCode', trim($code))
                ->putUtfString('pf', 'market_global')
                ->putUtfString('deviceId', $pending->deviceId)
                ->putUtfString('airKey', GslClient::airKey($pending->deviceId)));
            $connection->waitForCommand(['account.login.new'], self::REPLY_TIMEOUT);
            $account = $connection->waitForCommand(['push.account.login.new'], self::REPLY_TIMEOUT);
        } finally {
            $pending->cancel();
        }

        $loginKey = $account->getString('loginKey');
        $gameUid = $account->getString('gameUid');

        if ($loginKey === '' || $gameUid === '') {
            throw new GameClientException('The game server confirmed the code but returned no login key or game uid.');
        }

        $servers = $this->gsl->serverList($pending->gate, $pending->deviceId, '', $gameUid, 'login', loginKey: $loginKey)
            ->ensureSuccessful();

        if ($servers->accessToken === null) {
            throw new GameClientException('The gate server issued no access token for the bound device.');
        }

        $server = $servers->pickServer($gameUid);

        return GameAccount::updateOrCreate(['team_id' => $team->id], [
            'device_id' => $pending->deviceId,
            'game_uid' => $server['game_uid'] ?: $gameUid,
            'zone' => $server['zone'],
            'login_key' => $loginKey,
            'access_token' => $servers->accessToken,
            'access_token_issued_at' => $servers->accessTokenTime ? now()->setTimestamp($servers->accessTokenTime) : now(),
            'refresh_token' => $servers->refreshToken,
            'app_version' => config('services.lastwar.game.app_version'),
            'version_code' => config('services.lastwar.game.version_code'),
            'last_error' => null,
        ]);
    }
}
