<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithGameAccount;
use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\GameClient;
use App\LastWar\Game\Sfs\SfsObject;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('lastwar:game-command
    {team : The team slug}
    {cmd : The extension command, e.g. al.rank}
    {--param=* : A parameter as key=value (a UTF string) or key:int|long|bool|double=value}
    {--timeout=15 : Seconds to wait for the reply}
    {--force : Allow commands that do not look read-only}')]
#[Description('Send one extension command as the bound game account and dump the decoded reply')]
class GameCommand extends Command
{
    use InteractsWithGameAccount;

    /**
     * Command names that only read state. Anything else could spend resources or
     * change the account, so it requires --force.
     */
    private const string READ_ONLY_PATTERN = '/(^|\.)(get|info|list|rank|ranking|rankings|detail|query|show|search)(\.|$)/i';

    /**
     * Execute the console command.
     */
    public function handle(GameClient $client): int
    {
        $command = (string) $this->argument('cmd');

        if (! preg_match(self::READ_ONLY_PATTERN, $command) && ! $this->option('force')) {
            $this->error("[{$command}] does not look read-only. Pass --force if you are sure it changes nothing.");

            return self::FAILURE;
        }

        try {
            $params = self::parseParams($this->option('param'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

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

        if ($session->init === null) {
            $this->warn('No init push arrived; the server may ignore commands on this session.');
        }

        try {
            $reply = $client->command($session, $command, $params, (float) $this->option('timeout'));
        } catch (GameClientException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $session->close();
        }

        $this->printJson($reply->toArray());

        return self::SUCCESS;
    }

    /**
     * Build typed parameters from `key=value` and `key:type=value` strings.
     *
     * @param  list<string>  $pairs
     *
     * @throws InvalidArgumentException
     */
    public static function parseParams(array $pairs): SfsObject
    {
        $params = new SfsObject;

        foreach ($pairs as $pair) {
            if (! preg_match('/^([^:=]+)(?::(string|int|long|bool|double))?=(.*)$/s', $pair, $matches)) {
                throw new InvalidArgumentException("Invalid parameter [{$pair}]. Use key=value or key:type=value.");
            }

            [, $key, $type, $value] = $matches;

            match ($type ?: 'string') {
                'string' => $params->putUtfString($key, $value),
                'int' => $params->putInt($key, (int) $value),
                'long' => $params->putLong($key, (int) $value),
                'bool' => $params->putBool($key, filter_var($value, FILTER_VALIDATE_BOOLEAN)),
                'double' => $params->putDouble($key, (float) $value),
            };
        }

        return $params;
    }
}
