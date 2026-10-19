<?php

namespace Tests\Support;

use App\LastWar\Game\Envelope;
use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Sfs\PacketCodec;
use App\LastWar\Game\Sfs\SfsCodec;
use App\LastWar\Game\Sfs\SfsObject;
use App\LastWar\Game\Transport\GameTransport;

/**
 * A scripted game server: each connect() plays back the next script of server frames.
 */
class FakeGameTransport implements GameTransport
{
    /**
     * @var list<list<string>>
     */
    private array $scripts = [];

    /**
     * @var list<string>
     */
    private array $inbound = [];

    /**
     * @var list<string>
     */
    public array $connectedTo = [];

    /**
     * @var list<Envelope>
     */
    public array $sent = [];

    /**
     * @var list<string>
     */
    public array $unreachableHosts = [];

    private string $written = '';

    /**
     * Queue the frames the server sends on the next connection.
     *
     * @param  list<Envelope>  $envelopes
     */
    public function script(array $envelopes): self
    {
        $this->scripts[] = array_map(self::frame(...), $envelopes);

        return $this;
    }

    public function connect(string $host, int $port, float $timeout): void
    {
        if (in_array($host, $this->unreachableHosts, true)) {
            throw new GameClientException("Could not connect to {$host}.");
        }

        $this->connectedTo[] = "{$host}:{$port}";
        $this->inbound = array_shift($this->scripts) ?? [];
    }

    public function write(string $bytes): void
    {
        $this->written .= $bytes;

        while (($body = PacketCodec::extractFrame($this->written)) !== null) {
            $this->sent[] = Envelope::fromObject(SfsCodec::decode($body));
        }
    }

    public function read(float $timeout): string
    {
        return array_shift($this->inbound) ?? '';
    }

    public function close(): void {}

    /**
     * Extension commands the client sent, in order.
     *
     * @return list<string>
     */
    public function sentCommands(): array
    {
        return array_values(array_filter(array_map(fn (Envelope $e): ?string => $e->command(), $this->sent)));
    }

    /**
     * The content of the most recent zone login the client sent.
     */
    public function loginRequest(): ?SfsObject
    {
        $logins = array_filter($this->sent, fn (Envelope $e): bool => $e->isLoginReply());

        return $logins === [] ? null : end($logins)->content;
    }

    /**
     * The parameters of the first extension command with the given name.
     */
    public function sentParams(string $command): ?SfsObject
    {
        foreach ($this->sent as $envelope) {
            if ($envelope->command() === $command) {
                return $envelope->params();
            }
        }

        return null;
    }

    public static function loginReply(SfsObject $content): Envelope
    {
        return new Envelope(Envelope::CONTROLLER_SYSTEM, Envelope::ACTION_LOGIN, $content);
    }

    public static function extension(string $command, SfsObject $params): Envelope
    {
        return new Envelope(Envelope::CONTROLLER_EXTENSION, Envelope::ACTION_CALL_EXTENSION, (new SfsObject)
            ->putUtfString('c', $command)
            ->putObject('p', $params));
    }

    /**
     * Encode an envelope as the server frames it: binary, unencrypted.
     */
    private static function frame(Envelope $envelope): string
    {
        $body = SfsCodec::encode((new SfsObject)
            ->putByte('c', $envelope->controller)
            ->putShort('a', $envelope->action)
            ->putObject('p', $envelope->content));

        return chr(PacketCodec::BINARY).pack('n', strlen($body)).$body;
    }
}
