@props(['change'])

@php($direction = $change->direction())

@if($change->isMeasured())
    <flux:badge size="sm" :color="$direction->color()" :title="$direction->label()" class="tabular-nums" data-direction="{{ $direction->value }}">{{ $change->differenceForHumans() }}</flux:badge>
@endif
