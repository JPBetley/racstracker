<?php

namespace App\Jobs\Imports\Concerns;

use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Exceptions\GateServerException;
use App\LastWar\Game\Exceptions\LoginRejectedException;
use App\LastWar\Game\Exceptions\SessionEndedException;

/**
 * Retry policy for import steps that log into the game.
 *
 * Every attempt is a fresh login that kicks whoever is playing the account, so a
 * using step allows one spaced-out retry ($tries = 2, $backoff = [300]) and fails
 * outright on anything a retry could not fix.
 */
trait RetriesGameFailures
{
    /**
     * Whether a retry could get a different answer than this failure.
     *
     * Dead credentials and rejected logins fail the same way every time, and the
     * owner logging back in is no reason to kick them off again.
     */
    protected static function isWorthRetrying(GameClientException $e): bool
    {
        return match (true) {
            $e instanceof GateServerException => ! $e->requiresReauth(),
            $e instanceof LoginRejectedException => false,
            $e instanceof SessionEndedException => $e->push !== 'push.user.off',
            default => true,
        };
    }
}
