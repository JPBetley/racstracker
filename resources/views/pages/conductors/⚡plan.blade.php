<?php

use App\Actions\Conductors\SaveWeekPlan;
use App\Models\ConductorAssignment;
use App\Models\Member;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plan Conductor Week')] class extends Component
{
    public int $step = 1;

    public ?string $timezone = null;

    /**
     * Desert Storm participation, keyed by member ID.
     *
     * Deliberately not persisted. Nothing in the schema or the game API records
     * who took part, so it is asked for once per planning run and only ever
     * narrows the candidate list for that run.
     *
     * @var array<int, bool>
     */
    public array $desertStorm = [];

    /**
     * The conductor picked for each of the six non-MVP days, keyed by `Y-m-d`.
     *
     * @var array<string, int|null>
     */
    public array $selections = [];

    public ?int $mvpMemberId = null;

    public function mount(): void
    {
        $this->seedDesertStorm();
    }

    /**
     * Set the active timezone from the browser and re-evaluate the week.
     */
    public function setTimezone(string $timezone): void
    {
        if ($timezone === $this->timezone || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return;
        }

        $this->timezone = $timezone;
        $this->refreshWeek();
        $this->seedDesertStorm();
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * The current date in the viewer's timezone, falling back to the app default.
     */
    #[Computed]
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::today($this->timezone ?? config('app.timezone'));
    }

    /**
     * The Sunday the planned week opens on.
     *
     * Planning happens on a Sunday, so that is the day itself; opening the wizard
     * mid-week plans the Sunday still to come rather than one already gone.
     */
    #[Computed]
    public function planStart(): CarbonImmutable
    {
        return $this->today->isSunday()
            ? $this->today
            : $this->today->next(CarbonInterface::SUNDAY);
    }

    /**
     * The Monday of the VS week whose scores decide eligibility.
     *
     * Sunday is the last day of an ISO week, so anchoring the plan's opening
     * Sunday to its own week start lands on the Monday of the week that has just
     * finished — exactly the scores entered that morning.
     */
    #[Computed]
    public function scoreWeekStart(): CarbonImmutable
    {
        return $this->planStart->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * The six days after the MVP Sunday, Monday through Saturday.
     *
     * @return array<int, CarbonImmutable>
     */
    #[Computed]
    public function conductorDays(): array
    {
        return array_map(
            fn (int $offset): CarbonImmutable => $this->planStart->addDays($offset),
            range(1, 6),
        );
    }

    #[Computed]
    public function desertStormRequired(): bool
    {
        return $this->team->train_desert_storm_requirement;
    }

    /**
     * The active roster with the deciding week's score and last conducted day.
     *
     * @return EloquentCollection<int, Member>
     */
    #[Computed]
    public function roster(): EloquentCollection
    {
        return $this->team->roster()
            ->active()
            ->with(['aliases', 'scores' => fn ($query) => $query->whereDate('week_start', $this->scoreWeekStart->toDateString())])
            ->withMax('conductorAssignments', 'assigned_on')
            ->orderBy('name')
            ->get();
    }

    /**
     * Every active member measured against the train VS requirement.
     *
     * Ordered by score, highest first, so the cut-off line falls in one place in
     * the table rather than being scattered through an alphabetical roster.
     *
     * A member with no score for the week counts as zero, which still clears a
     * requirement of zero — that setting means "no requirement".
     *
     * @return array<int, array{member: Member, points: int, lastConductedOn: ?CarbonImmutable, passes: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $requirement = $this->team->train_vs_requirement;

        return $this->roster
            ->map(function (Member $member) use ($requirement): array {
                $points = $member->scores->first()?->points ?? 0;
                $lastConducted = $member->conductor_assignments_max_assigned_on;

                return [
                    'member' => $member,
                    'points' => $points,
                    'lastConductedOn' => $lastConducted ? CarbonImmutable::parse($lastConducted) : null,
                    'passes' => $points >= $requirement,
                ];
            })
            ->sortBy([
                ['points', 'desc'],
                fn (array $a, array $b): int => strcasecmp($a['member']->name, $b['member']->name),
            ])
            ->values()
            ->all();
    }

    /**
     * The members who cleared the VS requirement.
     *
     * @return array<int, array{member: Member, points: int, lastConductedOn: ?CarbonImmutable, passes: bool}>
     */
    #[Computed]
    public function eligible(): array
    {
        return array_values(array_filter($this->rows, fn (array $row): bool => $row['passes']));
    }

    /**
     * The final candidate list, longest-waiting first.
     *
     * A member who has never conducted sorts ahead of everyone: never is the
     * oldest a last turn can be.
     *
     * @return array<int, array{member: Member, points: int, lastConductedOn: ?CarbonImmutable, passes: bool}>
     */
    #[Computed]
    public function candidates(): array
    {
        $entries = collect($this->eligible);

        if ($this->desertStormRequired) {
            $entries = $entries->filter(fn (array $row): bool => (bool) ($this->desertStorm[$row['member']->id] ?? false));
        }

        return $entries
            ->sortBy([
                fn (array $a, array $b): int => ($a['lastConductedOn']?->toDateString() ?? '') <=> ($b['lastConductedOn']?->toDateString() ?? ''),
                fn (array $a, array $b): int => strcasecmp($a['member']->name, $b['member']->name),
            ])
            ->values()
            ->all();
    }

    /**
     * Days in the planned week that already have a conductor on record.
     *
     * The range is fetched a day wide and narrowed in PHP: the date cast writes a
     * full timestamp, so a bare `Y-m-d` upper bound drops the final day on SQLite.
     *
     * @return EloquentCollection<string, ConductorAssignment>
     */
    #[Computed]
    public function existingDays(): EloquentCollection
    {
        $planned = collect([$this->planStart, ...$this->conductorDays])
            ->map(fn (CarbonImmutable $day): string => $day->toDateString());

        return $this->team->conductorAssignments()
            ->with('member')
            ->whereBetween('assigned_on', [$this->planStart->toDateString(), $this->planStart->addDays(7)->toDateString()])
            ->get()
            ->filter(fn (ConductorAssignment $assignment): bool => $planned->contains($assignment->assigned_on->toDateString()))
            ->keyBy(fn (ConductorAssignment $assignment): string => $assignment->assigned_on->toDateString());
    }

    /**
     * The candidates still free to take a given day.
     *
     * @return array<int, array{member: Member, points: int, lastConductedOn: ?CarbonImmutable, passes: bool}>
     */
    public function availableFor(string $date): array
    {
        $taken = collect($this->selections)->forget($date)->filter()->all();

        return array_values(array_filter(
            $this->candidates,
            fn (array $row): bool => ! in_array($row['member']->id, $taken, true),
        ));
    }

    public function nextStep(): void
    {
        $target = $this->step + 1;

        // Nothing to ask on the Desert Storm step when the team does not require it.
        if ($target === 2 && ! $this->desertStormRequired) {
            $target = 3;
        }

        if ($target >= 4) {
            $this->seedSelections();
        }

        $this->step = min($target, 4);
    }

    public function previousStep(): void
    {
        $target = $this->step - 1;

        if ($target === 2 && ! $this->desertStormRequired) {
            $target = 1;
        }

        $this->step = max($target, 1);
        $this->resetValidation();
    }

    public function selectAllDesertStorm(): void
    {
        $this->setDesertStorm(true);
    }

    public function clearDesertStorm(): void
    {
        $this->setDesertStorm(false);
    }

    /**
     * Write the planned week, amending any day already on record.
     */
    public function save(SaveWeekPlan $saveWeekPlan): void
    {
        $team = $this->team;

        $memberOfTeam = ['nullable', 'integer', Rule::exists('members', 'id')->where('team_id', $team->id)];

        $rules = ['mvpMemberId' => ['required', 'integer', Rule::exists('members', 'id')->where('team_id', $team->id)]];

        foreach (array_keys($this->selections) as $date) {
            $rules["selections.{$date}"] = $memberOfTeam;
        }

        $this->validate($rules, [
            'mvpMemberId.required' => __('Select the MVP for Sunday.'),
            'mvpMemberId.exists' => __('Select a member of your roster.'),
            'selections.*.exists' => __('Select a member of your roster.'),
        ]);

        $duplicates = collect($this->selections)->push($this->mvpMemberId)->filter()->duplicates();

        if ($duplicates->isNotEmpty()) {
            $this->addError('selections', __('A member can only conduct once in a week.'));

            return;
        }

        $assignments = $this->selections;
        $assignments[$this->planStart->toDateString()] = $this->mvpMemberId;

        $written = $saveWeekPlan->handle($team, $this->planStart, $assignments);

        Flux::toast(variant: 'success', text: trans_choice('{1} :count day scheduled.|[2,*] :count days scheduled.', $written, ['count' => $written]));

        $this->redirectRoute('conductors.index', navigate: true);
    }

    /**
     * Assume everyone eligible took part, leaving the user to deselect the few
     * who did not rather than tick the whole alliance every week.
     */
    private function seedDesertStorm(): void
    {
        $this->setDesertStorm(true);
    }

    private function setDesertStorm(bool $participated): void
    {
        $this->desertStorm = collect($this->eligible)
            ->mapWithKeys(fn (array $row): array => [$row['member']->id => $participated])
            ->all();

        unset($this->candidates);
    }

    /**
     * Fill the six days from the candidate list, longest-waiting first.
     *
     * Picks the user has already made are kept as long as the member is still a
     * candidate, so stepping back to revise Desert Storm does not throw away the
     * rest of the week.
     */
    private function seedSelections(): void
    {
        $candidateIds = collect($this->candidates)->pluck('member.id');

        $selections = [];

        foreach ($this->conductorDays as $day) {
            $date = $day->toDateString();
            $current = $this->selections[$date] ?? null;
            $selections[$date] = $candidateIds->contains($current) ? $current : null;
        }

        $taken = collect($selections)->filter();
        $available = $candidateIds->reject(fn (int $id): bool => $taken->contains($id))->values();

        $next = 0;

        foreach ($selections as $date => $memberId) {
            if ($memberId !== null) {
                continue;
            }

            $selections[$date] = $available->get($next);
            $next++;
        }

        $this->selections = $selections;
    }

    private function refreshWeek(): void
    {
        unset(
            $this->today,
            $this->planStart,
            $this->scoreWeekStart,
            $this->conductorDays,
            $this->roster,
            $this->rows,
            $this->eligible,
            $this->candidates,
            $this->existingDays,
        );
    }
}; ?>

<section class="w-full" x-data x-init="$wire.setTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone)">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Plan the week') }}</flux:heading>
            <flux:subheading>
                {{ __('Conductors for :start – :end', [
                    'start' => $this->planStart->format('M j'),
                    'end' => $this->planStart->addDays(6)->format('M j'),
                ]) }}
            </flux:subheading>
        </div>

        <flux:button :href="route('conductors.index')" wire:navigate variant="ghost" icon="x-mark" data-test="plan-cancel-button">
            {{ __('Cancel') }}
        </flux:button>
    </div>

    @php
        $steps = [
            1 => __('VS threshold'),
            2 => __('Desert Storm'),
            3 => __('Candidates'),
            4 => __('Assign days'),
        ];
    @endphp

    <div class="mt-6 flex flex-wrap items-center gap-2" data-test="plan-steps">
        @foreach ($steps as $number => $label)
            @continue($number === 2 && ! $this->desertStormRequired)

            <flux:badge
                size="sm"
                :color="match (true) { $step === $number => 'green', $step > $number => 'blue', default => 'zinc' }"
                :icon="$step > $number ? 'check' : null"
                data-test="plan-step-{{ $number }}"
            >
                {{ $number }}. {{ $label }}
            </flux:badge>
        @endforeach
    </div>

    {{-- Step 1: who clears the VS requirement --}}
    @if ($step === 1)
        <div class="mt-6" data-test="plan-threshold-step">
            <flux:text class="text-zinc-500 dark:text-zinc-400">
                {{ $this->team->train_vs_requirement > 0
                    ? __('Scores for :start – :end measured against the :points point requirement.', [
                        'start' => $this->scoreWeekStart->format('M j'),
                        'end' => $this->scoreWeekStart->addDays(5)->format('M j'),
                        'points' => number_format($this->team->train_vs_requirement),
                    ])
                    : __('No train VS requirement is set, so the whole active roster is eligible.') }}
            </flux:text>

            @if ($this->roster->isEmpty())
                <div class="mt-4 rounded-lg border border-dashed border-zinc-200 p-8 text-center dark:border-zinc-700" data-test="plan-roster-empty">
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No active members to plan with.') }}</flux:text>
                </div>
            @else
                <div class="mt-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                                <th class="px-4 py-3 text-left font-medium">{{ __('Member') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ __('VS Score') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ __('Eligible') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->rows as $row)
                                <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800" wire:key="threshold-{{ $row['member']->id }}" data-test="plan-threshold-row">
                                    <td class="px-4 py-2">
                                        <div class="flex items-center gap-3">
                                            <flux:avatar size="sm" :name="$row['member']->name" :initials="strtoupper(substr($row['member']->name, 0, 1))" />
                                            <span class="font-medium">{{ $row['member']->name }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ number_format($row['points']) }}</td>
                                    <td class="px-4 py-2">
                                        <div class="flex justify-end">
                                            <flux:badge size="sm" :color="$row['passes'] ? 'green' : 'red'" data-test="plan-threshold-badge">
                                                {{ $row['passes'] ? __('Pass') : __('Below') }}
                                            </flux:badge>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="mt-4 flex items-center justify-between">
                <flux:text class="text-zinc-500 dark:text-zinc-400" data-test="plan-eligible-count">
                    {{ trans_choice('{1} :count member clears the requirement.|[2,*] :count members clear the requirement.', count($this->eligible), ['count' => count($this->eligible)]) }}
                </flux:text>

                <flux:button variant="primary" icon-trailing="arrow-right" wire:click="nextStep" data-test="plan-next-button">
                    {{ __('Next') }}
                </flux:button>
            </div>
        </div>

    {{-- Step 2: who took part in Desert Storm --}}
    @elseif ($step === 2)
        <div class="mt-6" data-test="plan-desert-storm-step">
            <div class="flex items-center justify-between">
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __('Untick anyone who sat Desert Storm out.') }}
                </flux:text>

                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="selectAllDesertStorm" data-test="plan-desert-storm-all">
                        {{ __('Select all') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="clearDesertStorm" data-test="plan-desert-storm-none">
                        {{ __('Select none') }}
                    </flux:button>
                </div>
            </div>

            <div class="mt-4 divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                @forelse ($this->eligible as $row)
                    <div class="flex items-center justify-between px-4 py-3" wire:key="ds-{{ $row['member']->id }}" data-test="plan-desert-storm-row">
                        <flux:checkbox
                            wire:model.live="desertStorm.{{ $row['member']->id }}"
                            :label="$row['member']->name"
                            data-test="plan-desert-storm-checkbox-{{ $row['member']->id }}"
                        />

                        <flux:text class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($row['points']) }}</flux:text>
                    </div>
                @empty
                    <div class="px-4 py-8 text-center" data-test="plan-desert-storm-empty">
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Nobody cleared the VS requirement.') }}</flux:text>
                    </div>
                @endforelse
            </div>

            <div class="mt-4 flex items-center justify-between">
                <flux:button variant="filled" icon="arrow-left" wire:click="previousStep" data-test="plan-back-button">
                    {{ __('Back') }}
                </flux:button>

                <flux:button variant="primary" icon-trailing="arrow-right" wire:click="nextStep" data-test="plan-next-button">
                    {{ __('Next') }}
                </flux:button>
            </div>
        </div>

    {{-- Step 3: the candidate list, longest wait first --}}
    @elseif ($step === 3)
        <div class="mt-6" data-test="plan-candidates-step">
            <flux:text class="text-zinc-500 dark:text-zinc-400">
                {{ __('Candidates in order of how long since they last had the train.') }}
            </flux:text>

            @if (count($this->candidates) < 6)
                <flux:callout variant="warning" class="mt-4" icon="exclamation-triangle" data-test="plan-candidates-short">
                    <flux:callout.heading>{{ __('Fewer candidates than days') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ trans_choice(
                            '{0} No member is eligible, so every day will be left unassigned.|{1} Only :count member is eligible, so five days will be left unassigned.|[2,*] Only :count members are eligible, so some days will be left unassigned.',
                            count($this->candidates),
                            ['count' => count($this->candidates)],
                        ) }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="mt-4 divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                @forelse ($this->candidates as $index => $row)
                    <div class="flex items-center justify-between px-4 py-3" wire:key="candidate-{{ $row['member']->id }}" data-test="plan-candidate-row">
                        <div class="flex items-center gap-3">
                            <span class="w-6 text-right text-sm text-zinc-500 tabular-nums dark:text-zinc-400">{{ $index + 1 }}</span>
                            <flux:avatar size="sm" :name="$row['member']->name" :initials="strtoupper(substr($row['member']->name, 0, 1))" />
                            <span class="font-medium">{{ $row['member']->name }}</span>
                        </div>

                        <flux:badge size="sm" :color="$row['lastConductedOn'] ? 'zinc' : 'amber'" data-test="plan-candidate-last-conducted">
                            {{ $row['lastConductedOn'] ? __('Last on :date', ['date' => $row['lastConductedOn']->format('M j, Y')]) : __('Never conducted') }}
                        </flux:badge>
                    </div>
                @empty
                    <div class="px-4 py-8 text-center" data-test="plan-candidates-empty">
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No candidates for this week.') }}</flux:text>
                    </div>
                @endforelse
            </div>

            <div class="mt-4 flex items-center justify-between">
                <flux:button variant="filled" icon="arrow-left" wire:click="previousStep" data-test="plan-back-button">
                    {{ __('Back') }}
                </flux:button>

                <flux:button variant="primary" icon-trailing="arrow-right" wire:click="nextStep" data-test="plan-next-button">
                    {{ __('Next') }}
                </flux:button>
            </div>
        </div>

    {{-- Step 4: pick the MVP and confirm the six days --}}
    @else
        <form wire:submit="save" class="mt-6" data-test="plan-assign-step">
            @if ($this->existingDays->isNotEmpty())
                <flux:callout variant="warning" class="mb-4" icon="exclamation-triangle" data-test="plan-existing-warning">
                    <flux:callout.heading>{{ __('This week is already partly scheduled') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ trans_choice(
                            '{1} :count day already has a conductor and will be overwritten.|[2,*] :count days already have a conductor and will be overwritten.',
                            $this->existingDays->count(),
                            ['count' => $this->existingDays->count()],
                        ) }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center" data-test="plan-mvp-row">
                    <div class="flex w-48 shrink-0 items-center gap-2">
                        <span class="font-medium">{{ $this->planStart->format('D M j') }}</span>
                        <flux:badge size="sm" color="amber" icon="star">{{ __('MVP') }}</flux:badge>
                    </div>

                    <div class="flex-1">
                        <flux:select
                            variant="combobox"
                            wire:model="mvpMemberId"
                            :placeholder="__('Select the MVP')"
                            :empty="__('No members match that name.')"
                            clearable
                            data-test="plan-mvp-select"
                        >
                            @foreach ($this->roster as $member)
                                <x-member-option :member="$member" />
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                @foreach ($this->conductorDays as $day)
                    @php($date = $day->toDateString())

                    <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center" wire:key="day-{{ $date }}" data-test="plan-day-row">
                        <div class="w-48 shrink-0">
                            <span class="font-medium">{{ $day->format('D M j') }}</span>
                        </div>

                        <div class="flex-1">
                            <flux:select
                                variant="combobox"
                                wire:model.live="selections.{{ $date }}"
                                :placeholder="__('Leave unassigned')"
                                :empty="__('No candidates match that name.')"
                                clearable
                                data-test="plan-day-select-{{ $date }}"
                            >
                                @foreach ($this->availableFor($date) as $row)
                                    <x-member-option :member="$row['member']" />
                                @endforeach
                            </flux:select>
                        </div>
                    </div>
                @endforeach
            </div>

            <flux:error name="selections" />

            <div class="mt-4 flex items-center justify-between">
                <flux:button type="button" variant="filled" icon="arrow-left" wire:click="previousStep" data-test="plan-back-button">
                    {{ __('Back') }}
                </flux:button>

                <flux:button variant="primary" type="submit" icon="check" data-test="plan-save-button">
                    {{ __('Save the week') }}
                </flux:button>
            </div>
        </form>
    @endif
</section>
