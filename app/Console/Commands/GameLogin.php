<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithGameAccount;
use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\GameClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lastwar:game-login {team : The team slug}')]
#[Description('Log the bound game account in and summarize the session (kicks the account off other devices)')]
class GameLogin extends Command
{
    use InteractsWithGameAccount;

    /**
     * Execute the console command.
     */
    public function handle(GameClient $client): int
    {
        $account = $this->resolveGameAccount();

        if ($account === null) {
            return self::FAILURE;
        }

        try {
            $session = $client->connect($account);
        } catch (GameClientException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $session->close();

        $this->info('Logged in.');
        $this->table(['Field', 'Value'], [
            ['Zone', $session->zone],
            ['Game uid', $session->gameUid],
            ['Alliance id', $session->allianceId() ?? '—'],
            ['Init received', $session->init === null ? 'no' : 'yes'],
        ]);

        if ($session->init !== null) {
            $this->line('Init keys: '.implode(', ', $session->init->keys()));
        }

        return self::SUCCESS;
    }
}
