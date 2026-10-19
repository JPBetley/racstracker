<?php

namespace App\LastWar\Game\Exceptions;

/**
 * The server ended the session on purpose: logged in elsewhere, maintenance, or init failure.
 */
class SessionEndedException extends GameClientException
{
    public function __construct(public readonly string $push)
    {
        parent::__construct(match ($push) {
            'push.user.off' => 'The game session was ended because the account logged in elsewhere.',
            'push.server.stop' => 'The game server is stopping for maintenance.',
            default => "The game server ended the session ({$push}).",
        });
    }
}
