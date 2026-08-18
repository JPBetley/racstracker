<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Command post for the RACS alliance')])
    </head>
    <body class="min-h-screen bg-zinc-950 text-zinc-300 antialiased">
        <div class="relative isolate overflow-hidden">
            {{-- Ambient glow and grid behind the hero --}}
            <div class="pointer-events-none absolute inset-x-0 -top-64 -z-10 h-[42rem] bg-[radial-gradient(60%_60%_at_50%_40%,rgba(245,158,11,0.16),transparent_70%)]"></div>
            <div class="pointer-events-none absolute inset-0 -z-10 bg-[linear-gradient(to_right,rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.04)_1px,transparent_1px)] bg-[size:56px_56px] [mask-image:radial-gradient(70%_50%_at_50%_0%,black,transparent)]"></div>

            <header class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-6 lg:px-8">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <span class="flex aspect-square size-9 items-center justify-center rounded-md bg-white">
                        <x-app-logo-icon class="size-5 fill-current text-black" />
                    </span>
                    <span class="text-base font-semibold text-white">RACS Tracker</span>
                </a>

                <nav class="flex items-center gap-2 text-sm">
                    @auth
                        @if (auth()->user()->currentTeam)
                            <a href="{{ route('dashboard') }}" class="rounded-md bg-white px-4 py-2 font-medium text-zinc-950 transition hover:bg-zinc-200">
                                {{ __('Open dashboard') }}
                            </a>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="rounded-md px-4 py-2 font-medium text-zinc-300 transition hover:text-white">
                            {{ __('Log in') }}
                        </a>

                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="rounded-md bg-white px-4 py-2 font-medium text-zinc-950 transition hover:bg-zinc-200">
                                {{ __('Create account') }}
                            </a>
                        @endif
                    @endauth
                </nav>
            </header>

            <main>
                <section class="mx-auto w-full max-w-6xl px-6 pt-12 pb-20 lg:px-8 lg:pt-24 lg:pb-28">
                    <div class="max-w-3xl">
                        <span class="inline-flex items-center gap-2 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-medium tracking-wide text-amber-400 uppercase">
                            <span class="size-1.5 rounded-full bg-amber-400"></span>
                            {{ __('Server 1919 · Last War: Survival') }}
                        </span>

                        <h1 class="mt-6 text-4xl font-semibold tracking-tight text-balance text-white sm:text-6xl">
                            {{ __('Command post for the RACS alliance.') }}
                        </h1>

                        <p class="mt-6 max-w-2xl text-lg/8 text-zinc-400">
                            {{ __('Leading an alliance should not mean scrolling chat for the train schedule or arguing over who carried last VS. RACS Tracker gives the leadership team one place for the roster, the conductor rota, and every weekly Duel score.') }}
                        </p>

                        <div class="mt-10 flex flex-wrap items-center gap-3">
                            @auth
                                @if (auth()->user()->currentTeam)
                                    <a href="{{ route('dashboard') }}" class="rounded-md bg-white px-5 py-2.5 text-sm font-semibold text-zinc-950 transition hover:bg-zinc-200">
                                        {{ __('Open dashboard') }}
                                    </a>
                                @endif
                            @else
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="rounded-md bg-white px-5 py-2.5 text-sm font-semibold text-zinc-950 transition hover:bg-zinc-200">
                                        {{ __('Create account') }}
                                    </a>
                                @endif

                                <a href="{{ route('login') }}" class="rounded-md border border-zinc-700 px-5 py-2.5 text-sm font-semibold text-zinc-200 transition hover:border-zinc-500 hover:text-white">
                                    {{ __('Log in') }}
                                </a>
                            @endauth
                        </div>

                        <p class="mt-4 text-sm text-zinc-500">
                            {{ __('Ask an R4 or R5 for an invitation to the alliance workspace.') }}
                        </p>
                    </div>
                </section>

                <section class="mx-auto w-full max-w-6xl px-6 pb-20 lg:px-8 lg:pb-28">
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ([
                            ['icon' => 'users', 'title' => __('The roster, kept straight'), 'body' => __('Every member with their UID, in-game position, and whether they are still active. Aliases keep a name change from splitting somebody\'s history in two.')],
                            ['icon' => 'calendar-days', 'title' => __('Train conductor rota'), 'body' => __('Plan the week ahead, see at a glance who has the train today and tomorrow, and flag the MVP days so nobody gets skipped twice.')],
                            ['icon' => 'trophy', 'title' => __('VS scores that stick around'), 'body' => __('Weekly Duel points per member, stored week after week, with the current leaders waiting on the dashboard the moment you log in.')],
                            ['icon' => 'sparkles', 'title' => __('Imports that do the typing'), 'body' => __('Drop in a scoreboard screenshot and let it read the numbers, or pull the roster straight from the game. Names are matched back to your roster automatically.')],
                        ] as $feature)
                            <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-6">
                                <span class="flex size-10 items-center justify-center rounded-lg bg-zinc-800 text-amber-400">
                                    <flux:icon :icon="$feature['icon']" class="size-5" />
                                </span>
                                <h2 class="mt-5 text-lg font-semibold text-white">{{ $feature['title'] }}</h2>
                                <p class="mt-2 text-sm/6 text-zinc-400">{{ $feature['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="mx-auto w-full max-w-6xl px-6 pb-20 lg:px-8 lg:pb-28">
                    <h2 class="text-2xl font-semibold tracking-tight text-white">{{ __('How a week runs') }}</h2>

                    <ol class="mt-8 grid gap-8 sm:grid-cols-3">
                        @foreach ([
                            __('Build the roster once — import it from the game or add members by hand.'),
                            __('Assign the train for the week ahead so everybody knows their day.'),
                            __('Import the VS scoreboard when the week closes and let the numbers settle the debate.'),
                        ] as $index => $step)
                            <li class="border-t border-zinc-800 pt-5">
                                <span class="text-sm font-semibold text-amber-400">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                <p class="mt-2 text-sm/6 text-zinc-400">{{ $step }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </main>

            <footer class="border-t border-zinc-800/80">
                <div class="mx-auto flex w-full max-w-6xl flex-col gap-2 px-6 py-8 text-sm text-zinc-500 lg:flex-row lg:items-center lg:justify-between lg:px-8">
                    <p>{{ __('RACS Tracker — built for the leadership of the RACS alliance, server 1919.') }}</p>
                    <p>{{ __('A fan-made tool. Not affiliated with or endorsed by the makers of Last War: Survival.') }}</p>
                </div>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
