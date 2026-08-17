<?php

use App\Actions\Conductors\CreateConductorAssignment;
use App\Actions\Conductors\DeleteConductorAssignment;
use App\Actions\Conductors\UpdateConductorAssignment;
use App\Models\ConductorAssignment;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Train Conductor')] class extends Component
{
    use WithPagination;

    public ?int $memberId = null;

    public string $assignedOn = '';

    public bool $isMvp = false;

    public ?int $editingId = null;

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
     * Badge color for a scheduled day: green for today, yellow for anything
     * still upcoming, zinc for days already run.
     *
     * Calendar dates are compared as `Y-m-d` strings rather than as instants
     * because `assigned_on` hydrates at midnight UTC while today is midnight in
     * the viewer's zone, so the same day is two different moments.
     */
    public function dayColor(CarbonImmutable $assignedOn): string
    {
        $date = $assignedOn->toDateString();
        $today = $this->today->toDateString();

        return match (true) {
            $date === $today => 'green',
            $date > $today => 'yellow',
            default => 'zinc',
        };
    }

    /**
     * @return LengthAwarePaginator<int, ConductorAssignment>
     */
    #[Computed]
    public function assignments(): LengthAwarePaginator
    {
        return $this->team->conductorAssignments()
            ->with('member')
            ->orderByDesc('assigned_on')
            ->paginate(25);
    }

    /**
     * Get the members that can be picked as conductor.
     *
     * The roster is limited to members still in the alliance, plus whoever is
     * already on the assignment being edited so a historical row can be saved
     * again without silently swapping a departed member out.
     *
     * @return Collection<int, \App\Models\Member>
     */
    #[Computed]
    public function members(): Collection
    {
        $members = $this->team->roster()->active()->with('aliases')->get();

        if ($this->memberId !== null && ! $members->contains('id', $this->memberId)) {
            $assigned = $this->team->roster()->with('aliases')->find($this->memberId);

            if ($assigned !== null) {
                $members->push($assigned);
            }
        }

        return $members->sortBy('name')->values();
    }

    public function addAssignment(): void
    {
        $this->resetForm();

        $this->assignedOn = $this->today->toDateString();

        Flux::modal('conductor-form')->show();
    }

    public function editAssignment(int $id): void
    {
        $assignment = $this->team->conductorAssignments()->findOrFail($id);

        $this->editingId = $assignment->id;
        $this->memberId = $assignment->member_id;
        $this->assignedOn = $assignment->assigned_on->toDateString();
        $this->isMvp = $assignment->is_mvp;

        Flux::modal('conductor-form')->show();
    }

    /**
     * Record or amend the conductor for a day.
     *
     * The one-conductor-per-day rule is checked with `whereDate` rather than
     * `Rule::unique` because the date column round-trips as a full datetime on
     * SQLite, which a plain equality comparison would miss.
     */
    public function saveAssignment(CreateConductorAssignment $createAssignment, UpdateConductorAssignment $updateAssignment): void
    {
        $team = $this->team;

        $validated = $this->validate([
            'memberId' => [
                'required', 'integer',
                Rule::exists('members', 'id')->where('team_id', $team->id),
            ],
            'assignedOn' => [
                'required', 'date',
                function (string $attribute, mixed $value, \Closure $fail) use ($team) {
                    $taken = $team->conductorAssignments()
                        ->whereDate('assigned_on', $value)
                        ->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))
                        ->exists();

                    if ($taken) {
                        $fail(__('A conductor is already assigned for that day.'));
                    }
                },
            ],
        ], [
            'memberId.exists' => __('Select a member of your roster.'),
        ], [
            'memberId' => __('conductor'),
            'assignedOn' => __('date'),
        ]);

        $member = $team->roster()->findOrFail($validated['memberId']);
        $assignedOn = CarbonImmutable::parse($validated['assignedOn']);

        if ($this->editingId !== null) {
            $assignment = $team->conductorAssignments()->findOrFail($this->editingId);
            $updateAssignment->handle($assignment, $member, $assignedOn, $this->isMvp);
            $message = __('Assignment updated.');
        } else {
            $createAssignment->handle($team, $member, $assignedOn, $this->isMvp);
            $message = __('Conductor assigned.');
        }

        unset($this->assignments);
        $this->resetForm();

        Flux::modal('conductor-form')->close();
        Flux::toast(variant: 'success', text: $message);
    }

    public function deleteAssignment(int $id, DeleteConductorAssignment $deleteAssignment): void
    {
        $assignment = $this->team->conductorAssignments()->findOrFail($id);

        $deleteAssignment->handle($assignment);

        unset($this->assignments);

        Flux::toast(variant: 'success', text: __('Assignment removed.'));
    }

    private function resetForm(): void
    {
        $this->reset('memberId', 'assignedOn', 'isMvp', 'editingId');
        $this->resetValidation();
        unset($this->members);
    }
}; ?>

<section class="w-full" x-data x-init="$wire.setTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone)">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Train Conductor') }}</flux:heading>
            <flux:subheading>{{ __('A history of who conducted the train each day') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                :href="route('conductors.plan')"
                wire:navigate
                variant="filled"
                icon="calendar-days"
                data-test="conductor-plan-button"
            >
                {{ __('Plan week') }}
            </flux:button>

            <flux:button variant="primary" icon="plus" wire:click="addAssignment" data-test="conductor-add-button">
                {{ __('Assign conductor') }}
            </flux:button>
        </div>
    </div>

    <div class="mt-6">
        @if ($this->assignments->isNotEmpty())
            <flux:table :paginate="$this->assignments">
                <flux:table.columns>
                    <flux:table.column>{{ __('Conductor') }}</flux:table.column>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Day') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->assignments as $assignment)
                        <flux:table.row :key="$assignment->id" data-test="conductor-row">
                            <flux:table.cell variant="strong">
                                <div class="flex items-center gap-3">
                                    <flux:avatar
                                        size="xs"
                                        :name="$assignment->member->name"
                                        :initials="strtoupper(substr($assignment->member->name, 0, 1))"
                                    />
                                    {{ $assignment->member->name }}

                                    @if ($assignment->is_mvp)
                                        <flux:badge size="sm" color="amber" icon="star" data-test="conductor-mvp-badge">
                                            {{ __('MVP') }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>

                            <flux:table.cell>{{ $assignment->assigned_on->format('M j, Y') }}</flux:table.cell>

                            <flux:table.cell>
                                <flux:badge size="sm" :color="$this->dayColor($assignment->assigned_on)" data-test="conductor-day-badge">
                                    {{ $assignment->assigned_on->format('l') }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                <div class="flex items-center justify-end gap-2">
                                    <flux:tooltip :content="__('Edit assignment')">
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil"
                                            wire:click="editAssignment({{ $assignment->id }})"
                                            data-test="conductor-edit-button"
                                        />
                                    </flux:tooltip>

                                    <flux:tooltip :content="__('Remove assignment')">
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            wire:click="deleteAssignment({{ $assignment->id }})"
                                            wire:confirm="{{ __('Remove the assignment for :date?', ['date' => $assignment->assigned_on->format('M j, Y')]) }}"
                                            data-test="conductor-remove-button"
                                        />
                                    </flux:tooltip>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-lg border border-dashed border-zinc-200 p-8 text-center dark:border-zinc-700" data-test="conductors-empty">
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No conductors assigned yet. Record your first day.') }}</flux:text>
            </div>
        @endif
    </div>

    <flux:modal name="conductor-form" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="saveAssignment" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editingId ? __('Edit assignment') : __('Assign conductor') }}
                </flux:heading>
                <flux:subheading>{{ __('Record the member conducting the train on a given day.') }}</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:select
                    variant="combobox"
                    wire:model="memberId"
                    :label="__('Conductor')"
                    :placeholder="__('Select a member')"
                    :empty="__('No members match that name.')"
                    clearable
                    data-test="conductor-member-select"
                >
                    @foreach ($this->members as $member)
                        <x-member-option :member="$member" />
                    @endforeach
                </flux:select>

                <flux:date-picker wire:model="assignedOn" :label="__('Date')" with-today data-test="conductor-date-picker" />

                <flux:checkbox
                    wire:model="isMvp"
                    :label="__('MVP')"
                    :description="__('Mark this conductor as the most valuable player for the week.')"
                    data-test="conductor-mvp-checkbox"
                />
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="conductor-save-button">
                    {{ $editingId ? __('Save changes') : __('Assign conductor') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
