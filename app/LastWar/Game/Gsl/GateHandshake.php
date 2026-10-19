<?php

namespace App\LastWar\Game\Gsl;

/**
 * The check-version result: which gate host answered and the RSA key it handed out.
 */
final readonly class GateHandshake
{
    public function __construct(
        public string $host,
        public string $publicKeyPem,
        public string $updateType = '',
    ) {}

    /**
     * Whether the gate server says this client build must update before it can play.
     */
    public function forcesUpdate(): bool
    {
        return $this->updateType === '2';
    }
}
