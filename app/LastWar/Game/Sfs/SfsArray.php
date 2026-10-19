<?php

namespace App\LastWar\Game\Sfs;

/**
 * An ordered list of individually typed values: the SFS2X equivalent of a JSON array.
 */
final class SfsArray
{
    /**
     * @var list<SfsValue>
     */
    private array $items = [];

    public function add(SfsValue $value): self
    {
        $this->items[] = $value;

        return $this;
    }

    public function addObject(SfsObject $value): self
    {
        return $this->add(new SfsValue(SfsType::Object, $value));
    }

    /**
     * @return list<SfsValue>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Get only the object entries, skipping any other types.
     *
     * @return list<SfsObject>
     */
    public function objects(): array
    {
        return array_values(array_filter(
            array_map(fn (SfsValue $item): mixed => $item->value, $this->items),
            fn (mixed $value): bool => $value instanceof SfsObject,
        ));
    }

    /**
     * @return list<mixed>
     */
    public function toArray(): array
    {
        return array_map(fn (SfsValue $item): mixed => $item->toPhp(), $this->items);
    }
}
