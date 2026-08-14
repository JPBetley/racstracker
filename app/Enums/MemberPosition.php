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
     * Map a Last War API alliance rank integer onto a roster position.
     *
     * The API numbers ranks the same way the game does, so rank N is simply RN:
     * 5 is the leader and 1 is a new member. Its documentation calls the field
     * "Alliance rank (1=R1 Leader)", which reads as though 1 were the leader, but
     * a live roster shows otherwise — exactly one member at rank 5 and exactly
     * ten at rank 4, matching the R5 and R4 caps.
     *
     * Unknown or out-of-range ranks fall back to R1, the uncapped position, so a
     * schema change on their side can never breach a position cap here.
     */
    public static function fromApiRank(int $rank): self
    {
        return match ($rank) {
            5 => self::R5,
            4 => self::R4,
            3 => self::R3,
            2 => self::R2,
            default => self::R1,
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
