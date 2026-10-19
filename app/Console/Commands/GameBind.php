<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithGameAccount;
use App\LastWar\Game\DeviceBinding;
use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\PendingBinding;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lastwar:game-bind {team : The team slug} {--email= : The email the game account is linked to}')]
#[Description('Bind this app to a Last War game account as its own device, via an emailed code')]
class GameBind extends Command
{
    use InteractsWithGameAccount;

    /**
     * How long to keep the guest session open while waiting for the code.
     */
    private const int CODE_WAIT_SECONDS = 600;

    /**
     * Execute the console command.
     */
    public function handle(DeviceBinding $binding): int
    {
        $team = $this->resolveTeam();

        if ($team === null) {
            return self::FAILURE;
        }

        $email = $this->option('email') ?: $this->ask('Email linked to the game account');

        if (blank($email)) {
            $this->error('An email address is required.');

            return self::FAILURE;
        }

        $this->warn('The account will receive a "New Device Alert" email, and every login from this app');
        $this->warn('kicks whoever is currently playing on the account (including your phone).');

        if (! $this->confirm('Continue?', true)) {
            return self::FAILURE;
        }

        try {
            $pending = $binding->begin($email);
        } catch (GameClientException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("A verification code was sent to {$email}.");
        $code = $this->readCode($pending);

        if (blank($code)) {
            $pending->cancel();
            $this->error('No verification code entered.');

            return self::FAILURE;
        }

        try {
            $account = $binding->complete($pending, $code, $team);
        } catch (GameClientException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Bound game account {$account->game_uid} on {$account->zone} to team [{$team->slug}].");

        return self::SUCCESS;
    }

    /**
     * Read the code, keeping the guest session alive while the user checks their email.
     *
     * Blocking on a prompt would let the server drop the idle socket, so a terminal
     * is polled instead; non-terminal input falls back to an ordinary prompt.
     */
    private function readCode(PendingBinding $pending): ?string
    {
        if (! stream_isatty(STDIN)) {
            return $this->ask('Verification code');
        }

        $this->output->write('Verification code: ');
        $deadline = time() + self::CODE_WAIT_SECONDS;

        while (time() < $deadline) {
            $read = [STDIN];
            $write = $except = null;

            if (stream_select($read, $write, $except, 1) === 1) {
                $line = fgets(STDIN);

                return $line === false ? null : trim($line);
            }

            $pending->keepAlive();
        }

        return null;
    }
}
