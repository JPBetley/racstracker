<?php

namespace App\LastWar\Game;

use App\LastWar\Game\Sfs\SfsObject;

/**
 * One SFS2X message: `{c: controller, a: action, p: content}`.
 */
final readonly class Envelope
{
    public const int CONTROLLER_SYSTEM = 0;

    public const int CONTROLLER_EXTENSION = 1;

    public const int ACTION_LOGIN = 1;

    public const int ACTION_CALL_EXTENSION = 13;

    public const int ACTION_PING_PONG = 29;

    public function __construct(
        public int $controller,
        public int $action,
        public SfsObject $content,
    ) {}

    public static function fromObject(SfsObject $object): self
    {
        return new self(
            $object->getInt('c'),
            $object->getInt('a'),
            $object->getObject('p') ?? new SfsObject,
        );
    }

    /**
     * The extension command name, for extension messages.
     */
    public function command(): ?string
    {
        return $this->controller === self::CONTROLLER_EXTENSION ? $this->content->getString('c') : null;
    }

    /**
     * The extension command's parameters.
     */
    public function params(): SfsObject
    {
        return $this->content->getObject('p') ?? new SfsObject;
    }

    public function isLoginReply(): bool
    {
        return $this->controller === self::CONTROLLER_SYSTEM && $this->action === self::ACTION_LOGIN;
    }

    public function isPing(): bool
    {
        return $this->controller === self::CONTROLLER_SYSTEM && $this->action === self::ACTION_PING_PONG;
    }
}
