@props(['variant' => 'default'])

@php
    $colors = match ($variant) {
        'amber' => 'border-amber-400 bg-amber-50 text-amber-800 dark:border-amber-500/50 dark:bg-amber-950/30 dark:text-amber-200',
        default => 'border-zinc-300 bg-zinc-50 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-300',
    };
@endphp

<div class="my-4 rounded-lg border-l-4 px-4 py-3 text-sm {{ $colors }}">
    <strong>{{ __('Tip:') }}</strong> {{ $slot }}
</div>
