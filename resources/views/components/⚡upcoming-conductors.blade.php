<?php

use App\Models\ConductorAssignment;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?string $timezone = null;

    /**
     * Set the active timezone from the browser so "today" matches the viewer.
     */
    public function setTimezone(string $timezone): void
    {
        if ($timezone === $this->timezone || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return;
        }

        $this->timezone = $timezone;

        unset($this->today, $this->assignments);
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
     * The two days this card covers.
     *
     * @return array<int, array{key: string, label: string, day: CarbonImmutable}>
     */
    #[Computed]
    public function days(): array
    {
        return [
            ['key' => 'today', 'label' => __('Today'), 'day' => $this->today],
            ['key' => 'tomorrow', 'label' => __('Tomorrow'), 'day' => $this->today->addDay()],
        ];
    }

    /**
     * Whoever is conducting on each of those days, keyed by date.
     *
     * The window is fetched a day wide and narrowed in PHP: the date cast writes
     * a full timestamp, so a bare `Y-m-d` upper bound drops the last day on
     * SQLite.
     *
     * @return EloquentCollection<string, ConductorAssignment>
     */
    #[Computed]
    public function assignments(): EloquentCollection
    {
        $wanted = array_map(fn (array $slot): string => $slot['day']->toDateString(), $this->days);

        return $this->team->conductorAssignments()
            ->with('member')
            ->whereBetween('assigned_on', [$this->today->toDateString(), $this->today->addDays(2)->toDateString()])
            ->get()
            ->filter(fn (ConductorAssignment $assignment): bool => in_array($assignment->assigned_on->toDateString(), $wanted, true))
            ->keyBy(fn (ConductorAssignment $assignment): string => $assignment->assigned_on->toDateString());
    }

    public function assignmentFor(CarbonImmutable $day): ?ConductorAssignment
    {
        return $this->assignments->get($day->toDateString());
    }
}; ?>

<div
    class="relative flex aspect-video flex-col overflow-hidden rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"
    x-data
    x-init="$wire.setTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone)"
    data-test="dashboard-conductor-card"
>
    <div class="flex items-start justify-between gap-2">
        <div>
            <flux:heading size="lg">{{ __('Train Conductor') }}</flux:heading>
            <flux:subheading>{{ __('Who has the train') }}</flux:subheading>
        </div>

        <flux:tooltip :content="__('View the full history')">
            <flux:button
                :href="route('conductors.index')"
                wire:navigate
                variant="ghost"
                size="sm"
                icon="arrow-up-right"
                data-test="dashboard-conductor-link"
            />
        </flux:tooltip>
    </div>

    <div class="mt-4 flex flex-1 flex-col justify-center gap-4">
        {{--
            Until the browser reports its zone, "today" would be guessed in UTC,
            which names the wrong day's conductor for anyone whose date has not
            turned over yet. Hold the rows back rather than show a name that is
            about to change under the reader.
        --}}
        @if ($timezone === null)
            @foreach (['today', 'tomorrow'] as $slot)
                <div class="animate-pulse" wire:key="conductor-skeleton-{{ $slot }}" data-test="dashboard-conductor-skeleton">
                    <div class="h-3.5 w-28 rounded bg-zinc-200 dark:bg-zinc-700"></div>

                    <div class="mt-2 flex items-center gap-2">
                        <div class="size-6 shrink-0 rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                        <div class="h-4 w-32 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </div>
                </div>
            @endforeach
        @else
            @foreach ($this->days as $slot)
                @php($assignment = $this->assignmentFor($slot['day']))

                <div wire:key="conductor-{{ $slot['key'] }}" data-test="dashboard-conductor-{{ $slot['key'] }}">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ $slot['label'] }} · {{ $slot['day']->format('D M j') }}
                    </flux:text>

                    @if ($assignment)
                        <div class="mt-1 flex items-center gap-2">
                            <flux:avatar
                                size="xs"
                                :name="$assignment->member->name"
                                :initials="strtoupper(substr($assignment->member->name, 0, 1))"
                            />

                            <span class="truncate font-medium">{{ $assignment->member->name }}</span>

                            @if ($assignment->is_mvp)
                                <flux:badge size="sm" color="amber" icon="star" data-test="dashboard-conductor-mvp-badge">
                                    {{ __('MVP') }}
                                </flux:badge>
                            @endif
                        </div>
                    @else
                        <flux:text class="mt-1 text-zinc-400 dark:text-zinc-500">
                            {{ __('Nobody assigned') }}
                        </flux:text>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
