<x-layouts::app :title="__('Guide')">
    <div class="mx-auto max-w-3xl">
        <flux:heading size="xl">{{ __('Guide') }}</flux:heading>
        <flux:subheading>{{ __('How to submit VS scores and plan your weekly train conductor schedule.') }}</flux:subheading>

        {{-- Table of Contents --}}
        <div class="mt-6 rounded-lg border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-3 text-xs font-semibold tracking-wider text-zinc-400 uppercase">{{ __('In this guide') }}</div>
            <ol class="list-inside list-decimal space-y-1 text-sm font-medium marker:text-zinc-400">
                <li>
                    <a href="#vs-scores" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">{{ __('Submitting VS Scores') }}</a>
                    <ol class="mt-1 ml-5 list-inside list-decimal space-y-0.5 text-zinc-500 marker:text-zinc-300 dark:text-zinc-400 dark:marker:text-zinc-600">
                        <li><a href="#manual-entry" class="hover:text-zinc-700 dark:hover:text-zinc-200">{{ __('Manual entry') }}</a></li>
                        <li><a href="#screenshot-import" class="hover:text-zinc-700 dark:hover:text-zinc-200">{{ __('Screenshot import') }}</a></li>
                    </ol>
                </li>
                <li>
                    <a href="#train-conductor" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">{{ __('Using the Train Conductor') }}</a>
                    <ol class="mt-1 ml-5 list-inside list-decimal space-y-0.5 text-zinc-500 marker:text-zinc-300 dark:text-zinc-400 dark:marker:text-zinc-600">
                        <li><a href="#plan-week" class="hover:text-zinc-700 dark:hover:text-zinc-200">{{ __('Planning a week') }}</a></li>
                        <li><a href="#manage-assignments" class="hover:text-zinc-700 dark:hover:text-zinc-200">{{ __('Managing assignments') }}</a></li>
                    </ol>
                </li>
            </ol>
        </div>

        {{-- ============================== --}}
        {{-- SECTION 1: VS SCORES           --}}
        {{-- ============================== --}}
        <section id="vs-scores" class="mt-12">
            <flux:badge color="amber" size="sm" class="mb-2">{{ __('VS Scores') }}</flux:badge>
            <flux:heading size="lg">{{ __('Submitting VS Scores') }}</flux:heading>
            <flux:text class="mt-1 max-w-xl">
                {{ __('Each week your alliance earns VS (versus) points. RACS Tracker gives you two ways to record them: type scores in manually, or upload screenshots from the in-game ranking screen and let AI read them for you.') }}
            </flux:text>

            <flux:text class="mt-4">
                {{ __('You\'ll find the VS Scores page in the sidebar under the trophy icon. The page shows a grid of every active roster member with an input field for their weekly points, plus a leaderboard below.') }}
            </flux:text>

            {{-- Manual Entry --}}
            <h3 id="manual-entry" class="mt-8 text-base font-semibold text-zinc-900 dark:text-white">{{ __('Manual entry') }}</h3>

            <div class="mt-4 space-y-0">
                <x-docs.step :number="1" title="Navigate to VS Scores" :last="false">
                    {{ __('Click') }} <strong>{{ __('VS Scores') }}</strong> {{ __('in the sidebar. The page opens on the current week by default.') }}
                </x-docs.step>

                <x-docs.step :number="2" title="Select the week" :last="false">
                    {{ __('Use the left and right arrows next to the week label to move between weeks. You can go back to previous weeks, but you can\'t go past the current one.') }}
                </x-docs.step>

                <x-docs.step :number="3" title="Enter scores" :last="false">
                    {{ __('Type each member\'s VS points into the input field next to their name. Leave a field blank if you don\'t have their score. Clearing a field that previously had a value will remove that score.') }}

                    {{-- Mock: scores grid --}}
                    <div class="pointer-events-none mt-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" aria-hidden="true">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                                    <th class="px-4 py-3 text-left font-medium">{{ __('Member') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">{{ __('VS Score') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ([
                                    ['name' => 'StarBlade', 'position' => 'R5', 'score' => '1,842,500'],
                                    ['name' => 'NightHawk', 'position' => 'R4', 'score' => '1,205,000'],
                                    ['name' => 'IronWolf', 'position' => 'R4', 'score' => ''],
                                    ['name' => 'QueenBee', 'position' => 'R3', 'score' => '980,300'],
                                ] as $example)
                                    <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                                        <td class="px-4 py-2">
                                            <div class="flex items-center gap-3">
                                                <flux:avatar size="sm" :name="$example['name']" :initials="strtoupper(substr($example['name'], 0, 1))" />
                                                <span class="font-medium">{{ $example['name'] }}</span>
                                                <flux:badge color="zinc" size="sm">{{ $example['position'] }}</flux:badge>
                                            </div>
                                        </td>
                                        <td class="px-4 py-2">
                                            <div class="flex justify-end">
                                                <div class="w-40 rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800">
                                                    {{ $example['score'] }}
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-docs.step>

                <x-docs.step :number="4" title="Save" :last="true">
                    {{ __('Click') }} <strong>{{ __('Save VS Scores') }}</strong>. {{ __('The leaderboard below the grid updates automatically to show the weekly rankings.') }}

                    {{-- Mock: leaderboard --}}
                    <div class="pointer-events-none mt-4 rounded-lg border border-zinc-200 dark:border-zinc-700" aria-hidden="true">
                        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                            <div class="text-sm font-semibold">{{ __('Weekly leaderboard') }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Total points for the week') }}</div>
                        </div>
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ([
                                ['rank' => 1, 'name' => 'StarBlade', 'position' => 'R5', 'score' => 1842500],
                                ['rank' => 2, 'name' => 'NightHawk', 'position' => 'R4', 'score' => 1205000],
                                ['rank' => 3, 'name' => 'QueenBee', 'position' => 'R3', 'score' => 980300],
                            ] as $entry)
                                <div class="flex items-center justify-between px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="w-6 text-center text-zinc-500 tabular-nums dark:text-zinc-400">{{ $entry['rank'] }}</span>
                                        <span class="font-medium">{{ $entry['name'] }}</span>
                                        <flux:badge color="zinc" size="sm">{{ $entry['position'] }}</flux:badge>
                                    </div>
                                    <span class="font-semibold tabular-nums">{{ number_format($entry['score']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </x-docs.step>
            </div>

            <x-docs.callout>
                {{ __('You only need to record scores for members who participated. Blank fields are simply skipped.') }}
            </x-docs.callout>

            {{-- Screenshot Import --}}
            <h3 id="screenshot-import" class="mt-10 text-base font-semibold text-zinc-900 dark:text-white">{{ __('Screenshot import') }}</h3>
            <flux:text class="mt-1">
                {{ __('If you have screenshots of the in-game Weekly Rank screen, you can upload them and let the app\'s AI read the scores automatically. This is usually faster than typing them in one by one.') }}
            </flux:text>

            <div class="mt-4 space-y-0">
                <x-docs.step :number="1" title="Open the import page" :last="false">
                    {{ __('On the VS Scores page, click') }} <strong>{{ __('Import from screenshots') }}</strong> {{ __('(top-right). This takes you to the import screen.') }}
                </x-docs.step>

                <x-docs.step :number="2" title="Upload your screenshots" :last="false">
                    {{ __('Click the upload area or drag your screenshots in. You can upload multiple images — for example, several scrolled captures of the ranking screen to cover the full alliance.') }}
                    <ul class="mt-2 ml-4 list-disc space-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                        <li>{{ __('Each file uploads one at a time') }}</li>
                        <li>{{ __('You can remove individual screenshots before processing') }}</li>
                        <li>{{ __('Select the correct target week if it isn\'t already set') }}</li>
                    </ul>
                </x-docs.step>

                <x-docs.step :number="3" title="Read screenshots" :last="false">
                    {{ __('Click') }} <strong>{{ __('Read screenshots') }}</strong>. {{ __('The app sends your images to an AI vision model that extracts player names, ranks, and point totals. A spinner shows while it processes — this usually takes 10–30 seconds.') }}
                </x-docs.step>

                <x-docs.step :number="4" title="Review the results" :last="false">
                    {{ __('Once processing finishes, you\'ll see a review table. Each row shows the name the AI read, the matched roster member, and the points. You can edit any field or reassign the member via dropdown.') }}

                    {{-- Mock: import review table --}}
                    <div class="pointer-events-none mt-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" aria-hidden="true">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                                    <th class="px-3 py-3 text-left font-medium">{{ __('Rank') }}</th>
                                    <th class="px-3 py-3 text-left font-medium">{{ __('Read as') }}</th>
                                    <th class="px-3 py-3 text-left font-medium">{{ __('Member') }}</th>
                                    <th class="px-3 py-3 text-right font-medium">{{ __('Points') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ([
                                    ['rank' => 1, 'read' => 'StarBIade', 'member' => 'StarBlade', 'points' => '1,842,500', 'suggestion' => 'StarBlade', 'existing' => null],
                                    ['rank' => 2, 'read' => 'NightHawk', 'member' => 'NightHawk', 'points' => '1,205,000', 'suggestion' => null, 'existing' => 1180000],
                                    ['rank' => 3, 'read' => 'QueenB33', 'member' => 'QueenBee', 'points' => '980,300', 'suggestion' => 'QueenBee', 'existing' => null],
                                    ['rank' => 4, 'read' => 'xXDarkLordXx', 'member' => null, 'points' => '870,100', 'suggestion' => null, 'existing' => null],
                                ] as $row)
                                    <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                                        <td class="px-3 py-2 text-zinc-500 tabular-nums dark:text-zinc-400">{{ $row['rank'] }}</td>
                                        <td class="px-3 py-2">
                                            <div class="rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                                                {{ $row['read'] }}
                                            </div>
                                            @if ($row['suggestion'])
                                                <div class="mt-1 text-xs text-amber-600 dark:text-amber-500">
                                                    {{ __('Matched to') }} <span class="font-medium underline">{{ $row['suggestion'] }}</span>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                                                {{ $row['member'] ?? '—' }}
                                            </div>
                                            @if ($row['member'] === null)
                                                <flux:badge color="zinc" size="sm" class="mt-1">{{ __('Will be skipped') }}</flux:badge>
                                            @elseif ($row['existing'] !== null)
                                                <flux:badge color="amber" size="sm" class="mt-1">{{ __('Replaces :points', ['points' => number_format($row['existing'])]) }}</flux:badge>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="flex justify-end">
                                                <div class="w-32 rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800">
                                                    {{ $row['points'] }}
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-docs.step>

                <x-docs.step :number="5" title="Confirm the import" :last="true">
                    {{ __('Once you\'re happy with the matches, click') }} <strong>{{ __('Import') }}</strong>. {{ __('Scores are saved for each matched member. Any row without a matched member is skipped. If a member already has a score for that week, the imported value replaces it.') }}
                </x-docs.step>
            </div>

            <x-docs.callout variant="amber">
                {{ __('The AI handles overlapping scroll captures, non-Latin characters, and the pinned green "your row" in the ranking screen. If a name doesn\'t auto-match, you can manually pick the right member from the dropdown before confirming.') }}
            </x-docs.callout>
        </section>

        <hr class="my-12 border-zinc-200 dark:border-zinc-700">

        {{-- ============================== --}}
        {{-- SECTION 2: TRAIN CONDUCTOR     --}}
        {{-- ============================== --}}
        <section id="train-conductor" class="mt-0">
            <flux:badge color="cyan" size="sm" class="mb-2">{{ __('Train Conductor') }}</flux:badge>
            <flux:heading size="lg">{{ __('Using the Train Conductor') }}</flux:heading>
            <flux:text class="mt-1 max-w-xl">
                {{ __('The Train Conductor tracks which alliance member "has the train" each day. The weekly planning wizard assigns conductors for a full Sunday–Saturday week, using VS scores and participation rules to determine who\'s eligible.') }}
            </flux:text>

            <flux:text class="mt-4">
                {{ __('Open it from the sidebar under') }} <strong>{{ __('Train Conductor') }}</strong> {{ __('(calendar icon). The main page shows a history of all assignments.') }}
            </flux:text>

            {{-- Mock: conductor history --}}
            <div class="pointer-events-none mt-4" aria-hidden="true">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Conductor') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Day') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ([
                            ['name' => 'StarBlade', 'date' => 'Aug 24, 2026', 'day' => 'Sunday', 'color' => 'yellow', 'mvp' => true],
                            ['name' => 'IronWolf', 'date' => 'Aug 23, 2026', 'day' => 'Saturday', 'color' => 'yellow', 'mvp' => false],
                            ['name' => 'QueenBee', 'date' => 'Aug 22, 2026', 'day' => 'Friday', 'color' => 'yellow', 'mvp' => false],
                            ['name' => 'NightHawk', 'date' => 'Aug 21, 2026', 'day' => 'Thursday', 'color' => 'green', 'mvp' => false],
                        ] as $a)
                            <flux:table.row>
                                <flux:table.cell variant="strong">
                                    <div class="flex items-center gap-3">
                                        <flux:avatar size="xs" :name="$a['name']" :initials="strtoupper(substr($a['name'], 0, 1))" />
                                        {{ $a['name'] }}
                                        @if ($a['mvp'])
                                            <flux:badge size="sm" color="amber" icon="star">{{ __('MVP') }}</flux:badge>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>{{ $a['date'] }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$a['color']">{{ $a['day'] }}</flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            {{-- Planning a week --}}
            <h3 id="plan-week" class="mt-8 text-base font-semibold text-zinc-900 dark:text-white">{{ __('Planning a week') }}</h3>
            <flux:text class="mt-1">
                {{ __('Click the') }} <strong>{{ __('Plan week') }}</strong> {{ __('button to open the 4-step wizard. The wizard plans the upcoming Sunday through Saturday.') }}
            </flux:text>

            <div class="mt-4 space-y-0">
                <x-docs.step :number="1" title="VS Threshold" :last="false">
                    {{ __('The wizard shows every active roster member alongside their VS score from the most recent completed week. Each member is marked as') }} <strong>{{ __('Pass') }}</strong> {{ __('or') }} <strong>{{ __('Below') }}</strong> {{ __('based on your team\'s VS requirement.') }}

                    {{-- Mock: VS threshold table --}}
                    <div class="pointer-events-none mt-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" aria-hidden="true">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                                    <th class="px-4 py-3 text-left font-medium">{{ __('Member') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">{{ __('VS Score') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">{{ __('Eligible') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ([
                                    ['name' => 'StarBlade', 'score' => 1842500, 'passes' => true],
                                    ['name' => 'NightHawk', 'score' => 1205000, 'passes' => true],
                                    ['name' => 'QueenBee', 'score' => 980300, 'passes' => true],
                                    ['name' => 'ShadowFax', 'score' => 420000, 'passes' => false],
                                    ['name' => 'Newbie99', 'score' => 0, 'passes' => false],
                                ] as $row)
                                    <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                                        <td class="px-4 py-2">
                                            <div class="flex items-center gap-3">
                                                <flux:avatar size="sm" :name="$row['name']" :initials="strtoupper(substr($row['name'], 0, 1))" />
                                                <span class="font-medium">{{ $row['name'] }}</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($row['score']) }}</td>
                                        <td class="px-4 py-2">
                                            <div class="flex justify-end">
                                                <flux:badge size="sm" :color="$row['passes'] ? 'green' : 'red'">
                                                    {{ $row['passes'] ? __('Pass') : __('Below') }}
                                                </flux:badge>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Members who don\'t meet the threshold are filtered out. If your team\'s VS requirement is set to 0, everyone passes.') }}
                    </div>
                </x-docs.step>

                <x-docs.step :number="2" title="Desert Storm" :last="false">
                    {{ __('This step only appears if your team requires Desert Storm participation (configured in Team Settings). All eligible members start checked — untick anyone who did not participate.') }}
                    <flux:text class="mt-2">
                        {{ __('You can use the') }} <strong>{{ __('Select all') }}</strong> / <strong>{{ __('Select none') }}</strong> {{ __('buttons for quick toggling. If your team doesn\'t require Desert Storm, this step is skipped automatically.') }}
                    </flux:text>
                </x-docs.step>

                <x-docs.step :number="3" title="Candidate list" :last="false">
                    {{ __('After filtering, the remaining candidates are ranked by how long it\'s been since they last conducted. Members who have never conducted appear first.') }}

                    {{-- Mock: candidate list --}}
                    <div class="pointer-events-none mt-4 divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700" aria-hidden="true">
                        @foreach ([
                            ['rank' => 1, 'name' => 'QueenBee', 'last' => null],
                            ['rank' => 2, 'name' => 'NightHawk', 'last' => 'Jul 28, 2026'],
                            ['rank' => 3, 'name' => 'StarBlade', 'last' => 'Aug 3, 2026'],
                            ['rank' => 4, 'name' => 'IronWolf', 'last' => 'Aug 10, 2026'],
                        ] as $row)
                            <div class="flex items-center justify-between px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="w-6 text-right text-sm text-zinc-500 tabular-nums dark:text-zinc-400">{{ $row['rank'] }}</span>
                                    <flux:avatar size="sm" :name="$row['name']" :initials="strtoupper(substr($row['name'], 0, 1))" />
                                    <span class="font-medium">{{ $row['name'] }}</span>
                                </div>
                                <flux:badge size="sm" :color="$row['last'] ? 'zinc' : 'amber'">
                                    {{ $row['last'] ? __('Last on :date', ['date' => $row['last']]) : __('Never conducted') }}
                                </flux:badge>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('If fewer than 6 candidates remain, you\'ll see a warning — you may need to relax your team\'s requirements.') }}
                    </div>
                </x-docs.step>

                <x-docs.step :number="4" title="Assign days" :last="true">
                    {{ __('The final step shows 7 rows — one for each day of the week.') }}
                    <ul class="mt-2 ml-4 list-disc space-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                        <li><strong class="text-zinc-700 dark:text-zinc-300">{{ __('Sunday') }}</strong> {{ __('is the MVP day. You pick the MVP conductor from the full roster.') }}</li>
                        <li><strong class="text-zinc-700 dark:text-zinc-300">{{ __('Monday–Saturday') }}</strong> {{ __('are auto-filled from candidates, longest-waiting first. You can change any assignment.') }}</li>
                    </ul>

                    {{-- Mock: assign days --}}
                    <div class="pointer-events-none mt-4 divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700" aria-hidden="true">
                        @foreach ([
                            ['day' => 'Sun Aug 23', 'member' => 'StarBlade', 'mvp' => true],
                            ['day' => 'Mon Aug 24', 'member' => 'QueenBee', 'mvp' => false],
                            ['day' => 'Tue Aug 25', 'member' => 'NightHawk', 'mvp' => false],
                            ['day' => 'Wed Aug 26', 'member' => 'IronWolf', 'mvp' => false],
                            ['day' => 'Thu Aug 27', 'member' => 'Phoenix', 'mvp' => false],
                            ['day' => 'Fri Aug 28', 'member' => 'DragonZ', 'mvp' => false],
                            ['day' => 'Sat Aug 29', 'member' => 'TigerKing', 'mvp' => false],
                        ] as $row)
                            <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center">
                                <div class="flex w-48 shrink-0 items-center gap-2">
                                    <span class="font-medium">{{ $row['day'] }}</span>
                                    @if ($row['mvp'])
                                        <flux:badge size="sm" color="amber" icon="star">{{ __('MVP') }}</flux:badge>
                                    @endif
                                </div>
                                <div class="flex-1">
                                    <div class="rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                                        {{ $row['member'] }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <flux:text class="mt-3">
                        {{ __('A member can only appear once in the week. When you\'re satisfied, click') }} <strong>{{ __('Save the week') }}</strong>.
                    </flux:text>
                </x-docs.step>
            </div>

            <x-docs.callout>
                {{ __('The dashboard shows today\'s and tomorrow\'s conductor at a glance, so members can quickly check who has the train without opening the full history.') }}
            </x-docs.callout>

            {{-- Managing assignments --}}
            <h3 id="manage-assignments" class="mt-10 text-base font-semibold text-zinc-900 dark:text-white">{{ __('Managing individual assignments') }}</h3>
            <flux:text class="mt-1">
                {{ __('You don\'t have to use the weekly wizard every time. From the Train Conductor history page, you can:') }}
            </flux:text>
            <ul class="mt-3 ml-4 list-disc space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                <li><strong class="text-zinc-700 dark:text-zinc-300">{{ __('Add a single assignment') }}</strong> — {{ __('click "Assign conductor", pick a member, date, and optionally mark them as MVP.') }}</li>
                <li><strong class="text-zinc-700 dark:text-zinc-300">{{ __('Edit an assignment') }}</strong> — {{ __('click the edit button on any row to change the member, date, or MVP status.') }}</li>
                <li><strong class="text-zinc-700 dark:text-zinc-300">{{ __('Delete an assignment') }}</strong> — {{ __('click the delete button to remove an assignment entirely.') }}</li>
            </ul>
            <flux:text class="mt-3">
                {{ __('This is useful for last-minute swaps or filling in a day that wasn\'t covered by the weekly plan.') }}
            </flux:text>
        </section>
    </div>
</x-layouts::app>
