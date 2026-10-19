<?php

namespace App\LastWar\Game\Exceptions;

/**
 * An extension command came back with an `errorCode`.
 */
class CommandFailedException extends GameClientException
{
    public function __construct(public readonly string $command, public readonly string $errorCode)
    {
        parent::__construct("Game command [{$command}] failed with error code [{$errorCode}].");
    }
}
