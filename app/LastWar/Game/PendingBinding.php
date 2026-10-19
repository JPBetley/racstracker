<?php

namespace App\LastWar\Game;

use App\LastWar\Game\Gsl\GateHandshake;

/**
 * A guest session waiting for the emailed verification code.
 */
final readonly class PendingBinding
{
    public function __construct(
        public GameSession $session,
        public GateHandshake $gate,
        public string $deviceId,
        public string $email,
    ) {}

    /**
     * Keep the guest session alive while the user fetches the code.
     */
    public function keepAlive(): void
    {
        $this->session->connection->keepAlive();
    }

    public function cancel(): void
    {
        $this->session->close();
    }
}
