<?php

use App\Actions\Teams\UpdateTeamSettings;
use App\Data\TeamPermissions;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Team Settings')] class extends Component
{
    public ?int $vsMinimum = 0;

    public ?int $trainVsRequirement = 0;

    public bool $trainDesertStormRequirement = false;

    public function mount(): void
    {
        $this->vsMinimum = $this->team->vs_minimum;
        $this->trainVsRequirement = $this->team->train_vs_requirement;
        $this->trainDesertStormRequirement = $this->team->train_desert_storm_requirement;
    }

    /**
     * Save the alliance-wide expectations for the current team.
     */
    public function save(UpdateTeamSettings $updateTeamSettings): void
    {
        Gate::authorize('update', $this->team);

        $validated = $this->validate([
            'vsMinimum' => ['required', 'integer', 'min:0'],
            'trainVsRequirement' => ['required', 'integer', 'min:0'],
            'trainDesertStormRequirement' => ['boolean'],
        ]);

        $updateTeamSettings->handle(
            $this->team,
            $validated['vsMinimum'],
            $validated['trainVsRequirement'],
            $this->trainDesertStormRequirement,
        );

        unset($this->team);

        Flux::toast(variant: 'success', text: __('Team settings saved.'));
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function permissions(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->team);
    }
}; ?>

<section class="w-full">
    <div>
        <flux:heading size="xl">{{ __('Team Settings') }}</flux:heading>
        <flux:subheading>{{ __('Set what this alliance expects of its members') }}</flux:subheading>
    </div>

    @if ($this->permissions->canUpdateTeam)
        <form wire:submit="save" class="mt-6 max-w-lg space-y-6">
            <flux:input
                type="number"
                min="0"
                wire:model="vsMinimum"
                :label="__('VS Minimum')"
                :description="__('The weekly VS points every member is expected to reach. Zero means no minimum.')"
                data-test="vs-minimum-input"
            />

            <flux:input
                type="number"
                min="0"
                wire:model="trainVsRequirement"
                :label="__('Train VS Requirement')"
                :description="__('The weekly VS points needed to be eligible to conduct a train. Zero means no requirement.')"
                data-test="train-vs-requirement-input"
            />

            <flux:switch
                wire:model="trainDesertStormRequirement"
                :label="__('Train Desert Storm Requirement')"
                :description="__('Require Desert Storm participation to be eligible to conduct a train.')"
                data-test="train-desert-storm-requirement-switch"
            />

            <flux:button variant="primary" type="submit" data-test="team-settings-save-button">
                {{ __('Save') }}
            </flux:button>
        </form>
    @else
        <div class="mt-6 max-w-lg space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="vs-minimum-value">
                <flux:text>{{ __('VS Minimum') }}</flux:text>
                <flux:badge color="zinc">{{ number_format($this->team->vs_minimum) }}</flux:badge>
            </div>

            <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="train-vs-requirement-value">
                <flux:text>{{ __('Train VS Requirement') }}</flux:text>
                <flux:badge color="zinc">{{ number_format($this->team->train_vs_requirement) }}</flux:badge>
            </div>

            <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="train-desert-storm-requirement-value">
                <flux:text>{{ __('Train Desert Storm Requirement') }}</flux:text>
                <flux:badge :color="$this->team->train_desert_storm_requirement ? 'green' : 'zinc'">
                    {{ $this->team->train_desert_storm_requirement ? __('Required') : __('Not required') }}
                </flux:badge>
            </div>
        </div>
    @endif
</section>
