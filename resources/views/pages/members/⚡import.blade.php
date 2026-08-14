<?php

use App\Actions\Imports\BeginRosterScreenshotImport;
use App\Actions\Imports\ConfirmRosterImport;
use App\Enums\ImportStatus;
use App\Enums\MemberPosition;
use App\Imports\Ocr\RosterNameMatcher;
use App\Models\Import;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Import roster')] class extends Component
{
    use WithFileUploads;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $screenshots = [];

    public ?int $importId = null;

    /** @var array<int, array{name: string, position: string, suggestion: ?string}> */
    public array $rows = [];

    public bool $loaded = false;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function import(): ?Import
    {
        return $this->importId ? $this->team->imports()->find($this->importId) : null;
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    #[Computed]
    public function positions(): array
    {
        return MemberPosition::options();
    }

    public function startParse(BeginRosterScreenshotImport $beginImport): void
    {
        $this->validate([
            'screenshots' => ['required', 'array', 'min:1'],
            'screenshots.*' => ['image', 'max:10240'],
        ]);

        $paths = array_map(
            fn ($file): string => $file->store('imports', 'local'),
            $this->screenshots,
        );

        $import = $beginImport->handle($this->team, Auth::user(), $paths);

        $this->importId = $import->id;
        $this->reset('screenshots');
        unset($this->import);

        // When the queue runs synchronously the OCR draft is ready immediately.
        $this->pollDraft();
    }

    /**
     * Poll the import while OCR runs; load the draft once it is ready for review.
     */
    public function pollDraft(): void
    {
        $import = $this->import;

        if ($import?->status === ImportStatus::AwaitingReview && ! $this->loaded) {
            $this->loadDraft($import);
        }
    }

    public function addRow(): void
    {
        $this->rows[] = ['name' => '', 'position' => MemberPosition::R3->value, 'suggestion' => null];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function applySuggestion(int $index): void
    {
        if (isset($this->rows[$index]['suggestion'])) {
            $this->rows[$index]['name'] = $this->rows[$index]['suggestion'];
            $this->rows[$index]['suggestion'] = null;
        }
    }

    public function confirm(ConfirmRosterImport $confirmImport): void
    {
        $import = $this->import;

        abort_unless($import?->status === ImportStatus::AwaitingReview, 404);

        $this->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.position' => ['required', Rule::enum(MemberPosition::class)],
        ]);

        $members = array_map(
            fn (array $row): array => ['name' => $row['name'], 'position' => $row['position']],
            $this->rows,
        );

        $confirmImport->handle($import, $members);

        Flux::toast(variant: 'success', text: __('Roster import started.'));

        $this->redirectRoute('members.index', navigate: true);
    }

    private function loadDraft(Import $import): void
    {
        $existing = $this->team->roster()->with('aliases')->get();
        $matcher = app(RosterNameMatcher::class);

        $this->rows = array_map(function (array $row) use ($existing, $matcher): array {
            $suggestion = $matcher->suggest($row['name'], $existing);

            return [
                'name' => $row['name'],
                'position' => $row['position'],
                'suggestion' => $suggestion && $suggestion->name !== $row['name'] ? $suggestion->name : null,
            ];
        }, $import->payload['members'] ?? []);

        $this->loaded = true;
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Import roster from screenshots') }}</flux:heading>
            <flux:subheading>{{ __('Upload your in-game member list screenshots to build the roster.') }}</flux:subheading>
        </div>

        <flux:button :href="route('members.index')" wire:navigate variant="ghost" icon="arrow-left">
            {{ __('Back to roster') }}
        </flux:button>
    </div>

    @php($import = $this->import)

    {{-- Step 1: upload --}}
    @if (! $import)
        <form wire:submit="startParse" class="mt-6 space-y-6" data-test="screenshot-upload-form">
            <flux:input
                type="file"
                wire:model="screenshots"
                :label="__('Screenshots')"
                multiple
                accept="image/*"
                data-test="screenshot-input"
            />

            <flux:error name="screenshots" />

            <div class="flex justify-end">
                <flux:button variant="primary" type="submit" icon="sparkles" data-test="screenshot-parse-button">
                    <span wire:loading.remove wire:target="startParse">{{ __('Read screenshots') }}</span>
                    <span wire:loading wire:target="startParse">{{ __('Uploading…') }}</span>
                </flux:button>
            </div>
        </form>

    {{-- Step 2: OCR running --}}
    @elseif (in_array($import->status, [ImportStatus::Pending, ImportStatus::Processing], true))
        <div
            wire:poll.2s="pollDraft"
            class="mt-6 flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-zinc-200 p-12 text-center dark:border-zinc-700"
            data-test="screenshot-processing"
        >
            <flux:icon.loading />
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Reading your screenshots…') }}</flux:text>
        </div>

    {{-- Failed --}}
    @elseif ($import->status === ImportStatus::Failed)
        <flux:callout variant="danger" class="mt-6" icon="exclamation-triangle" data-test="screenshot-failed">
            <flux:callout.heading>{{ __('We could not read those screenshots') }}</flux:callout.heading>
            <flux:callout.text>{{ $import->error }}</flux:callout.text>
        </flux:callout>

    {{-- Step 3: review draft --}}
    @else
        <form wire:submit="confirm" class="mt-6 space-y-4" data-test="screenshot-review-form">
            <div class="flex items-center justify-between">
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __(':count members found. Review and edit before importing.', ['count' => count($this->rows)]) }}
                </flux:text>

                <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="addRow" data-test="review-add-row">
                    {{ __('Add row') }}
                </flux:button>
            </div>

            <div class="space-y-2">
                @forelse ($this->rows as $index => $row)
                    <div class="flex items-start gap-2 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="review-row" wire:key="row-{{ $index }}">
                        <div class="flex-1">
                            <flux:input wire:model="rows.{{ $index }}.name" :label="__('Name')" data-test="review-name-input" />

                            @if ($row['suggestion'])
                                <flux:text size="sm" class="mt-1 text-amber-600 dark:text-amber-500">
                                    {{ __('Did you mean') }}
                                    <button type="button" class="font-medium underline" wire:click="applySuggestion({{ $index }})" data-test="review-suggestion">{{ $row['suggestion'] }}</button>?
                                </flux:text>
                            @endif
                        </div>

                        <flux:select wire:model="rows.{{ $index }}.position" :label="__('Position')" class="w-28" data-test="review-position-select">
                            @foreach ($this->positions as $option)
                                <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:button type="button" variant="ghost" size="sm" icon="trash" class="mt-6" wire:click="removeRow({{ $index }})" data-test="review-remove-row" />
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-200 p-8 text-center dark:border-zinc-700">
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No members were read. Add rows manually or try clearer screenshots.') }}</flux:text>
                    </div>
                @endforelse
            </div>

            <div class="flex justify-end gap-2">
                <flux:button :href="route('members.index')" wire:navigate variant="filled" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button variant="primary" type="submit" icon="check" data-test="review-confirm-button">
                    {{ __('Import :count members', ['count' => count($this->rows)]) }}
                </flux:button>
            </div>
        </form>
    @endif
</section>
