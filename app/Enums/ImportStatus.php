<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case AwaitingReview = 'awaiting_review';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::AwaitingReview => 'Awaiting review',
            default => ucfirst($this->value),
        };
    }

    /**
     * Get the Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Processing => 'blue',
            self::AwaitingReview => 'amber',
            self::Completed => 'green',
            self::Failed => 'red',
        };
    }
}
