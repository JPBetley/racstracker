<?php

use App\Actions\Members\CreateMember;
use App\Actions\Members\CreateMemberAlias;
use App\Actions\Members\DeleteMember;
use App\Actions\Members\DeleteMemberAlias;
use App\Actions\Members\UpdateMember;
use App\Actions\Members\UpdateMemberAlias;
use App\Enums\MemberPosition;
use App\Models\Member;
use App\Models\MemberAlias;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Members')] class extends Component
{
    public string $name = '';

    public string $position = '';

    public ?int $editingId = null;

    public ?int $aliasMemberId = null;

    public string $newAliasName = '';

    public ?int $editingAliasId = null;

    public string $editingAliasName = '';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Collection<int, Member>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->team->roster()
            ->active()
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

    public function manageAliases(int $id): void
    {
        $member = $this->team->roster()->findOrFail($id);

        $this->aliasMemberId = $member->id;
        $this->resetAliasForm();

        Flux::modal('member-aliases')->show();
    }

    /**
     * Get the member whose names are being managed, if the modal is open.
     */
    #[Computed]
    public function aliasMember(): ?Member
    {
        if ($this->aliasMemberId === null) {
            return null;
        }

        return $this->team->roster()->with('aliases')->find($this->aliasMemberId);
    }

    /**
     * Get the names this member no longer goes by.
     *
     * The roster sync records the current name as an alias alongside every past
     * one, so it is dropped here and shown separately as the locked current name.
     *
     * @return Collection<int, MemberAlias>
     */
    #[Computed]
    public function formerNames(): Collection
    {
        $member = $this->aliasMember;

        if ($member === null) {
            return new Collection;
        }

        return $member->aliases
            ->reject(fn (MemberAlias $alias): bool => $alias->name === $member->name)
            ->sortBy('name')
            ->values();
    }

    public function addAlias(CreateMemberAlias $createMemberAlias): void
    {
        $member = $this->aliasMemberOrFail();

        $createMemberAlias->handle($member, $this->validateAliasName('newAliasName', $member));

        $this->refreshAliases();
        $this->reset('newAliasName');

        Flux::toast(variant: 'success', text: __('Name added.'));
    }

    public function editAlias(int $id): void
    {
        $alias = $this->aliasMemberOrFail()->aliases()->findOrFail($id);

        $this->editingAliasId = $alias->id;
        $this->editingAliasName = $alias->name;
        $this->resetValidation();
    }

    public function updateAlias(UpdateMemberAlias $updateMemberAlias): void
    {
        $member = $this->aliasMemberOrFail();
        $alias = $member->aliases()->findOrFail($this->editingAliasId);

        $name = $this->validateAliasName('editingAliasName', $member, $alias->id);

        $updateMemberAlias->handle($alias, $name);

        $this->refreshAliases();
        $this->cancelAliasEdit();

        Flux::toast(variant: 'success', text: __('Name updated.'));
    }

    public function cancelAliasEdit(): void
    {
        $this->reset('editingAliasId', 'editingAliasName');
        $this->resetValidation();
    }

    public function deleteAlias(int $id, DeleteMemberAlias $deleteMemberAlias): void
    {
        $alias = $this->aliasMemberOrFail()->aliases()->findOrFail($id);

        $deleteMemberAlias->handle($alias);

        $this->refreshAliases();

        if ($this->editingAliasId === $alias->id) {
            $this->cancelAliasEdit();
        }

        Flux::toast(variant: 'success', text: __('Name removed.'));
    }

    /**
     * Get the member whose names are being managed, scoped to the current team.
     *
     * @throws ModelNotFoundException
     */
    private function aliasMemberOrFail(): Member
    {
        return $this->team->roster()->findOrFail($this->aliasMemberId);
    }

    /**
     * Validate a name typed into the alias modal and return it trimmed.
     *
     * @throws ValidationException
     */
    private function validateAliasName(string $field, Member $member, ?int $ignoreId = null): string
    {
        $this->{$field} = trim($this->{$field});

        $validated = $this->validate([
            $field => [
                'required', 'string', 'max:255',
                Rule::unique('member_aliases', 'name')
                    ->where('member_id', $member->id)
                    ->ignore($ignoreId),
                Rule::notIn([$member->name]),
            ],
        ], [
            $field.'.unique' => __('This name is already recorded for :name.', ['name' => $member->name]),
            $field.'.not_in' => __('That is already this member\'s current name.'),
        ]);

        return $validated[$field];
    }

    private function refreshAliases(): void
    {
        unset($this->aliasMember, $this->formerNames);
    }

    private function resetForm(): void
    {
        $this->reset('name', 'position', 'editingId');
        $this->resetValidation();
    }

    private function resetAliasForm(): void
    {
        $this->reset('newAliasName', 'editingAliasId', 'editingAliasName');
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

                    <flux:tooltip :content="__('Manage names')">
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="tag"
                            wire:click="manageAliases({{ $member->id }})"
                            data-test="member-aliases-button"
                        />
                    </flux:tooltip>

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

    {{-- Scoped to this form's own fields so an alias error does not pop it open. --}}
    <flux:modal name="member-form" :show="$errors->hasAny(['name', 'position'])" focusable class="max-w-lg">
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

    <flux:modal name="member-aliases" focusable class="max-w-lg">
        @if ($this->aliasMember)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Names') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Every name this member has gone by. Past names keep screenshot imports and scores matched to them across a rename.') }}
                    </flux:subheading>
                </div>

                <div class="space-y-2" data-test="member-alias-list">
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200 px-4 py-2.5 dark:border-zinc-700" data-test="member-alias-current">
                        <div class="font-medium">{{ $this->aliasMember->name }}</div>
                        <flux:badge size="sm" color="zinc">{{ __('Current') }}</flux:badge>
                    </div>

                    @forelse ($this->formerNames as $alias)
                        @if ($editingAliasId === $alias->id)
                            <form
                                wire:submit="updateAlias"
                                class="flex items-start gap-2 rounded-lg border border-zinc-200 px-4 py-2.5 dark:border-zinc-700"
                                data-test="member-alias-edit-form"
                            >
                                <div class="flex-1">
                                    <flux:input wire:model="editingAliasName" size="sm" data-test="member-alias-edit-input" />
                                </div>

                                <flux:tooltip :content="__('Save name')">
                                    <flux:button variant="primary" size="sm" icon="check" type="submit" data-test="member-alias-save-button" />
                                </flux:tooltip>

                                <flux:tooltip :content="__('Cancel')">
                                    <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="cancelAliasEdit" data-test="member-alias-cancel-button" />
                                </flux:tooltip>
                            </form>
                        @else
                            <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200 px-4 py-2.5 dark:border-zinc-700" data-test="member-alias-row">
                                <div>{{ $alias->name }}</div>

                                <div class="flex items-center gap-1">
                                    <flux:tooltip :content="__('Edit name')">
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil"
                                            wire:click="editAlias({{ $alias->id }})"
                                            data-test="member-alias-edit-button"
                                        />
                                    </flux:tooltip>

                                    <flux:tooltip :content="__('Forget name')">
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            wire:click="deleteAlias({{ $alias->id }})"
                                            wire:confirm="{{ __('Forget the name :name? Imports will no longer match it.', ['name' => $alias->name]) }}"
                                            data-test="member-alias-delete-button"
                                        />
                                    </flux:tooltip>
                                </div>
                            </div>
                        @endif
                    @empty
                        <div class="rounded-lg border border-dashed border-zinc-200 p-6 text-center dark:border-zinc-700" data-test="member-aliases-empty">
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No past names recorded yet.') }}</flux:text>
                        </div>
                    @endforelse
                </div>

                <form wire:submit="addAlias" class="flex items-start gap-2">
                    <div class="flex-1">
                        <flux:input
                            wire:model="newAliasName"
                            :placeholder="__('Add a past name')"
                            data-test="member-alias-name-input"
                        />
                    </div>

                    <flux:button variant="primary" icon="plus" type="submit" data-test="member-alias-add-button">
                        {{ __('Add') }}
                    </flux:button>
                </form>
            </div>
        @endif
    </flux:modal>
</section>
