<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithGameAccount;
use App\LastWar\Game\GameAccountTransfer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('lastwar:game-account-import
    {team : The team slug}
    {--data= : The string printed by lastwar:game-account-export (defaults to LASTWAR_GAME_ACCOUNT)}
    {--force : Replace the team\'s existing game account}')]
#[Description('Bind a team to a game account exported from another environment, without the interactive email code')]
class GameAccountImport extends Command
{
    use InteractsWithGameAccount;

    /**
     * Execute the console command.
     *
     * Runs without prompts so it works from hosted consoles such as Laravel Cloud's
     * Commands tab. The export may also come from the LASTWAR_GAME_ACCOUNT variable,
     * which keeps it out of the command history.
     */
    public function handle(GameAccountTransfer $transfer): int
    {
        $team = $this->resolveTeam();

        if ($team === null) {
            return self::FAILURE;
        }

        $data = $this->option('data') ?: config('services.lastwar.game.account_import');

        if (blank($data)) {
            $this->error('Pass the export with --data= or set LASTWAR_GAME_ACCOUNT.');

            return self::FAILURE;
        }

        if ($team->gameAccount !== null && ! $this->option('force')) {
            $this->error("Team [{$team->slug}] already has a game account. Pass --force to replace it.");

            return self::FAILURE;
        }

        try {
            $account = $transfer->import($team, $data);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Bound game account {$account->game_uid} on {$account->zone} to team [{$team->slug}].");
        $this->line('Its own tokens are issued on the first login. Remove LASTWAR_GAME_ACCOUNT if you set it.');

        return self::SUCCESS;
    }
}
