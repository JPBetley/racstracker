<?php

use App\Actions\Imports\BeginVsScoreScreenshotImport;
use App\Actions\Imports\ConfirmVsScoreImport;
use App\Enums\ImportStatus;
use App\Imports\Ocr\RosterNameMatcher;
use App\Models\Import;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Import VS scores')] class extends Component
{
    use WithFileUploads;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $screenshots = [];

    /**
     * The week being imported into, as an offset from the current week.
     *
     * Carried in the query string so the VS Scores page can hand over whichever week
     * the user was looking at, and so a refresh mid-review keeps it.
     */
    #[Url]
    public int $weekOffset = 0;

    public ?string $timezone = null;

    public ?int $importId = null;

    /** @var array<int, array{rank: ?int, name: string, points: string, member_id: ?int, suggestion: ?string, existing: ?int}> */
    public array $rows = [];

    public bool $loaded = false;

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
    public function import(): ?Import
    {
        return $this->importId ? $this->team->imports()->find($this->importId) : null;
    }

    #[Computed]
    public function weekStart(): CarbonImmutable
    {
        return $this->now()
            ->startOfWeek(CarbonInterface::MONDAY)
            ->addWeeks($this->weekOffset);
    }

    /**
     * The roster to match parsed names against, with aliases eager loaded.
     *
     * @return Collection<int, \App\Models\Member>
     */
    #[Computed]
    public function roster(): Collection
    {
        return $this->team->roster()->with('aliases')->orderBy('name')->get();
    }

    public function startParse(BeginVsScoreScreenshotImport $beginImport): void
    {
        $this->validate([
            'screenshots' => ['required', 'array', 'min:1'],
            'screenshots.*' => ['image', 'max:10240'],
        ]);

        $paths = array_map(
            fn ($file): string => $file->store('imports', 'local'),
            $this->screenshots,
        );

        $import = $beginImport->handle($this->team, Auth::user(), $this->weekStart, $paths);

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

    public function addRow(): void
    {
        $this->rows[] = ['rank' => null, 'name' => '', 'points' => '', 'member_id' => null, 'suggestion' => null, 'existing' => null];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    /**
     * Accept the fuzzy match, adopting the roster member's spelling of the name.
     */
    public function applySuggestion(int $index): void
    {
        if (isset($this->rows[$index]['suggestion'])) {
            $this->rows[$index]['name'] = $this->rows[$index]['suggestion'];
            $this->rows[$index]['suggestion'] = null;
        }
    }

    /**
     * The rows that will actually be written, i.e. those bound to a roster member.
     */
    #[Computed]
    public function matchedCount(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => filled($row['member_id'])));
    }

    public function confirm(ConfirmVsScoreImport $confirmImport): void
    {
        $import = $this->import;

        abort_unless($import?->status === ImportStatus::AwaitingReview, 404);

        // An unselected member <select> arrives as an empty string, which would fail
        // the integer rule; "skip this row" is a null member, not a malformed one.
        $this->rows = array_map(fn (array $row): array => [
            ...$row,
            'member_id' => blank($row['member_id']) ? null : (int) $row['member_id'],
        ], $this->rows);

        $this->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.points' => ['required', 'integer', 'min:0'],
            'rows.*.member_id' => ['nullable', 'integer'],
        ]);

        $scores = array_map(fn (array $row): array => [
            'member_id' => $row['member_id'],
            'name' => $row['name'],
            'points' => (int) $row['points'],
        ], $this->rows);

        $confirmImport->handle($import, $this->weekStart, $scores);

        Flux::toast(variant: 'success', text: __('VS score import started.'));

        $this->redirectRoute('scores.index', ['weekOffset' => $this->weekOffset], navigate: true);
    }

    /**
     * Match each parsed row to a roster member and note what it would replace.
     */
    private function loadDraft(Import $import): void
    {
        $roster = $this->roster;
        $matcher = app(RosterNameMatcher::class);
        $existing = $this->existingScores();

        $this->rows = array_map(function (array $row) use ($roster, $matcher, $existing): array {
            $match = $matcher->suggest($row['name'], $roster);

            return [
                'rank' => $row['rank'] ?? null,
                'name' => $row['name'],
                'points' => (string) $row['points'],
                'member_id' => $match?->id,
                'suggestion' => $match && $match->name !== $row['name'] ? $match->name : null,
                'existing' => $match ? ($existing[$match->id] ?? null) : null,
            ];
        }, $import->payload['rows'] ?? []);

        $this->loaded = true;
    }

    /**
     * The points already recorded for the active week, keyed by member.
     *
     * @return array<int, int>
     */
    private function existingScores(): array
    {
        return $this->team->roster()
            ->with(['scores' => fn ($query) => $query->whereDate('week_start', $this->weekStart->toDateString())])
            ->get()
            ->mapWithKeys(fn ($member): array => [$member->id => $member->scores->first()?->points])
            ->filter()
            ->all();
    }

    /**
     * Re-evaluate the target week, refreshing what each row would overwrite.
     */
    private function refreshWeek(): void
    {
        unset($this->weekStart, $this->roster);

        if ($this->rows === []) {
            return;
        }

        $existing = $this->existingScores();

        $this->rows = array_map(fn (array $row): array => [
            ...$row,
            'existing' => filled($row['member_id']) ? ($existing[(int) $row['member_id']] ?? null) : null,
        ], $this->rows);
    }

    /**
     * The current moment in the user's timezone, falling back to the app default.
     */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone ?? config('app.timezone'));
    }
}; ?>

<section class="w-full" x-data x-init="$wire.setTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone)">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Import VS scores') }}</flux:heading>
            <flux:subheading>{{ __('Upload your in-game Weekly Rank screenshots to fill in the week.') }}</flux:subheading>
        </div>

        <flux:button :href="route('scores.index')" wire:navigate variant="ghost" icon="arrow-left">
            {{ __('Back to VS scores') }}
        </flux:button>
    </div>

    <div class="mt-6 flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700">
        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Importing into week') }}</flux:text>

        <div class="flex items-center gap-3">
            <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousWeek" data-test="import-prev-week" />

            <div class="text-center text-sm font-medium" data-test="import-week-range">
                {{ $this->weekStart->format('M j') }} – {{ $this->weekStart->addDays(5)->format('M j') }}
            </div>

            <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextWeek" :disabled="$weekOffset >= 0" data-test="import-next-week" />
        </div>
    </div>

    @php($import = $this->import)

    {{-- Step 1: upload --}}
    @if (! $import)
        <flux:callout variant="warning" class="mt-6" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Use the Weekly Rank tab') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Capture the ranking screen with Weekly Rank selected, not Daily Rank. Daily points are a single day, not the week total.') }}</flux:callout.text>
        </flux:callout>

        <form wire:submit="startParse" class="mt-6 space-y-6" data-test="vs-screenshot-upload-form">
            <flux:input
                type="file"
                wire:model="screenshots"
                :label="__('Screenshots')"
                multiple
                accept="image/*"
                data-test="vs-screenshot-input"
            />

            <flux:error name="screenshots" />

            <div class="flex justify-end">
                <flux:button variant="primary" type="submit" icon="sparkles" data-test="vs-screenshot-parse-button">
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
            data-test="vs-screenshot-processing"
        >
            <flux:icon.loading />
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Reading your screenshots…') }}</flux:text>
        </div>

    {{-- Failed --}}
    @elseif ($import->status === ImportStatus::Failed)
        <flux:callout variant="danger" class="mt-6" icon="exclamation-triangle" data-test="vs-screenshot-failed">
            <flux:callout.heading>{{ __('We could not read those screenshots') }}</flux:callout.heading>
            <flux:callout.text>{{ $import->error }}</flux:callout.text>
        </flux:callout>

    {{-- Step 3: review draft --}}
    @else
        <form wire:submit="confirm" class="mt-6 space-y-4" data-test="vs-review-form">
            <div class="flex items-center justify-between">
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __(':matched of :count rows matched a roster member. Review and edit before importing.', ['matched' => $this->matchedCount, 'count' => count($this->rows)]) }}
                </flux:text>

                <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="addRow" data-test="vs-review-add-row">
                    {{ __('Add row') }}
                </flux:button>
            </div>

            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                            <th class="px-3 py-3 text-left font-medium">{{ __('Rank') }}</th>
                            <th class="px-3 py-3 text-left font-medium">{{ __('Read as') }}</th>
                            <th class="px-3 py-3 text-left font-medium">{{ __('Member') }}</th>
                            <th class="px-3 py-3 text-right font-medium">{{ __('Points') }}</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->rows as $index => $row)
                            <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800" data-test="vs-review-row" wire:key="vs-row-{{ $index }}">
                                <td class="px-3 py-2 text-zinc-500 tabular-nums dark:text-zinc-400">{{ $row['rank'] }}</td>

                                <td class="px-3 py-2">
                                    <flux:input size="sm" wire:model="rows.{{ $index }}.name" data-test="vs-review-name-input" />

                                    @if ($row['suggestion'])
                                        <flux:text size="sm" class="mt-1 text-amber-600 dark:text-amber-500">
                                            {{ __('Matched to') }}
                                            <button type="button" class="font-medium underline" wire:click="applySuggestion({{ $index }})" data-test="vs-review-suggestion">{{ $row['suggestion'] }}</button>
                                        </flux:text>
                                    @endif
                                </td>

                                <td class="px-3 py-2">
                                    <flux:select size="sm" wire:model="rows.{{ $index }}.member_id" data-test="vs-review-member-select">
                                        <flux:select.option value="">{{ __('Skip this row') }}</flux:select.option>
                                        @foreach ($this->roster as $member)
                                            <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    @if (blank($row['member_id']))
                                        <flux:badge color="zinc" size="sm" class="mt-1">{{ __('Will be skipped') }}</flux:badge>
                                    @elseif ($row['existing'] !== null)
                                        <flux:badge color="amber" size="sm" class="mt-1">{{ __('Replaces :points', ['points' => number_format($row['existing'])]) }}</flux:badge>
                                    @endif
                                </td>

                                <td class="px-3 py-2">
                                    <div class="flex justify-end">
                                        <flux:input
                                            type="number"
                                            min="0"
                                            size="sm"
                                            class="w-40 text-right"
                                            wire:model="rows.{{ $index }}.points"
                                            data-test="vs-review-points-input"
                                        />
                                    </div>
                                </td>

                                <td class="px-3 py-2">
                                    <flux:button type="button" variant="ghost" size="sm" icon="trash" wire:click="removeRow({{ $index }})" data-test="vs-review-remove-row" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center">
                                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No scores were read. Add rows manually or try clearer screenshots.') }}</flux:text>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button :href="route('scores.index')" wire:navigate variant="filled" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button variant="primary" type="submit" icon="check" data-test="vs-review-confirm-button">
                    {{ __('Import :count scores', ['count' => $this->matchedCount]) }}
                </flux:button>
            </div>
        </form>
    @endif
</section>
