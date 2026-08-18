<?php

use App\Models\Score;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * The most recent week this team has any scores for.
     *
     * Read from the data rather than counted back from today, so the card shows
     * the last week actually on record instead of emptying out on the days
     * between a week ending and its scores being entered. The raw column value
     * is kept as it comes back, so comparing it below needs no date casting.
     */
    #[Computed]
    public function weekStart(): ?string
    {
        return Score::query()
            ->whereRelation('member', 'team_id', $this->team->id)
            ->max('week_start');
    }

    /**
     * The three highest scorers of that week, ties broken by name.
     *
     * @return array<int, Score>
     */
    #[Computed]
    public function leaders(): array
    {
        if ($this->weekStart === null) {
            return [];
        }

        return Score::query()
            ->with('member')
            ->whereRelation('member', 'team_id', $this->team->id)
            ->where('week_start', $this->weekStart)
            ->get()
            ->sortBy([
                ['points', 'desc'],
                fn (Score $a, Score $b): int => strcasecmp($a->member->name, $b->member->name),
            ])
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * The VS week those scores cover, which runs Monday through Saturday.
     */
    #[Computed]
    public function weekRange(): ?string
    {
        if ($this->weekStart === null) {
            return null;
        }

        $start = CarbonImmutable::parse($this->weekStart);

        return $start->format('M j').' – '.$start->addDays(5)->format('M j');
    }

    /**
     * Badge colour for a placing: gold, silver, bronze.
     */
    public function placeColor(int $place): string
    {
        return match ($place) {
            1 => 'amber',
            2 => 'zinc',
            default => 'orange',
        };
    }
}; ?>

<div class="relative flex aspect-video flex-col overflow-hidden rounded-xl border border-neutral-200 p-5 dark:border-neutral-700" data-test="dashboard-top-vs-card">
    <div class="flex items-start justify-between gap-2">
        <div>
            <flux:heading size="lg">{{ __('Top Weekly VS') }}</flux:heading>
            <flux:subheading>
                {{ $this->weekRange ?? __('No scores recorded yet') }}
            </flux:subheading>
        </div>

        <flux:tooltip :content="__('View the full leaderboard')">
            <flux:button
                :href="route('scores.index')"
                wire:navigate
                variant="ghost"
                size="sm"
                icon="arrow-up-right"
                data-test="dashboard-top-vs-link"
            />
        </flux:tooltip>
    </div>

    <div class="mt-4 flex flex-1 flex-col justify-center gap-3">
        @forelse ($this->leaders as $index => $score)
            <div class="flex items-center gap-3" wire:key="top-vs-{{ $score->id }}" data-test="dashboard-top-vs-row">
                <flux:badge size="sm" :color="$this->placeColor($index + 1)" class="tabular-nums">
                    {{ $index + 1 }}
                </flux:badge>

                <flux:avatar
                    size="xs"
                    :name="$score->member->name"
                    :initials="strtoupper(substr($score->member->name, 0, 1))"
                />

                <span class="truncate font-medium">{{ $score->member->name }}</span>

                <span class="ms-auto shrink-0 text-sm text-zinc-500 tabular-nums dark:text-zinc-400">
                    {{ number_format($score->points) }}
                </span>
            </div>
        @empty
            <flux:text class="text-zinc-400 dark:text-zinc-500" data-test="dashboard-top-vs-empty">
                {{ __('Enter a week of VS scores to see the leaders here.') }}
            </flux:text>
        @endforelse
    </div>
</div>
