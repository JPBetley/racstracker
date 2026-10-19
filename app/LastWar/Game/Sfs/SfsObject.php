<?php

namespace App\LastWar\Game\Sfs;

/**
 * An ordered, typed key/value map: the SFS2X equivalent of a JSON object.
 *
 * Key order is preserved because it is part of the encoded bytes.
 */
final class SfsObject
{
    /**
     * @var array<string, SfsValue>
     */
    private array $values = [];

    public function put(string $key, SfsValue $value): self
    {
        $this->values[$key] = $value;

        return $this;
    }

    public function putNull(string $key): self
    {
        return $this->put($key, new SfsValue(SfsType::Null, null));
    }

    public function putBool(string $key, bool $value): self
    {
        return $this->put($key, new SfsValue(SfsType::Bool, $value));
    }

    public function putByte(string $key, int $value): self
    {
        return $this->put($key, new SfsValue(SfsType::Byte, $value));
    }

    public function putShort(string $key, int $value): self
    {
        return $this->put($key, new SfsValue(SfsType::Short, $value));
    }

    public function putInt(string $key, int $value): self
    {
        return $this->put($key, new SfsValue(SfsType::Int, $value));
    }

    public function putLong(string $key, int $value): self
    {
        return $this->put($key, new SfsValue(SfsType::Long, $value));
    }

    public function putDouble(string $key, float $value): self
    {
        return $this->put($key, new SfsValue(SfsType::Double, $value));
    }

    public function putUtfString(string $key, string $value): self
    {
        return $this->put($key, new SfsValue(SfsType::UtfString, $value));
    }

    public function putObject(string $key, SfsObject $value): self
    {
        return $this->put($key, new SfsValue(SfsType::Object, $value));
    }

    public function putArray(string $key, SfsArray $value): self
    {
        return $this->put($key, new SfsValue(SfsType::Array, $value));
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function value(string $key): ?SfsValue
    {
        return $this->values[$key] ?? null;
    }

    /**
     * Get the raw PHP value stored under a key.
     */
    public function get(string $key): mixed
    {
        return $this->values[$key]?->value ?? null;
    }

    /**
     * Read a scalar as a string, whichever numeric or string type the server used.
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Read a number as an int, whichever integer width or numeric string the server used.
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function getObject(string $key): ?SfsObject
    {
        $value = $this->get($key);

        return $value instanceof SfsObject ? $value : null;
    }

    public function getArray(string $key): ?SfsArray
    {
        $value = $this->get($key);

        return $value instanceof SfsArray ? $value : null;
    }

    /**
     * Convert the object to a plain PHP array, recursing into nested values.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_map(fn (SfsValue $value): mixed => $value->toPhp(), $this->values);
    }
}
