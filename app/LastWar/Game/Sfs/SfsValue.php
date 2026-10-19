<?php

namespace App\LastWar\Game\Sfs;

/**
 * A single value tagged with the SFS2X type it is encoded as.
 *
 * The game server checks types strictly (an activity id must be a UTF string, not
 * an int), so values keep their wire type rather than being inferred from PHP types.
 */
final readonly class SfsValue
{
    public function __construct(
        public SfsType $type,
        public mixed $value,
    ) {}

    /**
     * Convert the value to plain PHP data, recursing into nested objects and arrays.
     */
    public function toPhp(): mixed
    {
        return match (true) {
            $this->value instanceof SfsObject, $this->value instanceof SfsArray => $this->value->toArray(),
            $this->type === SfsType::ByteArray => base64_encode($this->value),
            default => $this->value,
        };
    }
}
