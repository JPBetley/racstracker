@props(['number', 'title', 'last' => false])

<div class="flex gap-4">
    <div class="flex flex-col items-center">
        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-zinc-900 text-xs font-semibold text-white dark:bg-white dark:text-zinc-900">
            {{ $number }}
        </div>
        @unless ($last)
            <div class="mt-1 w-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
        @endunless
    </div>
    <div class="pb-6 text-sm {{ $last ? '' : '' }}">
        <div class="font-semibold text-zinc-900 dark:text-white">{{ $title }}</div>
        <div class="mt-1 text-zinc-600 dark:text-zinc-400">{{ $slot }}</div>
    </div>
</div>
