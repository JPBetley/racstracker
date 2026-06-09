<?php

namespace App\Enums;

enum MemberPosition: string
{
    case R5 = 'R5';
    case R4 = 'R4';
    case R3 = 'R3';
    case R2 = 'R2';
    case R1 = 'R1';

    /**
     * Get the display label for the position.
     */
    public function label(): string
    {
        return $this->value;
    }

    /**
     * Get the maximum number of members allowed at this position per team.
     *
     * Returns null when the position is uncapped.
     */
    public function maxPerTeam(): ?int
    {
        return match ($this) {
            self::R5 => 1,
            self::R4 => 10,
            default => null,
        };
    }

    /**
     * Get all positions formatted for a select input.
     *
     * @return array<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $position) => ['value' => $position->value, 'label' => $position->label()])
            ->values()
            ->toArray();
    }
}
