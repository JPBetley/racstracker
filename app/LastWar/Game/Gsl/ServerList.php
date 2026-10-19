<?php

namespace App\LastWar\Game\Gsl;

use App\LastWar\Game\Exceptions\GateServerException;

/**
 * A decoded `getserverlist.php` reply: the account's game servers and, depending on
 * the request, a fresh access/refresh token pair.
 */
final readonly class ServerList
{
    /**
     * @param  list<array{id: string, ip: string, port: int, zone: string, game_uid: string}>  $servers
     */
    public function __construct(
        public string $code,
        public array $servers,
        public string $lastLoggedServer = '',
        public ?string $accessToken = null,
        public ?int $accessTokenTime = null,
        public ?string $refreshToken = null,
    ) {}

    /**
     * Decode the reply JSON.
     *
     * A brand-new guest (`opt=new`) gets an empty server list plus a `loginServer`
     * entry, which the game client dials instead.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, bool $fallBackToLoginServer = false): self
    {
        $listed = is_array($payload['serverList'] ?? null) ? $payload['serverList'] : [];

        if ($listed === [] && $fallBackToLoginServer && is_array($payload['loginServer'] ?? null)) {
            $listed = [$payload['loginServer']];
        }

        $servers = collect($listed)
            ->filter(fn ($server): bool => is_array($server))
            ->map(fn (array $server): array => [
                'id' => (string) ($server['id'] ?? ''),
                'ip' => (string) ($server['ip'] ?? ''),
                'port' => (int) ($server['port'] ?? 0),
                'zone' => (string) ($server['zone'] ?? ''),
                'game_uid' => (string) ($server['gameUid'] ?? ''),
            ])
            ->values()
            ->all();

        $at = is_array($payload['at'] ?? null) ? $payload['at'] : [];
        $rt = is_array($payload['rt'] ?? null) ? $payload['rt'] : [];

        return new self(
            code: trim((string) ($payload['code'] ?? '')),
            servers: $servers,
            lastLoggedServer: trim((string) ($payload['lastLoggedServer'] ?? '')),
            accessToken: filled($at['token'] ?? null) ? (string) $at['token'] : null,
            accessTokenTime: is_numeric($at['time'] ?? null) ? (int) $at['time'] : null,
            refreshToken: filled($rt['token'] ?? null) ? (string) $rt['token'] : null,
        );
    }

    /**
     * Throw unless the reply succeeded.
     *
     * A token refresh may legitimately list no servers, so that check is optional.
     *
     * @throws GateServerException
     */
    public function ensureSuccessful(bool $requireServers = true): self
    {
        $ok = in_array($this->code, ['', '0'], true);

        if (! $ok || ($requireServers && $this->servers === [])) {
            throw GateServerException::fromCode($this->code);
        }

        return $this;
    }

    /**
     * Pick the server hosting the given role, else the last one logged into, else the first.
     *
     * @return array{id: string, ip: string, port: int, zone: string, game_uid: string}|null
     */
    public function pickServer(?string $gameUid): ?array
    {
        $servers = collect($this->servers);

        return ($gameUid ? $servers->firstWhere('game_uid', $gameUid) : null)
            ?? (! in_array($this->lastLoggedServer, ['', '0'], true) ? $servers->firstWhere('id', $this->lastLoggedServer) : null)
            ?? $servers->first();
    }
}
