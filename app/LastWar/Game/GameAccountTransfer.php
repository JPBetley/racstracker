<?php

namespace App\LastWar\Game;

use App\Models\GameAccount;
use App\Models\Team;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Moves a bound game account between environments as one pasteable string.
 *
 * Binding needs a live terminal to type the emailed code, which hosts like Laravel
 * Cloud cannot provide, so the account is bound locally and carried over. The login
 * key from the bind is single-use and already spent, so the current access and
 * refresh tokens travel with the device identity; the receiving environment logs in
 * with them and renews them from then on. Once moved, the source environment must
 * stop logging in: the first refresh on either side rotates the tokens out from
 * under the other. Anyone holding the string can log into the account, so treat it
 * as a password.
 */
class GameAccountTransfer
{
    private const string PREFIX = 'lwga2.';

    /**
     * @var list<string>
     */
    private const array FIELDS = ['device_id', 'game_uid', 'zone', 'login_key', 'access_token', 'refresh_token', 'app_version', 'version_code'];

    /**
     * @throws InvalidArgumentException when the account has no refresh token to carry.
     */
    public function export(GameAccount $account): string
    {
        if (blank($account->refresh_token)) {
            throw new InvalidArgumentException('The game account has no refresh token to export. Bind it again with lastwar:game-bind.');
        }

        $payload = array_combine(self::FIELDS, array_map(fn (string $field): string => (string) $account->{$field}, self::FIELDS));
        $payload['access_token_issued_at'] = $account->access_token_issued_at?->getTimestamp();

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
        $issuedAt = $payload['access_token_issued_at'] ?? null;

        return GameAccount::updateOrCreate(['team_id' => $team->id], [
            ...array_map(fn (mixed $value): ?string => blank($value) ? null : (string) $value, array_intersect_key($payload, array_flip(self::FIELDS))),
            'access_token_issued_at' => is_numeric($issuedAt) ? Carbon::createFromTimestamp((int) $issuedAt) : null,
            'last_login_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function decode(string $data): array
    {
        if (str_starts_with($data, 'lwga1.')) {
            throw new InvalidArgumentException('This export is from an older version that cannot log in. Export it again with lastwar:game-account-export.');
        }

        if (! str_starts_with($data, self::PREFIX)) {
            throw new InvalidArgumentException('This is not a game account export (it should start with "'.self::PREFIX.'").');
        }

        $json = base64_decode(strtr(substr($data, strlen(self::PREFIX)), '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('The game account export is corrupt; copy it again in full.');
        }

        $missing = array_filter(['device_id', 'game_uid', 'login_key', 'refresh_token'], fn (string $field): bool => blank($payload[$field] ?? null));

        if ($missing !== []) {
            throw new InvalidArgumentException('The game account export is missing: '.implode(', ', $missing).'.');
        }

        return $payload;
    }
}
