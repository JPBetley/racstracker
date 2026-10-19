<?php

namespace App\LastWar\Game;

use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Exceptions\GateServerException;
use App\LastWar\Game\Exceptions\LoginRejectedException;
use App\LastWar\Game\Gsl\GateHandshake;
use App\LastWar\Game\Gsl\GslClient;
use App\LastWar\Game\Gsl\ServerList;
use App\LastWar\Game\Sfs\SfsArray;
use App\LastWar\Game\Sfs\SfsObject;
use App\LastWar\Game\Transport\GameTransport;
use App\Models\GameAccount;

/**
 * Logs a bound GameAccount into its game server and runs commands on the session.
 */
class GameClient
{
    private const float CONNECT_TIMEOUT = 10.0;

    private const float LOGIN_TIMEOUT = 15.0;

    private const float INIT_TIMEOUT = 45.0;

    private const int MAX_REDIRECTS = 3;

    public function __construct(private readonly GslClient $gsl) {}

    /**
     * Log the account in, refreshing its tokens first when they are stale or rejected.
     *
     * Each login takes over the account's only live connection, so this kicks any
     * phone currently playing on it.
     *
     * @throws GameClientException
     */
    public function connect(GameAccount $account, bool $waitForInit = true): GameSession
    {
        try {
            $gate = $this->gsl->checkVersion((string) $account->zone);
            $servers = $account->needsTokenRefresh()
                ? $this->reissueTokens($account, $gate)
                : $this->gsl->serverList($gate, $account->device_id, (string) $account->zone, $account->game_uid);

            try {
                $session = $this->loginVia($account, $servers, $waitForInit);
            } catch (LoginRejectedException $e) {
                if (! $e->tokenRejected()) {
                    throw $e;
                }

                $session = $this->loginVia($account, $this->reissueTokens($account, $gate), $waitForInit);
            }
        } catch (GameClientException $e) {
            $account->update(['last_error' => $e->getMessage()]);

            throw $e;
        }

        $account->update([
            'zone' => $session->zone,
            'game_uid' => $session->gameUid,
            'last_login_at' => now(),
            'last_error' => null,
        ]);

        return $session;
    }

    /**
     * Call an extension command and wait for its reply.
     *
     * @throws GameClientException
     */
    public function command(GameSession $session, string $command, ?SfsObject $params = null, float $timeout = 15.0): SfsObject
    {
        $session->connection->sendExtension($command, $params);

        return $session->connection->waitForCommand([$command], $timeout);
    }

    /**
     * Log into the zone at the given gateway, following serverInfo redirects.
     *
     * @throws GameClientException
     */
    public function zoneLogin(
        string $hosts,
        int $port,
        string $zone,
        string $deviceId,
        string $gameUid,
        ?string $accessToken,
        bool $waitForInit = true,
    ): GameSession {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $connection = $this->dial($hosts, $port);

            $connection->sendEnvelope(Envelope::CONTROLLER_SYSTEM, Envelope::ACTION_LOGIN, (new SfsObject)
                ->putUtfString('zn', $zone)
                ->putUtfString('un', $gameUid)
                ->putUtfString('pw', '')
                ->putObject('p', LoginParams::build(
                    deviceId: $deviceId,
                    gameUid: $gameUid,
                    accessToken: $accessToken,
                    serverId: self::serverIdFromZone($zone),
                    appVersion: (string) config('services.lastwar.game.app_version'),
                    versionCode: (string) config('services.lastwar.game.version_code'),
                )));

            try {
                $reply = $connection->waitFor(fn (Envelope $e): bool => $e->isLoginReply(), self::LOGIN_TIMEOUT, 'the login reply')->content;
            } catch (GameClientException $e) {
                $connection->close();

                throw $e;
            }

            if ($reply->has('ec')) {
                $connection->close();

                throw self::loginRejection($reply);
            }

            $redirect = $reply->getObject('serverInfo') ?? $reply->getObject('p')?->getObject('serverInfo');

            if ($redirect !== null && filled($redirect->getString('ip'))) {
                $connection->close();
                $hosts = $redirect->getString('ip');
                $port = $redirect->getInt('port');
                $zone = $redirect->getString('zone') ?: $zone;
                $gameUid = $redirect->getString('uid') ?: $gameUid;

                continue;
            }

            return new GameSession(
                connection: $connection,
                zone: $zone,
                gameUid: $gameUid,
                loginReply: $reply,
                init: $waitForInit ? $this->waitForInit($connection) : null,
            );
        }

        throw new GameClientException('The game server redirected the login too many times.');
    }

    /**
     * Turn a zone like "APS783" into the numeric server id the login reports.
     */
    public static function serverIdFromZone(string $zone): string
    {
        return str_starts_with($zone, 'APS') ? substr($zone, 3) : $zone;
    }

    /**
     * Wait for the `init` push, asking for it explicitly halfway through.
     */
    private function waitForInit(GameConnection $connection): ?SfsObject
    {
        $deadline = microtime(true) + self::INIT_TIMEOUT;
        $halfway = microtime(true) + self::INIT_TIMEOUT / 2;
        $requested = false;

        while (microtime(true) < $deadline) {
            if (! $requested && microtime(true) >= $halfway) {
                $connection->sendExtension('login.init', (new SfsObject)->putUtfString('dataConfigMd5', ''));
                $requested = true;
            }

            $envelope = $connection->nextEnvelope(min(1.0, $deadline - microtime(true)));

            if ($envelope?->command() === 'init') {
                return $envelope->params();
            }
        }

        return null;
    }

    /**
     * Get fresh tokens by trading in the refresh token.
     *
     * The login key from the email bind is spent on the first token pair, so the
     * refresh token is the only renewable credential; once it is rejected the
     * device has to be bound again.
     *
     * @throws GameClientException
     */
    private function reissueTokens(GameAccount $account, GateHandshake $gate): ServerList
    {
        if (blank($account->refresh_token)) {
            throw new GateServerException('The game account has no refresh token. Bind it again with lastwar:game-bind.', '211');
        }

        try {
            $servers = $this->gsl->serverList($gate, $account->device_id, (string) $account->zone, $account->game_uid, 'refresh', refreshToken: $account->refresh_token)
                ->ensureSuccessful(requireServers: false);
        } catch (GateServerException $e) {
            if (! $e->requiresReauth()) {
                throw $e;
            }

            throw new GateServerException("The game account's refresh token was rejected. Bind it again with lastwar:game-bind.", $e->gateCode);
        }

        if ($servers->accessToken === null) {
            throw new GameClientException('The gate server issued no access token.');
        }

        $account->update(array_filter([
            'access_token' => $servers->accessToken,
            'access_token_issued_at' => $servers->accessTokenTime ? now()->setTimestamp($servers->accessTokenTime) : now(),
            'refresh_token' => $servers->refreshToken,
        ]));

        return $servers->servers === []
            ? $this->gsl->serverList($gate, $account->device_id, (string) $account->zone, $account->game_uid)
            : $servers;
    }

    private function loginVia(GameAccount $account, ServerList $servers, bool $waitForInit): GameSession
    {
        $server = $servers->ensureSuccessful()->pickServer($account->game_uid);

        return $this->zoneLogin(
            hosts: $server['ip'],
            port: $server['port'],
            zone: $server['zone'] ?: (string) $account->zone,
            deviceId: $account->device_id,
            gameUid: $account->game_uid,
            accessToken: $account->access_token,
            waitForInit: $waitForInit,
        );
    }

    /**
     * Connect to the first reachable host of a "|"-separated gateway list.
     *
     * @throws GameClientException
     */
    private function dial(string $hosts, int $port): GameConnection
    {
        if ($port <= 0) {
            throw new GameClientException('The gate server listed no port for the game server.');
        }

        $errors = [];

        foreach (array_filter(array_map(trim(...), explode('|', $hosts))) as $host) {
            $transport = app(GameTransport::class);

            try {
                $transport->connect($host, $port, self::CONNECT_TIMEOUT);

                return new GameConnection($transport);
            } catch (GameClientException $e) {
                $errors[] = $e->getMessage();
            }
        }

        throw new GameClientException($errors === [] ? 'The gate server listed no game server host.' : implode(' ', $errors));
    }

    private static function loginRejection(SfsObject $reply): LoginRejectedException
    {
        $ep = $reply->get('ep');
        $codes = match (true) {
            is_array($ep) => array_map(strval(...), $ep),
            $ep instanceof SfsArray => array_map(strval(...), array_filter($ep->toArray(), is_scalar(...))),
            is_scalar($ep) => [(string) $ep],
            default => [],
        };

        return new LoginRejectedException(
            sprintf('The game server rejected the login (ec=%s%s).', $reply->getString('ec'), $codes === [] ? '' : ', '.implode(', ', $codes)),
            array_values($codes),
        );
    }
}
