<?php

namespace App\LastWar\Game\Sfs;

use App\LastWar\Game\Exceptions\GameClientException;

/**
 * Binary encoder/decoder for SFS2X objects.
 *
 * Everything is big-endian. Strings carry a u16 byte length (TEXT a u32), arrays an
 * i16 count, except BYTE_ARRAY which uses an i32 count.
 */
final class SfsCodec
{
    private const int MAX_DEPTH = 64;

    private string $data = '';

    private int $position = 0;

    public static function encode(SfsObject $object): string
    {
        return chr(SfsType::Object->value).self::encodeObjectPayload($object);
    }

    /**
     * @throws GameClientException
     */
    public static function decode(string $data): SfsObject
    {
        $reader = new self;
        $reader->data = $data;

        $tag = $reader->readByte();

        if ($tag !== SfsType::Object->value) {
            throw new GameClientException("Expected a top-level SFS object (tag 18), got tag {$tag}.");
        }

        $object = $reader->readObject(0);

        if ($reader->position !== strlen($data)) {
            throw new GameClientException(sprintf('%d trailing bytes after the decoded SFS object.', strlen($data) - $reader->position));
        }

        return $object;
    }

    private static function encodeObjectPayload(SfsObject $object): string
    {
        $out = self::count16(count($object->keys()));

        foreach ($object->keys() as $key) {
            $out .= self::utf($key).self::encodeTagged($object->value($key));
        }

        return $out;
    }

    private static function encodeTagged(SfsValue $value): string
    {
        return chr($value->type->value).self::encodePayload($value);
    }

    private static function encodePayload(SfsValue $value): string
    {
        $v = $value->value;

        return match ($value->type) {
            SfsType::Null => '',
            SfsType::Bool => $v ? "\x01" : "\x00",
            SfsType::Byte => chr($v & 0xFF),
            SfsType::Short => pack('n', $v & 0xFFFF),
            SfsType::Int => pack('N', $v & 0xFFFFFFFF),
            SfsType::Long => pack('J', $v),
            SfsType::Float => pack('G', $v),
            SfsType::Double => pack('E', $v),
            SfsType::UtfString => self::utf($v),
            SfsType::Text => pack('N', strlen($v)).$v,
            SfsType::ByteArray => pack('N', strlen($v)).$v,
            SfsType::BoolArray => self::count16(count($v)).implode('', array_map(fn (bool $b): string => $b ? "\x01" : "\x00", $v)),
            SfsType::ShortArray => self::count16(count($v)).implode('', array_map(fn (int $n): string => pack('n', $n & 0xFFFF), $v)),
            SfsType::IntArray => self::count16(count($v)).implode('', array_map(fn (int $n): string => pack('N', $n & 0xFFFFFFFF), $v)),
            SfsType::LongArray => self::count16(count($v)).implode('', array_map(fn (int $n): string => pack('J', $n), $v)),
            SfsType::FloatArray => self::count16(count($v)).implode('', array_map(fn (float $n): string => pack('G', $n), $v)),
            SfsType::DoubleArray => self::count16(count($v)).implode('', array_map(fn (float $n): string => pack('E', $n), $v)),
            SfsType::UtfStringArray => self::count16(count($v)).implode('', array_map(self::utf(...), $v)),
            SfsType::Array => self::count16(count($v->items())).implode('', array_map(self::encodeTagged(...), $v->items())),
            SfsType::Object => self::encodeObjectPayload($v),
        };
    }

    private static function utf(string $value): string
    {
        if (strlen($value) > 32767) {
            throw new GameClientException('String too long to encode as an SFS UTF string.');
        }

        return pack('n', strlen($value)).$value;
    }

    private static function count16(int $count): string
    {
        if ($count > 32767) {
            throw new GameClientException("Too many entries ({$count}) to encode in an SFS collection.");
        }

        return pack('n', $count);
    }

    private function readObject(int $depth): SfsObject
    {
        $this->guardDepth($depth);
        $object = new SfsObject;

        for ($i = 0, $count = $this->readCount(); $i < $count; $i++) {
            $key = $this->readUtf();
            $object->put($key, $this->readTagged($depth));
        }

        return $object;
    }

    private function readTagged(int $depth): SfsValue
    {
        $tag = $this->readByte();
        $type = SfsType::tryFrom($tag) ?? throw new GameClientException("Unsupported SFS type tag {$tag}.");

        return new SfsValue($type, match ($type) {
            SfsType::Null => null,
            SfsType::Bool => $this->readByte() !== 0,
            SfsType::Byte => $this->readByte(),
            SfsType::Short => $this->readShort(),
            SfsType::Int => $this->readInt(),
            SfsType::Long => $this->readLong(),
            SfsType::Float => $this->readFloat(),
            SfsType::Double => $this->readDouble(),
            SfsType::UtfString => $this->readUtf(),
            SfsType::Text, SfsType::ByteArray => $this->read($this->readUnsignedInt()),
            SfsType::BoolArray => $this->readList(fn (): bool => $this->readByte() !== 0),
            SfsType::ShortArray => $this->readList($this->readShort(...)),
            SfsType::IntArray => $this->readList($this->readInt(...)),
            SfsType::LongArray => $this->readList($this->readLong(...)),
            SfsType::FloatArray => $this->readList($this->readFloat(...)),
            SfsType::DoubleArray => $this->readList($this->readDouble(...)),
            SfsType::UtfStringArray => $this->readList($this->readUtf(...)),
            SfsType::Array => $this->readArray($depth + 1),
            SfsType::Object => $this->readObject($depth + 1),
        });
    }

    private function readArray(int $depth): SfsArray
    {
        $this->guardDepth($depth);
        $array = new SfsArray;

        for ($i = 0, $count = $this->readCount(); $i < $count; $i++) {
            $array->add($this->readTagged($depth));
        }

        return $array;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $readItem
     * @return list<T>
     */
    private function readList(callable $readItem): array
    {
        $items = [];

        for ($i = 0, $count = $this->readCount(); $i < $count; $i++) {
            $items[] = $readItem();
        }

        return $items;
    }

    private function guardDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new GameClientException('SFS object nesting is too deep.');
        }
    }

    private function read(int $length): string
    {
        if ($length < 0 || $this->position + $length > strlen($this->data)) {
            throw new GameClientException('Unexpected end of SFS data.');
        }

        $bytes = substr($this->data, $this->position, $length);
        $this->position += $length;

        return $bytes;
    }

    private function readByte(): int
    {
        return ord($this->read(1));
    }

    private function readCount(): int
    {
        $count = $this->readShort();

        if ($count < 0) {
            throw new GameClientException("Negative SFS collection size {$count}.");
        }

        return $count;
    }

    private function readShort(): int
    {
        $n = unpack('n', $this->read(2))[1];

        return $n >= 0x8000 ? $n - 0x10000 : $n;
    }

    private function readUnsignedShort(): int
    {
        return unpack('n', $this->read(2))[1];
    }

    private function readInt(): int
    {
        $n = $this->readUnsignedInt();

        return $n >= 0x80000000 ? $n - 0x100000000 : $n;
    }

    private function readUnsignedInt(): int
    {
        return unpack('N', $this->read(4))[1];
    }

    private function readLong(): int
    {
        return unpack('J', $this->read(8))[1];
    }

    private function readFloat(): float
    {
        return unpack('G', $this->read(4))[1];
    }

    private function readDouble(): float
    {
        return unpack('E', $this->read(8))[1];
    }

    private function readUtf(): string
    {
        return $this->read($this->readUnsignedShort());
    }
}
