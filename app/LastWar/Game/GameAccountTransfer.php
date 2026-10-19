<?php

namespace App\LastWar\Game;

use App\Models\GameAccount;
use App\Models\Team;
use InvalidArgumentException;

/**
 * Moves a bound game account between environments as one pasteable string.
 *
 * Binding needs a live terminal to type the emailed code, which hosts like Laravel
 * Cloud cannot provide, so the account is bound locally and carried over. Only the
 * device identity and the long-lived login key travel: the receiving environment
 * trades the login key for its own access and refresh tokens on first login.
 * Anyone holding the string can log into the account, so treat it as a password.
 */
class GameAccountTransfer
{
    private const string PREFIX = 'lwga1.';

    /**
     * @var list<string>
     */
    private const array FIELDS = ['device_id', 'game_uid', 'zone', 'login_key', 'app_version', 'version_code'];

    public function export(GameAccount $account): string
    {
        $payload = array_combine(self::FIELDS, array_map(fn (string $field): string => (string) $account->{$field}, self::FIELDS));

        return self::PREFIX.rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    /**
     * Store an exported account for the team, replacing any it already has.
     *
     * @throws InvalidArgumentException when the string is not a valid export.
     */
    public function import(Team $team, string $data): GameAccount
    {
        $payload = $this->decode(trim($data));

        return GameAccount::updateOrCreate(['team_id' => $team->id], [
            ...$payload,
            'access_token' => null,
            'access_token_issued_at' => null,
            'refresh_token' => null,
            'last_login_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * @return array<string, string>
     *
     * @throws InvalidArgumentException
     */
    private function decode(string $data): array
    {
        if (! str_starts_with($data, self::PREFIX)) {
            throw new InvalidArgumentException('This is not a game account export (it should start with "'.self::PREFIX.'").');
        }

        $json = base64_decode(strtr(substr($data, strlen(self::PREFIX)), '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('The game account export is corrupt; copy it again in full.');
        }

        $missing = array_filter(['device_id', 'game_uid', 'login_key'], fn (string $field): bool => blank($payload[$field] ?? null));

        if ($missing !== []) {
            throw new InvalidArgumentException('The game account export is missing: '.implode(', ', $missing).'.');
        }

        return array_map(strval(...), array_intersect_key($payload, array_flip(self::FIELDS)));
    }
}
