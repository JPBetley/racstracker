<?php

namespace App\LastWar\Game\Exceptions;

/**
 * The gate server (GSL) refused a server-list or token request.
 */
class GateServerException extends GameClientException
{
    public function __construct(string $message, public readonly string $gateCode = '')
    {
        parent::__construct($message);
    }

    /**
     * Build an exception from a GSL reply code.
     */
    public static function fromCode(string $code): self
    {
        $reason = match ($code) {
            '201' => 'not available',
            '211' => 'the access, refresh, or login key was rejected',
            '212' => 'the credentials no longer map to this account and server',
            '213' => 'the request envelope could not be decrypted',
            '', '0' => 'no servers were listed',
            default => 'the request was rejected',
        };

        return new self("Gate server code [{$code}]: {$reason}.", $code);
    }

    /**
     * Whether the stored credentials are dead and the device must be bound again.
     */
    public function requiresReauth(): bool
    {
        return in_array($this->gateCode, ['211', '212'], true);
    }
}
