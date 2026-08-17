@props(['member'])

{{--
    A member option for a combobox, searchable by every name the member has carried.

    Players rename themselves in game, so someone hunting the roster is as likely to
    type a name the member no longer uses. Flux filters combobox options client side
    on the option's `textContent`, so past names ride along in a hidden span: they
    match the search without showing in the list.

    The trigger clones the selected option's markup rather than reading its text, so
    `selected-label` is what keeps those past names out of it once a member is picked.
--}}
@php
    $formerNames = $member->aliases
        ->pluck('name')
        ->reject(fn (string $alias): bool => $alias === $member->name)
        ->unique();
@endphp

<flux:select.option :value="$member->id" selected-label="{{ $member->name }}">
    {{ $member->name }}

    @if ($formerNames->isNotEmpty())
        <span hidden>{{ $formerNames->implode(' ') }}</span>
    @endif
</flux:select.option>
