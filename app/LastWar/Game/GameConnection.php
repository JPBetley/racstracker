<?php

namespace App\LastWar\Game;

use App\LastWar\Game\Exceptions\CommandFailedException;
use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Exceptions\SessionEndedException;
use App\LastWar\Game\Sfs\PacketCodec;
use App\LastWar\Game\Sfs\SfsCodec;
use App\LastWar\Game\Sfs\SfsObject;
use App\LastWar\Game\Transport\GameTransport;
use Closure;

/**
 * An open socket to a game server, speaking SFS2X envelopes.
 *
 * PHP has no background heartbeat, so a ping is sent whenever one is due while the
 * connection is being read; long idle waits must call keepAlive() periodically.
 */
class GameConnection
{
    private const float HEARTBEAT_INTERVAL = 4.0;

    private const array TERMINAL_PUSHES = ['push.user.off', 'push.server.stop', 'init.error'];

    private string $buffer = '';

    private int $nextExtensionId = 2;

    private float $startedAt;

    private float $lastPingAt = 0.0;

    public function __construct(private readonly GameTransport $transport)
    {
        $this->startedAt = microtime(true);
    }

    public function sendEnvelope(int $controller, int $action, SfsObject $content): void
    {
        $outer = (new SfsObject)
            ->putByte('c', $controller)
            ->putShort('a', $action)
            ->putObject('p', $content);

        $this->transport->write(PacketCodec::encode(SfsCodec::encode($outer)));
    }

    /**
     * Call an extension command. Replies come back as extension messages named after it.
     */
    public function sendExtension(string $command, ?SfsObject $params = null): void
    {
        $params ??= new SfsObject;

        if (! $params->has('_id')) {
            $params->putInt('_id', $this->nextExtensionId++);
        }

        $this->sendEnvelope(Envelope::CONTROLLER_EXTENSION, Envelope::ACTION_CALL_EXTENSION, (new SfsObject)
            ->putUtfString('c', $command)
            ->putInt('r', -1)
            ->putObject('p', $params));
    }

    /**
     * Read envelopes until one matches, discarding the rest.
     *
     * @param  Closure(Envelope): bool  $matches
     *
     * @throws GameClientException
     */
    public function waitFor(Closure $matches, float $timeout, string $waitingFor = 'a reply'): Envelope
    {
        $deadline = microtime(true) + $timeout;
        $received = [];

        while (($remaining = $deadline - microtime(true)) > 0) {
            $envelope = $this->nextEnvelope($remaining);

            if ($envelope === null) {
                continue;
            }

            if ($matches($envelope)) {
                return $envelope;
            }

            $received[] = $envelope->command() ?? "c{$envelope->controller}/a{$envelope->action}";
        }

        throw new GameClientException(sprintf(
            'Timed out after %ss waiting for %s. Received meanwhile: %s.',
            $timeout,
            $waitingFor,
            $received === [] ? 'nothing' : implode(', ', array_slice(array_unique($received), 0, 20)),
        ));
    }

    /**
     * Wait for any of the named extension messages and return its parameters.
     *
     * @param  list<string>  $commands
     *
     * @throws CommandFailedException when the reply carries an errorCode.
     */
    public function waitForCommand(array $commands, float $timeout): SfsObject
    {
        $envelope = $this->waitFor(
            fn (Envelope $envelope): bool => in_array($envelope->command(), $commands, true),
            $timeout,
            implode(' or ', $commands),
        );

        $params = $envelope->params();

        if ($params->has('errorCode')) {
            throw new CommandFailedException((string) $envelope->command(), $params->getString('errorCode'));
        }

        return $params;
    }

    /**
     * Send a heartbeat if one is due and drain anything the server pushed meanwhile.
     */
    public function keepAlive(): void
    {
        $this->nextEnvelope(0.0);
    }

    /**
     * Read the next envelope, or null if none arrives within the timeout.
     *
     * @throws SessionEndedException
     */
    public function nextEnvelope(float $timeout): ?Envelope
    {
        $deadline = microtime(true) + $timeout;
        $hasRead = false;

        while (true) {
            $this->heartbeatIfDue();

            $body = PacketCodec::extractFrame($this->buffer);

            if ($body !== null) {
                $envelope = Envelope::fromObject(SfsCodec::decode($body));

                if ($envelope->isPing()) {
                    continue;
                }

                if (in_array($envelope->command(), self::TERMINAL_PUSHES, true)) {
                    throw new SessionEndedException((string) $envelope->command());
                }

                return $envelope;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0 && $hasRead) {
                return null;
            }

            $untilPing = $this->lastPingAt + self::HEARTBEAT_INTERVAL - microtime(true);
            $this->buffer .= $this->transport->read(max(0.0, min($remaining, $untilPing)));
            $hasRead = true;
        }
    }

    public function close(): void
    {
        $this->transport->close();
    }

    private function heartbeatIfDue(): void
    {
        $now = microtime(true);

        if ($now - $this->lastPingAt < self::HEARTBEAT_INTERVAL) {
            return;
        }

        $this->lastPingAt = $now;
        $this->sendEnvelope(Envelope::CONTROLLER_SYSTEM, Envelope::ACTION_PING_PONG, (new SfsObject)
            ->putLong('clientTime', (int) (($now - $this->startedAt) * 1000)));
    }
}
