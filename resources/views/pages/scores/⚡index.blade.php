<?php

use App\Actions\Scores\SaveScore;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('VS Scores')] class extends Component
{
    public int $weekOffset = 0;

    public ?string $timezone = null;

    /** @var array<int, string> */
    public array $grid = [];

    public function mount(): void
    {
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
     * The roster with each member's score for the active week eager loaded.
     *
     * @return Collection<int, \App\Models\Member>
     */
    #[Computed]
    public function members(): Collection
    {
        $weekStart = $this->weekStart->toDateString();

        // Departed members stay listed for any week they actually scored in, so
        // historical rankings remain complete, but drop off the current week.
        return $this->team->roster()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->orWhereHas('scores', fn ($scores) => $scores->whereDate('week_start', $weekStart)))
            ->orderByDesc('position')
            ->orderBy('name')
            ->with(['scores' => fn ($query) => $query->whereDate('week_start', $weekStart)])
            ->get();
    }

    /**
     * Members ranked by their score for the active week.
     *
     * @return array<int, array{rank: int, member: \App\Models\Member, points: int}>
     */
    #[Computed]
    public function weeklyRanking(): array
    {
        return $this->rank(
            $this->members->map(fn ($member) => [
                'member' => $member,
                'points' => $member->scores->first()?->points ?? 0,
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

        foreach ($this->grid as $memberId => $value) {
            $member = $members->get($memberId);

            if ($member === null) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                if ($member->scores->isNotEmpty()) {
                    $saveScore->handle($member, $this->weekStart, null);
                }

                continue;
            }

            $saveScore->handle($member, $this->weekStart, (int) $value);
        }

        unset($this->members, $this->weeklyRanking);

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

    /**
     * Build validation rules for every non-empty grid cell.
     *
     * @return array<string, array<int, string>>
     */
    private function gridRules(): array
    {
        $rules = [];

        foreach ($this->grid as $memberId => $value) {
            if (trim((string) $value) !== '') {
                $rules["grid.{$memberId}"] = ['integer', 'min:0'];
            }
        }

        return $rules;
    }

    /**
     * Rebuild the editable grid from the freshly loaded roster scores.
     */
    private function refreshWeek(): void
    {
        unset($this->weekStart, $this->members, $this->weeklyRanking);
        $this->loadGrid();
    }

    private function loadGrid(): void
    {
        $grid = [];

        foreach ($this->members as $member) {
            $score = $member->scores->first();

            $grid[$member->id] = $score ? (string) $score->points : '';
        }

        $this->grid = $grid;
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
            <flux:subheading>{{ __('Track weekly scores and rankings') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-3">
            <flux:button
                :href="route('scores.import', ['weekOffset' => $weekOffset])"
                wire:navigate
                variant="filled"
                icon="photo"
                data-test="score-import-button"
            >
                {{ __('Import from screenshots') }}
            </flux:button>

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
                        <th class="px-4 py-3 text-right font-medium">{{ __('VS Score') }}</th>
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
                            <td class="px-4 py-2">
                                <div class="flex justify-end">
                                    <flux:input
                                        type="number"
                                        min="0"
                                        size="sm"
                                        class="w-40 text-right"
                                        wire:model.blur="grid.{{ $member->id }}"
                                        data-test="score-input-{{ $member->id }}"
                                    />
                                </div>
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

        <div class="mt-12">
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="weekly-leaderboard">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:heading size="lg">{{ __('Weekly leaderboard') }}</flux:heading>
                    <flux:subheading>{{ __('Total points for the week') }}</flux:subheading>
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
        </div>
    @endif
</section>
