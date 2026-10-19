<?php

namespace App\LastWar\Game\Exceptions;

/**
 * The game server refused the zone login (`ec` in the login reply).
 */
class LoginRejectedException extends GameClientException
{
    /**
     * @param  list<string>  $codes  The `ep` error parameters, such as E011 or E005.
     */
    public function __construct(string $message, public readonly array $codes = [])
    {
        parent::__construct($message);
    }

    /**
     * Whether the access token was rejected (E011) and should be refreshed.
     */
    public function tokenRejected(): bool
    {
        return in_array('E011', $this->codes, true);
    }
}
