<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithGameAccount;
use App\LastWar\Game\GameAccountTransfer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lastwar:game-account-export {team : The team slug}')]
#[Description('Print the bound game account as a string that lastwar:game-account-import can load elsewhere')]
class GameAccountExport extends Command
{
    use InteractsWithGameAccount;

    /**
     * Execute the console command.
     */
    public function handle(GameAccountTransfer $transfer): int
    {
        $account = $this->resolveGameAccount();

        if ($account === null) {
            return self::FAILURE;
        }

        $this->warn('Anyone with this string can log into the game account. Treat it like a password.');
        $this->line($transfer->export($account));

        return self::SUCCESS;
    }
}
