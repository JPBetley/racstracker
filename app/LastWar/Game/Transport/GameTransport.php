<?php

namespace App\LastWar\Game\Transport;

use App\LastWar\Game\Exceptions\GameClientException;

/**
 * A raw byte stream to a game server.
 */
interface GameTransport
{
    /**
     * @throws GameClientException
     */
    public function connect(string $host, int $port, float $timeout): void;

    /**
     * @throws GameClientException
     */
    public function write(string $bytes): void;

    /**
     * Read whatever bytes arrive within the timeout, or an empty string if none do.
     *
     * @throws GameClientException when the connection has closed.
     */
    public function read(float $timeout): string;

    public function close(): void;
}
