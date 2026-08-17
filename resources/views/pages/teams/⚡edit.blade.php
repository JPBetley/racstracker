<?php

use App\Actions\Imports\StartImport;
use App\Data\TeamPermissions;
use App\Enums\ImportType;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public string $teamName = '';

    public string $allianceId = '';

    public array $teamData = [];

    public array $members = [];

    public array $invitations = [];

    public array $availableRoles = [];

    public bool $isCurrentTeam = false;

    public function mount(Team $team): void
    {
        $this->teamModel = $team;
        $this->teamName = $team->name;
        $this->allianceId = $team->alliance_id ?? '';

        $this->populateTeamData();
    }

    public function updateTeam(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = DB::transaction(function () use ($validated) {
            $team = Team::whereKey($this->teamModel->id)->lockForUpdate()->firstOrFail();

            $team->update(['name' => $validated['teamName']]);

            return $team;
        });

        $this->teamModel = $team;

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Team updated.'));

        $this->redirectRoute('teams.edit', ['team' => $this->teamModel->fresh()->slug], navigate: true);
    }

    /**
     * Save the Last War alliance this team's roster is synced from.
     */
    public function updateAlliance(): void
    {
        Gate::authorize('update', $this->teamModel);

        $this->allianceId = mb_strtolower(trim($this->allianceId));

        $this->validate([
            'allianceId' => ['nullable', 'string', 'regex:/^[0-9a-f]{32}$/'],
        ], [
            'allianceId.regex' => __('The alliance ID must be 32 hexadecimal characters.'),
        ]);

        $this->teamModel->update(['alliance_id' => $this->allianceId ?: null]);

        $this->populateTeamData();

        unset($this->canSyncRoster);

        Flux::toast(variant: 'success', text: __('Alliance ID saved.'));
    }

    /**
     * Queue the one-off roster sync that seeds a new team from the Last War API.
     *
     * This is a setup step only: once the roster exists it is kept current by the
     * nightly sync, so seeding again is refused rather than re-run.
     *
     * The sync runs on the queue because unkeyed API requests are served by a shared
     * connection pool and can take an unbounded amount of time.
     */
    public function syncRoster(StartImport $startImport): void
    {
        Gate::authorize('update', $this->teamModel);

        if ($this->teamModel->roster()->exists()) {
            Flux::toast(variant: 'danger', text: __('This team already has a roster.'));

            return;
        }

        $allianceId = $this->teamModel->allianceId();

        if (blank($allianceId)) {
            Flux::toast(variant: 'danger', text: __('Set an alliance ID before syncing the roster.'));

            return;
        }

        if (blank(config('services.lastwar.key'))) {
            Flux::toast(variant: 'danger', text: __('No Last War API key is configured.'));

            return;
        }

        $startImport->handle($this->teamModel, Auth::user(), ImportType::AllianceRoster, [
            'alliance_id' => $allianceId,
        ]);

        Flux::toast(variant: 'success', text: __('Roster sync started. Members will appear once it finishes.'));
    }

    public function updateMember(int $userId, string $role): void
    {
        Gate::authorize('updateMember', $this->teamModel);

        $validated = Validator::make(['role' => $role], [
            'role' => ['required', 'string', Rule::enum(TeamRole::class)],
        ])->validate();

        $this->teamModel->memberships()
            ->where('user_id', $userId)
            ->firstOrFail()
            ->update(['role' => TeamRole::from($validated['role'])]);

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Member role updated.'));
    }

    private function populateTeamData(): void
    {
        $user = Auth::user();

        $team = $this->teamModel->fresh();

        $this->teamData = [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'is_personal' => $team->is_personal,
        ];

        $this->members = $team->members()->get()->map(fn ($member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar' => $member->avatar ?? null,
            'role' => $member->pivot->role->value,
            'role_label' => $member->pivot->role->label(),
        ])->toArray();

        $this->invitations = $team->invitations()
            ->whereNull('accepted_at')
            ->get()
            ->map(fn ($invitation) => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'created_at' => $invitation->created_at->toISOString(),
            ])->toArray();

        $this->availableRoles = TeamRole::assignable();

        $this->isCurrentTeam = $user->isCurrentTeam($team);
    }

    public function render()
    {
        $teamName = $this->teamData['name'] ?? $this->teamModel->name;

        $title = $this->permissions->canUpdateTeam
            ? __('Edit :name', ['name' => $teamName])
            : __('View :name', ['name' => $teamName]);

        return $this->view()->title($title);
    }

    #[Computed]
    public function permissions(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }

    /**
     * Whether the one-off seeding sync can run.
     *
     * It needs an alliance to read, a key to read it with, and an empty roster —
     * after setup the nightly sync owns keeping the roster current.
     */
    #[Computed]
    public function canSyncRoster(): bool
    {
        return filled($this->teamModel->allianceId())
            && filled(config('services.lastwar.key'))
            && $this->teamModel->roster()->doesntExist();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Teams') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Teams')" :subheading="__('Manage your team settings')">
        <div class="space-y-10">
            <div class="space-y-6">
                @if ($this->permissions->canUpdateTeam)
                    <div class="space-y-4">
                        <form wire:submit="updateTeam" class="space-y-6">
                            <flux:input wire:model="teamName" :label="__('Team name')" required data-test="team-name-input" />

                            <flux:button variant="primary" type="submit" data-test="team-save-button">
                                {{ __('Save') }}
                            </flux:button>
                        </form>
                    </div>
                @else
                    <div>
                        <flux:heading>{{ $teamData['name'] }}</flux:heading>
                    </div>
                @endif
            </div>

            @if ($this->permissions->canUpdateTeam)
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Alliance roster') }}</flux:heading>
                        <flux:subheading>{{ __('Seed this team\'s roster from the Last War API. After setup the nightly sync keeps it current.') }}</flux:subheading>
                    </div>

                    <form wire:submit="updateAlliance" class="space-y-6">
                        <flux:input
                            wire:model="allianceId"
                            :label="__('Alliance ID')"
                            :description="__('The 32-character hex ID of your alliance, from the Last War API alliance rankings.')"
                            placeholder="0123456789abcdef0123456789abcdef"
                            data-test="alliance-id-input"
                        />

                        <div class="flex items-center gap-2">
                            <flux:button variant="primary" type="submit" data-test="alliance-save-button">
                                {{ __('Save') }}
                            </flux:button>

                            <flux:button
                                type="button"
                                variant="filled"
                                icon="arrow-path"
                                wire:click="syncRoster"
                                :disabled="! $this->canSyncRoster"
                                data-test="alliance-sync-button"
                            >
                                <span wire:loading.remove wire:target="syncRoster">{{ __('Sync roster now') }}</span>
                                <span wire:loading wire:target="syncRoster">{{ __('Starting…') }}</span>
                            </flux:button>
                        </div>

                        @unless ($this->canSyncRoster)
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="alliance-sync-blocked">
                                @if ($teamModel->roster()->exists())
                                    {{ __('This roster is already set up and is kept current by the nightly sync.') }}
                                @elseif (blank($teamModel->allianceId()))
                                    {{ __('Save an alliance ID before you can sync the roster.') }}
                                @else
                                    {{ __('No Last War API key is configured, so the roster cannot be synced.') }}
                                @endif
                            </flux:text>
                        @endunless
                    </form>
                </div>
            @endif

            <div class="space-y-6">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading>{{ __('Team members') }}</flux:heading>
                        @if ($this->permissions->canAddMember || $this->permissions->canUpdateMember || $this->permissions->canRemoveMember)
                            <flux:subheading>{{ __('Manage who belongs to this team') }}</flux:subheading>
                        @endif
                    </div>

                    @if ($this->permissions->canCreateInvitation)
                        <flux:modal.trigger name="invite-member">
                            <flux:button variant="primary" icon="user-plus" data-test="invite-member-button">
                                {{ __('Invite member') }}
                            </flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>

                <div class="space-y-3">
                    @foreach ($members as $member)
                        <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="member-row">
                            <div class="flex items-center gap-4">
                                <flux:avatar :name="$member['name']" :initials="strtoupper(substr($member['name'], 0, 1))" />
                                <div>
                                    <div class="font-medium">{{ $member['name'] }}</div>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $member['email'] }}</flux:text>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                @if ($member['role'] !== 'owner' && $this->permissions->canUpdateMember)
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="outline" size="sm" icon:trailing="chevron-down" data-test="member-role-trigger">
                                            {{ $member['role_label'] }}
                                        </flux:button>
                                        <flux:menu>
                                            @foreach ($availableRoles as $role)
                                                <flux:menu.item
                                                    as="button"
                                                    type="button"
                                                    wire:click="updateMember({{ $member['id'] }}, '{{ $role['value'] }}')"
                                                    data-test="member-role-option"
                                                >
                                                    {{ $role['label'] }}
                                                </flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                @else
                                    <flux:badge color="zinc">{{ $member['role_label'] }}</flux:badge>
                                @endif

                                @if ($member['role'] !== 'owner' && $this->permissions->canRemoveMember)
                                    <flux:modal.trigger name="remove-member-{{ $member['id'] }}">
                                        <flux:tooltip :content="__('Remove member')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="member-remove-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif
                            </div>
                        </div>

                        @if ($member['role'] !== 'owner' && $this->permissions->canRemoveMember)
                            <livewire:pages::teams.remove-member-modal
                                :team="$teamModel"
                                :member-id="$member['id']"
                                :member-name="$member['name']"
                                :modal-name="'remove-member-'.$member['id']"
                                :key="'remove-member-modal-'.$member['id']"
                            />
                        @endif
                    @endforeach
                </div>
            </div>

            @if (count($invitations) > 0)
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Pending invitations') }}</flux:heading>
                        <flux:subheading>{{ __('Invitations that have not been accepted yet') }}</flux:subheading>
                    </div>

                    <div class="space-y-3">
                        @foreach ($invitations as $invitation)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="invitation-row">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <flux:icon name="envelope" class="text-zinc-500" />
                                    </div>
                                    <div>
                                        <div class="font-medium">{{ $invitation['email'] }}</div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $invitation['role_label'] }}</flux:text>
                                    </div>
                                </div>

                                @if ($this->permissions->canCancelInvitation)
                                    <flux:modal.trigger name="cancel-invitation-{{ $invitation['code'] }}">
                                        <flux:tooltip :content="__('Cancel invitation')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="invitation-cancel-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif
                            </div>
                            @if ($this->permissions->canCancelInvitation)
                                <livewire:pages::teams.cancel-invitation-modal
                                    :team="$teamModel"
                                    :invitation-code="$invitation['code']"
                                    :invitation-email="$invitation['email']"
                                    :modal-name="'cancel-invitation-'.$invitation['code']"
                                    :key="'cancel-invitation-modal-'.$invitation['code']"
                                />
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Delete team') }}</flux:heading>
                        <flux:subheading>{{ __('Permanently delete your team') }}</flux:subheading>
                    </div>

                    <div class="space-y-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700 dark:border-red-200/10 dark:bg-red-900/20 dark:text-red-100">
                        <div>
                            <p class="font-medium">{{ __('Warning') }}</p>
                            <p class="text-sm">{{ __('Please proceed with caution, this cannot be undone.') }}</p>
                        </div>

                        <flux:modal.trigger name="delete-team">
                            <flux:button variant="danger" data-test="delete-team-button">
                                {{ __('Delete team') }}
                            </flux:button>
                        </flux:modal.trigger>
                    </div>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>

    @if ($this->permissions->canCreateInvitation)
        <livewire:pages::teams.invite-member-modal :team="$teamModel" />
    @endif

    @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
        <livewire:pages::teams.delete-team-modal :team="$teamModel" />
    @endif
</section>
