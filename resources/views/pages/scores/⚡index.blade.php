<?php

use App\Actions\Scores\SaveScore;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('VS Scores')] class extends Component
{
    public int $weekOffset = 0;

    public int $selectedDay = 1;

    public ?string $timezone = null;

    /** @var array<int, array<int, string>> */
    public array $grid = [];

    public function mount(): void
    {
        $this->selectedDay = $this->defaultSelectedDay();
        $this->loadGrid();
    }

    /**
     * Set the active timezone from the browser and re-evaluate the current week.
     */
    public function setTimezone(string $timezone): void
    {
        if ($timezone === $this->timezone || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return;
        }

        $this->timezone = $timezone;
        $this->selectedDay = $this->defaultSelectedDay();
        $this->refreshWeek();
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function weekStart(): CarbonImmutable
    {
        return $this->now()
            ->startOfWeek(CarbonInterface::MONDAY)
            ->addWeeks($this->weekOffset);
    }

    /**
     * The six scoring days (Monday through Saturday) for the active week.
     *
     * @return array<int, array{day: int, date: CarbonImmutable, label: string}>
     */
    #[Computed]
    public function days(): array
    {
        return collect(range(1, 6))
            ->map(fn (int $day) => [
                'day' => $day,
                'date' => $this->weekStart->addDays($day - 1),
                'label' => __('Day :number', ['number' => $day]),
            ])
            ->all();
    }

    /**
     * The roster with each member's scores for the active week eager loaded.
     *
     * @return Collection<int, \App\Models\Member>
     */
    #[Computed]
    public function members(): Collection
    {
        // whereBetween accepts a CarbonPeriod directly and matches the stored datetimes (incl. Saturday).
        $week = CarbonPeriod::create($this->weekStart, $this->weekStart->addDays(5));

        // Departed members stay listed for any week they actually scored in, so
        // historical rankings remain complete, but drop off the current week.
        return $this->team->roster()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->orWhereHas('scores', fn ($scores) => $scores->whereBetween('date', $week)))
            ->orderByDesc('position')
            ->orderBy('name')
            ->with(['scores' => fn ($query) => $query->whereBetween('date', $week)])
            ->get();
    }

    /**
     * Members ranked by their total points across the active week.
     *
     * @return array<int, array{rank: int, member: \App\Models\Member, points: int}>
     */
    #[Computed]
    public function weeklyRanking(): array
    {
        return $this->rank(
            $this->members->map(fn ($member) => [
                'member' => $member,
                'points' => $member->scores->sum('points'),
            ])
        );
    }

    /**
     * Members ranked by their points on the selected day.
     *
     * @return array<int, array{rank: int, member: \App\Models\Member, points: int}>
     */
    #[Computed]
    public function dailyRanking(): array
    {
        $date = $this->weekStart->addDays($this->selectedDay - 1)->toDateString();

        return $this->rank(
            $this->members->map(fn ($member) => [
                'member' => $member,
                'points' => optional($member->scores->first(fn ($score) => $score->date->toDateString() === $date))->points ?? 0,
            ])
        );
    }

    /**
     * Persist every entered score for the active week.
     *
     * Cells with a value are upserted; cleared cells that previously held a
     * score are removed. Entries for members outside the roster are ignored.
     */
    public function saveWeek(SaveScore $saveScore): void
    {
        $rules = $this->gridRules();

        if ($rules !== []) {
            $this->validate($rules);
        }

        $members = $this->members->keyBy('id');

        foreach ($this->grid as $memberId => $days) {
            $member = $members->get($memberId);

            if ($member === null) {
                continue;
            }

            foreach ($days as $day => $value) {
                $value = trim((string) $value);
                $date = $this->weekStart->addDays((int) $day - 1);
                $existing = $member->scores->first(fn ($score) => $score->date->toDateString() === $date->toDateString());

                if ($value === '') {
                    if ($existing !== null) {
                        $saveScore->handle($member, $date, null);
                    }

                    continue;
                }

                $saveScore->handle($member, $date, (int) $value);
            }
        }

        unset($this->members, $this->weeklyRanking, $this->dailyRanking);

        Flux::toast(variant: 'success', text: __('VS Scores saved.'));
    }

    public function previousWeek(): void
    {
        $this->weekOffset--;
        $this->refreshWeek();
    }

    public function nextWeek(): void
    {
        if ($this->weekOffset >= 0) {
            return;
        }

        $this->weekOffset++;
        $this->refreshWeek();
    }

    public function selectDay(int $day): void
    {
        $this->selectedDay = max(1, min(6, $day));
    }

    /**
     * The live week total for a member, summed from the editable grid.
     */
    public function rowTotal(int $memberId): int
    {
        return collect($this->grid[$memberId] ?? [])
            ->sum(fn ($value) => is_numeric($value) ? (int) $value : 0);
    }

    /**
     * Build validation rules for every non-empty grid cell.
     *
     * @return array<string, array<int, string>>
     */
    private function gridRules(): array
    {
        $rules = [];

        foreach ($this->grid as $memberId => $days) {
            foreach ($days as $day => $value) {
                if (trim((string) $value) !== '') {
                    $rules["grid.{$memberId}.{$day}"] = ['integer', 'min:0'];
                }
            }
        }

        return $rules;
    }

    /**
     * Rebuild the editable grid from the freshly loaded roster scores.
     */
    private function refreshWeek(): void
    {
        unset($this->weekStart, $this->members, $this->weeklyRanking, $this->dailyRanking);
        $this->loadGrid();
    }

    private function loadGrid(): void
    {
        $grid = [];

        foreach ($this->members as $member) {
            foreach (range(1, 6) as $day) {
                $date = $this->weekStart->addDays($day - 1)->toDateString();
                $score = $member->scores->first(fn ($score) => $score->date->toDateString() === $date);

                $grid[$member->id][$day] = $score ? (string) $score->points : '';
            }
        }

        $this->grid = $grid;
    }

    private function defaultSelectedDay(): int
    {
        $isoDay = $this->now()->dayOfWeekIso;

        return $isoDay === 7 ? 6 : $isoDay;
    }

    /**
     * The current moment in the user's timezone, falling back to the app default.
     */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone ?? config('app.timezone'));
    }

    /**
     * Order entries by points (then name) and assign sequential ranks.
     *
     * @param  \Illuminate\Support\Collection<int, array{member: \App\Models\Member, points: int}>  $entries
     * @return array<int, array{rank: int, member: \App\Models\Member, points: int}>
     */
    private function rank($entries): array
    {
        return $entries
            ->sortBy([
                ['points', 'desc'],
                fn ($a, $b) => strcasecmp($a['member']->name, $b['member']->name),
            ])
            ->values()
            ->map(fn (array $entry, int $index) => [...$entry, 'rank' => $index + 1])
            ->all();
    }
}; ?>

<section class="w-full" x-data x-init="$wire.setTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone)">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('VS Scores') }}</flux:heading>
            <flux:subheading>{{ __('Track daily scores and weekly rankings') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-3">
            <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousWeek" data-test="score-prev-week" />

            <div class="text-center text-sm font-medium" data-test="score-week-range">
                {{ $this->weekStart->format('M j') }} – {{ $this->weekStart->addDays(5)->format('M j') }}
            </div>

            <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextWeek" :disabled="$weekOffset >= 0" data-test="score-next-week" />
        </div>
    </div>

    @if ($this->members->isEmpty())
        <div class="mt-6 rounded-lg border border-dashed border-zinc-200 p-8 text-center dark:border-zinc-700" data-test="scores-empty">
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No members yet. Add roster members before tracking scores.') }}</flux:text>
        </div>
    @else
        <div class="mt-6 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                        <th class="px-4 py-3 text-left font-medium">{{ __('Member') }}</th>
                        @foreach ($this->days as $day)
                            <th class="px-2 py-3 text-center font-medium" wire:key="head-{{ $day['day'] }}">
                                <button
                                    type="button"
                                    wire:click="selectDay({{ $day['day'] }})"
                                    class="rounded px-2 py-1 {{ $selectedDay === $day['day'] ? 'bg-zinc-200 dark:bg-zinc-700' : '' }}"
                                    data-test="score-day-header-{{ $day['day'] }}"
                                >
                                    {{ $day['label'] }}
                                </button>
                            </th>
                        @endforeach
                        <th class="px-4 py-3 text-right font-medium">{{ __('Week') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->members as $member)
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800" wire:key="row-{{ $member->id }}" data-test="score-row">
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-3">
                                    <flux:avatar size="sm" :name="$member->name" :initials="strtoupper(substr($member->name, 0, 1))" />
                                    <span class="font-medium">{{ $member->name }}</span>
                                    <flux:badge color="zinc" size="sm">{{ $member->position->label() }}</flux:badge>
                                </div>
                            </td>
                            @foreach ($this->days as $day)
                                <td class="px-2 py-2" wire:key="cell-{{ $member->id }}-{{ $day['day'] }}">
                                    <flux:input
                                        type="number"
                                        min="0"
                                        size="sm"
                                        class="w-24 text-right"
                                        wire:model.blur="grid.{{ $member->id }}.{{ $day['day'] }}"
                                        data-test="score-input-{{ $member->id }}-{{ $day['day'] }}"
                                    />
                                </td>
                            @endforeach
                            <td class="px-4 py-2 text-right font-semibold tabular-nums" data-test="score-week-total-{{ $member->id }}">
                                {{ number_format($this->rowTotal($member->id)) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex justify-end">
            <flux:button variant="primary" icon="check" wire:click="saveWeek" data-test="score-save-button">
                {{ __('Save VS Scores') }}
            </flux:button>
        </div>

        <div class="mt-12 grid gap-6 md:grid-cols-2">
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="weekly-leaderboard">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:heading size="lg">{{ __('Weekly leaderboard') }}</flux:heading>
                    <flux:subheading>{{ __('Total points, Monday through Saturday') }}</flux:subheading>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->weeklyRanking as $entry)
                        <div class="flex items-center justify-between px-4 py-3" wire:key="weekly-{{ $entry['member']->id }}">
                            <div class="flex items-center gap-3">
                                <span class="w-6 text-center text-zinc-500 tabular-nums dark:text-zinc-400">{{ $entry['rank'] }}</span>
                                <span class="font-medium">{{ $entry['member']->name }}</span>
                                <flux:badge color="zinc" size="sm">{{ $entry['member']->position->label() }}</flux:badge>
                            </div>
                            <span class="font-semibold tabular-nums">{{ number_format($entry['points']) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="daily-leaderboard">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:heading size="lg">{{ __('Daily leaderboard') }}</flux:heading>
                    <flux:subheading>{{ $this->weekStart->addDays($selectedDay - 1)->format('l, M j') }}</flux:subheading>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->dailyRanking as $entry)
                        <div class="flex items-center justify-between px-4 py-3" wire:key="daily-{{ $entry['member']->id }}">
                            <div class="flex items-center gap-3">
                                <span class="w-6 text-center text-zinc-500 tabular-nums dark:text-zinc-400">{{ $entry['rank'] }}</span>
                                <span class="font-medium">{{ $entry['member']->name }}</span>
                                <flux:badge color="zinc" size="sm">{{ $entry['member']->position->label() }}</flux:badge>
                            </div>
                            <span class="font-semibold tabular-nums">{{ number_format($entry['points']) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</section>
