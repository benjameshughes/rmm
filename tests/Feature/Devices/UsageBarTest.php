<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('draws a usage bar with the Flux progress bar, its figure, colour and width up front', function (): void {
    $html = Blade::render('<x-device.usage-bar :percent="95.04" color="red" title="C: nearly full" />');

    expect($html)->toContain('data-flux-progress')
        ->toContain('value="95"')
        ->toContain('wire:key="usage-bar-95"')
        ->toContain('--flux-progress-percentage: 95%')
        ->toContain('var(--color-red-600)')
        ->toContain('dark:[--flux-progress-color:var(--color-red-400)]')
        ->toContain('role="progressbar"')
        ->toContain('aria-valuenow="95"')
        ->toContain('title="C: nearly full"')
        ->toContain(' h-2"');
});

it('keeps the thin bar at the Flux default height', function (): void {
    $html = Blade::render('<x-device.usage-bar :percent="40" color="blue" thin />');

    expect($html)->toContain('var(--color-blue-600)')
        ->not->toContain(' h-2"');
});

it('draws a neutral bar in zinc, which Flux has no colour for', function (): void {
    $html = Blade::render('<x-device.usage-bar :percent="30" color="zinc" thin />');

    expect($html)->toContain('[--flux-progress-color:var(--color-zinc-400)]!')
        ->toContain('dark:[--flux-progress-color:var(--color-zinc-500)]!');
});

it('draws an empty bar for an unknown figure', function (): void {
    $html = Blade::render('<x-device.usage-bar :percent="null" color="blue" />');

    expect($html)->toContain('wire:key="usage-bar-0"')
        ->toContain('--flux-progress-percentage: 0%');
});
