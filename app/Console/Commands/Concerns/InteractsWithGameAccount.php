<?php

namespace App\Console\Commands\Concerns;

use App\Models\GameAccount;
use App\Models\Team;

/**
 * Shared helpers for the native game client commands.
 */
trait InteractsWithGameAccount
{
    /**
     * Find the team named by the `team` argument, reporting when it is missing.
     */
    protected function resolveTeam(): ?Team
    {
        $team = Team::where('slug', $this->argument('team'))->first();

        if ($team === null) {
            $this->error("No team found with slug [{$this->argument('team')}].");
        }

        return $team;
    }

    /**
     * Find the team's bound game account, reporting when there is none.
     */
    protected function resolveGameAccount(): ?GameAccount
    {
        $team = $this->resolveTeam();

        if ($team === null) {
            return null;
        }

        if ($team->gameAccount === null) {
            $this->error("Team [{$team->slug}] has no bound game account. Run lastwar:game-bind first.");
        }

        return $team->gameAccount;
    }

    /**
     * Print decoded game data as pretty JSON.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function printJson(array $data): void
    {
        $this->line((string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }
}
