<?php

declare(strict_types=1);

use App\DTOs\CommandProgress;

it('labels progress with whatever figures the script gave', function (array $progress, string $label, ?string $percentLabel): void {
    $commandProgress = CommandProgress::fromArray(['schema' => 'rmm.progress/1', ...$progress]);

    expect($commandProgress->label())->toBe($label)
        ->and($commandProgress->percentLabel())->toBe($percentLabel);
})->with([
    'everything' => [['percent' => 42.5, 'done' => 7612, 'total' => 18128, 'unit' => 'files', 'bytes_done' => 1288490188, 'bytes_total' => 3328599654, 'eta_seconds' => 312], '42% · 7,612 / 18,128 files · 1.2 / 3.1 GB · ~5 min left', '42%'],
    'nothing but the schema' => [[], '', null],
    'percent only, never rounded up to done' => [['percent' => 99.9], '99%', '99%'],
    'percent worked out from counts' => [['done' => 25, 'total' => 200, 'unit' => 'files'], '12% · 25 / 200 files', '12%'],
    'percent worked out from bytes' => [['bytes_done' => 500, 'bytes_total' => 1000], '50% · 500 / 1,000 B', '50%'],
    'bytes in the total\'s unit' => [['bytes_done' => 512, 'bytes_total' => 1024], '50% · 0.5 / 1.0 KB', '50%'],
    'a count with no total or unit' => [['done' => 1500], '1,500', null],
    'bytes with no total' => [['bytes_done' => 1572864], '1.5 MB', null],
    'a zero total does not divide' => [['done' => 0, 'total' => 0], '0 / 0', null],
    'seconds left' => [['eta_seconds' => 45], 'under a minute left', null],
    'hours left' => [['eta_seconds' => 4800], '~1 h 20 min left', null],
    'whole hours left' => [['eta_seconds' => 7200], '~2 h left', null],
]);

it('works out the percent from counts before bytes, and caps it at 100', function (): void {
    expect(CommandProgress::fromArray(['schema' => 'rmm.progress/1', 'done' => 3, 'total' => 4, 'bytes_done' => 1, 'bytes_total' => 4])->percent())->toEqual(75.0)
        ->and(CommandProgress::fromArray(['schema' => 'rmm.progress/1', 'done' => 9, 'total' => 4])->percent())->toEqual(100.0)
        ->and(CommandProgress::fromArray(['schema' => 'rmm.progress/1', 'percent' => 300])->percent())->toEqual(100.0);
});

it('cuts a long current path in the middle so the drive and file name both show', function (): void {
    $path = 'C:\\Users\\sophie\\'.str_repeat('Very Long Folder\\', 10).'Quarterly accounts.xlsx';

    $shown = CommandProgress::fromArray(['schema' => 'rmm.progress/1', 'current' => $path])->currentForDisplay();

    expect(mb_strlen($shown))->toBe(config('commands.progress.current_display_length'))
        ->and($shown)->toStartWith('C:\\Users\\sophie\\')
        ->toEndWith('Quarterly accounts.xlsx')
        ->toContain('…');
});

it('leaves a short current path and a missing one alone', function (): void {
    expect(CommandProgress::fromArray(['schema' => 'rmm.progress/1', 'current' => 'C:\\x.txt'])->currentForDisplay())->toBe('C:\\x.txt')
        ->and(CommandProgress::fromArray(['schema' => 'rmm.progress/1'])->currentForDisplay())->toBeNull();
});
