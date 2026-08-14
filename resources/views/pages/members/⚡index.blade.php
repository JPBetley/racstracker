<?php

use App\Actions\Members\CreateMember;
use App\Actions\Members\DeleteMember;
use App\Actions\Members\UpdateMember;
use App\Enums\MemberPosition;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Members')] class extends Component
{
    public string $name = '';

    public string $position = '';

    public ?int $editingId = null;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Collection<int, \App\Models\Member>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->team->roster()
            ->orderByDesc('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    #[Computed]
    public function positions(): array
    {
        return MemberPosition::options();
    }

    public function addMember(): void
    {
        $this->resetForm();

        Flux::modal('member-form')->show();
    }

    public function editMember(int $id): void
    {
        $member = $this->team->roster()->findOrFail($id);

        $this->editingId = $member->id;
        $this->name = $member->name;
        $this->position = $member->position->value;

        Flux::modal('member-form')->show();
    }

    public function saveMember(CreateMember $createMember, UpdateMember $updateMember): void
    {
        $team = $this->team;

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('members', 'name')
                    ->where('team_id', $team->id)
                    ->ignore($this->editingId),
            ],
            'position' => ['required', Rule::enum(MemberPosition::class)],
        ]);

        $position = MemberPosition::from($validated['position']);

        if ($this->editingId !== null) {
            $member = $team->roster()->findOrFail($this->editingId);
            $updateMember->handle($member, $validated['name'], $position);
            $message = __('Member updated.');
        } else {
            $createMember->handle($team, $validated['name'], $position);
            $message = __('Member added.');
        }

        unset($this->members);
        $this->resetForm();

        Flux::modal('member-form')->close();
        Flux::toast(variant: 'success', text: $message);
    }

    public function deleteMember(int $id, DeleteMember $deleteMember): void
    {
        $member = $this->team->roster()->findOrFail($id);

        $deleteMember->handle($member);

        unset($this->members);

        Flux::toast(variant: 'success', text: __('Member removed.'));
    }

    private function resetForm(): void
    {
        $this->reset('name', 'position', 'editingId');
        $this->resetValidation();
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Members') }}</flux:heading>
            <flux:subheading>{{ __('Manage your alliance roster') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                :href="route('members.import')"
                wire:navigate
                variant="filled"
                icon="photo"
                data-test="member-import-button"
            >
                {{ __('Import from screenshots') }}
            </flux:button>

            <flux:button variant="primary" icon="plus" wire:click="addMember" data-test="member-add-button">
                {{ __('Add member') }}
            </flux:button>
        </div>
    </div>

    <div class="mt-6 space-y-3">
        @forelse ($this->members as $member)
            <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="member-row">
                <div class="flex items-center gap-4">
                    <flux:avatar :name="$member->name" :initials="strtoupper(substr($member->name, 0, 1))" />
                    <div class="font-medium">{{ $member->name }}</div>
                </div>

                <div class="flex items-center gap-2">
                    <flux:badge color="zinc">{{ $member->position->label() }}</flux:badge>

                    <flux:tooltip :content="__('Edit member')">
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="pencil"
                            wire:click="editMember({{ $member->id }})"
                            data-test="member-edit-button"
                        />
                    </flux:tooltip>

                    <flux:tooltip :content="__('Remove member')">
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            wire:click="deleteMember({{ $member->id }})"
                            wire:confirm="{{ __('Remove :name from the roster?', ['name' => $member->name]) }}"
                            data-test="member-remove-button"
                        />
                    </flux:tooltip>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-zinc-200 p-8 text-center dark:border-zinc-700" data-test="members-empty">
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No members yet. Add your first roster member.') }}</flux:text>
            </div>
        @endforelse
    </div>

    <flux:modal name="member-form" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="saveMember" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editingId ? __('Edit member') : __('Add member') }}
                </flux:heading>
                <flux:subheading>{{ __('Track a member of your alliance roster.') }}</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="name" :label="__('Name')" required data-test="member-name-input" />

                <flux:select wire:model="position" :label="__('Position')" :placeholder="__('Select a position')" data-test="member-position-select">
                    @foreach ($this->positions as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="member-save-button">
                    {{ $editingId ? __('Save changes') : __('Add member') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
