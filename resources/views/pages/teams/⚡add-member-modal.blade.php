<?php

use App\Actions\Teams\AddTeamMember;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * Anything shorter is not a lookup, it is a way of paging through the whole
     * user table, so a search that short returns nothing at all.
     */
    private const MINIMUM_SEARCH_LENGTH = 2;

    private const RESULT_LIMIT = 10;

    public Team $team;

    public string $search = '';

    public string $role = 'member';

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    /**
     * Add the chosen user to the team straight away, with no invitation to accept.
     */
    public function addMember(int $userId, AddTeamMember $addTeamMember): void
    {
        Gate::authorize('addMember', $this->team);

        $validated = $this->validate([
            'role' => ['required', 'string', Rule::enum(TeamRole::class)->except(TeamRole::Owner)],
        ]);

        $user = $this->addableUser($userId);

        if (! $user instanceof User) {
            Flux::toast(variant: 'danger', text: __('That user cannot be added to this team.'));

            return;
        }

        $addTeamMember->handle($this->team, $user, TeamRole::from($validated['role']));

        $this->reset('search', 'role');
        $this->dispatch('close-modal', name: 'add-member');

        Flux::toast(variant: 'success', text: __(':name was added to the team.', ['name' => $user->name]));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }

    /**
     * The verified, not-yet-a-member users matching the search.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function results(): Collection
    {
        $search = trim($this->search);

        if (mb_strlen($search) < self::MINIMUM_SEARCH_LENGTH) {
            return new Collection;
        }

        return $this->addableUsers()
            ->where(fn ($query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%"))
            ->orderBy('name')
            ->limit(self::RESULT_LIMIT)
            ->get();
    }

    #[Computed]
    public function hasSearched(): bool
    {
        return mb_strlen(trim($this->search)) >= self::MINIMUM_SEARCH_LENGTH;
    }

    #[Computed]
    public function availableRoles(): array
    {
        return TeamRole::assignable();
    }

    /**
     * Re-read the chosen user rather than trusting the id the browser sent back.
     *
     * The results it came from are a snapshot: the account may have been added by
     * somebody else, or had its verification revoked, since the search ran.
     */
    private function addableUser(int $userId): ?User
    {
        return $this->addableUsers()->whereKey($userId)->first();
    }

    /**
     * The users this team is allowed to add: verified, and not already members.
     *
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function addableUsers(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->whereNotNull('email_verified_at')
            ->whereNotIn('id', $this->team->memberships()->select('user_id'));
    }
}; ?>

<flux:modal name="add-member" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Add an existing member') }}</flux:heading>
            <flux:subheading>{{ __('Search registered, email-verified users and add them to this team right away.') }}</flux:subheading>
        </div>

        <div class="space-y-4">
            <flux:input
                wire:model.live.debounce.300ms="search"
                type="search"
                icon="magnifying-glass"
                :label="__('Search')"
                :placeholder="__('Name or email address')"
                data-test="add-member-search"
            />

            <flux:select wire:model="role" :label="__('Role')" data-test="add-member-role">
                @foreach ($this->availableRoles as $availableRole)
                    <flux:select.option value="{{ $availableRole['value'] }}">{{ $availableRole['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($this->hasSearched)
            @forelse ($this->results as $result)
                <div
                    class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900"
                    wire:key="add-member-result-{{ $result->id }}"
                    data-test="add-member-result"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:avatar :name="$result->name" :initials="$result->initials()" size="sm" />
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $result->name }}</div>
                            <flux:text class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $result->email }}</flux:text>
                        </div>
                    </div>

                    <flux:button
                        type="button"
                        variant="primary"
                        size="sm"
                        wire:click="addMember({{ $result->id }})"
                        data-test="add-member-submit"
                    >
                        {{ __('Add') }}
                    </flux:button>
                </div>
            @empty
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="add-member-empty">
                    {{ __('No verified users match that search. They may not have registered, verified their email, or they are on this team already.') }}
                </flux:text>
            @endforelse
        @endif

        <div class="flex justify-end">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Done') }}</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>
