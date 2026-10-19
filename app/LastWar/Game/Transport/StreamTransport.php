<?php

namespace App\LastWar\Game\Transport;

use App\LastWar\Game\Exceptions\GameClientException;

/**
 * Plain TCP transport (the game socket has no TLS) over PHP streams.
 */
class StreamTransport implements GameTransport
{
    /**
     * @var resource|null
     */
    private $socket = null;

    public function connect(string $host, int $port, float $timeout): void
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, $timeout);

        if ($socket === false) {
            throw new GameClientException("Could not connect to game server {$host}:{$port}: {$errorMessage} ({$errorCode}).");
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
    }

    public function write(string $bytes): void
    {
        $socket = $this->socket ?? throw new GameClientException('The game connection is not open.');

        while ($bytes !== '') {
            $write = [$socket];
            $read = $except = null;

            if (stream_select($read, $write, $except, 10) !== 1) {
                throw new GameClientException('Timed out writing to the game server.');
            }

            $written = @fwrite($socket, $bytes);

            if ($written === false) {
                throw new GameClientException('The game server closed the connection.');
            }

            $bytes = substr($bytes, $written);
        }
    }

    public function read(float $timeout): string
    {
        $socket = $this->socket ?? throw new GameClientException('The game connection is not open.');
        $read = [$socket];
        $write = $except = null;
        $seconds = (int) floor(max(0, $timeout));
        $microseconds = (int) ((max(0, $timeout) - $seconds) * 1_000_000);

        if (stream_select($read, $write, $except, $seconds, $microseconds) < 1) {
            return '';
        }

        $bytes = fread($socket, 65536);

        if ($bytes === false || ($bytes === '' && feof($socket))) {
            throw new GameClientException('The game server closed the connection.');
        }

        return $bytes;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
