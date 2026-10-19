<?php

namespace App\Models;

use Database\Factories\GameAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Last War game account bound to this app as its own device.
 *
 * The login key is the long-lived credential from the email bind; the access and
 * refresh tokens are re-issued from it (or from each other) by the gate server.
 */
#[Fillable([
    'team_id', 'device_id', 'game_uid', 'zone', 'login_key', 'access_token', 'access_token_issued_at',
    'refresh_token', 'app_version', 'version_code', 'last_login_at', 'last_error',
])]
#[Hidden(['login_key', 'access_token', 'refresh_token'])]
class GameAccount extends Model
{
    /** @use HasFactory<GameAccountFactory> */
    use HasFactory;

    /**
     * Access tokens older than this are refreshed before logging in, as the game client does.
     */
    public const int ACCESS_TOKEN_MAX_AGE_DAYS = 30;

    /**
     * Get the team this account syncs for.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Whether the access token is missing or old enough that the gate server should re-issue it.
     */
    public function needsTokenRefresh(): bool
    {
        if (blank($this->access_token)) {
            return true;
        }

        return $this->access_token_issued_at !== null
            && $this->access_token_issued_at->lte(now()->subDays(self::ACCESS_TOKEN_MAX_AGE_DAYS));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'login_key' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_issued_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
