<?php

declare(strict_types=1);

use App\DTOs\Printers\PrinterQueue;
use App\DTOs\Printers\PrinterSnapshot;
use App\Enums\PrinterStatusFlag;
use App\Enums\PrintJobStatusFlag;
use Illuminate\Support\Carbon;

/**
 * A queue as the agent saw it at $collectedAt, with jobs submitted the given minutes earlier.
 *
 * @param  array<int, array{0: int, 1: int}>  $jobs  [status, minutes waited] pairs
 */
function queueSeen(int $status = 0, array $jobs = [], ?int $jobsCount = null, ?Carbon $collectedAt = null): PrinterQueue
{
    $collectedAt ??= Carbon::parse('2026-10-07T09:00:00Z');

    return PrinterQueue::fromArray([
        'name' => 'Zebra GK420d - ZPL',
        'status' => $status,
        'jobs_count' => $jobsCount ?? count($jobs),
        'jobs' => collect($jobs)->map(fn (array $job, int $index): array => [
            'id' => $index + 1,
            'status' => $job[0],
            'submitted' => $collectedAt->copy()->subMinutes($job[1])->toIso8601ZuluString(),
        ])->all(),
    ], $collectedAt);
}

it('decodes raw printer status bits into labelled flags, ignoring unknown bits', function (): void {
    expect(PrinterStatusFlag::fromBits(0x2 | 0x80 | 0x20000 | 0x80000000)->all())
        ->toBe([PrinterStatusFlag::Error, PrinterStatusFlag::Offline, PrinterStatusFlag::TonerLow])
        ->and(PrinterStatusFlag::Offline->label())->toBe('Offline')
        ->and(PrinterStatusFlag::Offline->color())->toBe('red')
        ->and(PrinterStatusFlag::TonerLow->color())->toBe('amber')
        ->and(PrinterStatusFlag::Printing->color())->toBe('blue')
        ->and(PrinterStatusFlag::fromBits(0))->toBeEmpty();
});

it('decodes raw job status bits, including the Vista-era retained and rendering bits', function (): void {
    expect(PrintJobStatusFlag::fromBits(0x2 | 0x10 | 0x2000)->map(fn (PrintJobStatusFlag $flag): string => $flag->label())->all())
        ->toBe(['Error', 'Printing', 'Retained'])
        ->and(PrintJobStatusFlag::fromBits(0x4000)->all())->toBe([PrintJobStatusFlag::RenderingLocally])
        ->and(PrintJobStatusFlag::BlockedDevq->isProblem())->toBeTrue()
        ->and(PrintJobStatusFlag::Retained->isProblem())->toBeFalse();
});

it('is a problem when Windows flags the printer with an error-class bit', function (PrinterStatusFlag $flag): void {
    $queue = queueSeen(status: $flag->value);

    expect($queue->hasProblem())->toBeTrue()
        ->and($queue->problemSummary())->toBe($flag->label())
        ->and($queue->problemRank())->toBe(0);
})->with([
    PrinterStatusFlag::Error, PrinterStatusFlag::PaperJam, PrinterStatusFlag::PaperOut, PrinterStatusFlag::PaperProblem,
    PrinterStatusFlag::Offline, PrinterStatusFlag::NotAvailable, PrinterStatusFlag::UserIntervention, PrinterStatusFlag::DoorOpen,
    PrinterStatusFlag::NoToner, PrinterStatusFlag::OutputBinFull, PrinterStatusFlag::ServerUnknown,
]);

it('is a problem when a job is in error', function (PrintJobStatusFlag $flag): void {
    $queue = queueSeen(jobs: [[$flag->value | PrintJobStatusFlag::Printing->value, 1]]);

    expect($queue->problemSummary())->toBe('1 job in error')
        ->and($queue->problemRank())->toBe(1);
})->with([PrintJobStatusFlag::Error, PrintJobStatusFlag::Offline, PrintJobStatusFlag::PaperOut, PrintJobStatusFlag::BlockedDevq, PrintJobStatusFlag::UserIntervention]);

it('is a problem once the oldest job has waited the configured minutes, measured at the snapshot', function (): void {
    expect(queueSeen(jobs: [[0, 4], [0, 1]])->hasProblem())->toBeFalse()
        ->and(queueSeen(jobs: [[0, 5], [0, 1]])->problemSummary())->toBe('Oldest waiting 5m')
        ->and(queueSeen(jobs: [[0, 5]])->problemRank())->toBe(2);

    config(['printers.problem.oldest_job_minutes' => 10]);
    expect(queueSeen(jobs: [[0, 5]])->hasProblem())->toBeFalse();
});

it('is a problem when more jobs are queued than the configured limit', function (): void {
    expect(queueSeen(jobsCount: 10)->hasProblem())->toBeFalse()
        ->and(queueSeen(jobsCount: 104)->problemSummary())->toBe('104 jobs queued');
});

it('describes today\'s Epson incident with every reason', function (): void {
    $retainedError = PrintJobStatusFlag::Error->value | PrintJobStatusFlag::Printing->value | PrintJobStatusFlag::Retained->value;
    $queue = queueSeen(status: PrinterStatusFlag::Error->value | PrinterStatusFlag::Offline->value, jobs: [[$retainedError, 40], [$retainedError, 30], [$retainedError, 20]]);

    expect($queue->problemSummary())->toBe('Error, Offline, 3 jobs in error, oldest waiting 40m');
});

it('is not a problem when idle, printing a fresh job, low on toner or saving power', function (int $status, array $jobs): void {
    $queue = queueSeen(status: $status, jobs: $jobs);

    expect($queue->hasProblem())->toBeFalse()
        ->and($queue->problemSummary())->toBeNull()
        ->and($queue->problemRank())->toBe(3);
})->with([
    'idle' => [0, []],
    'printing' => [PrinterStatusFlag::Printing->value, [[PrintJobStatusFlag::Printing->value, 1]]],
    'toner low' => [PrinterStatusFlag::TonerLow->value, []],
    'power save' => [PrinterStatusFlag::PowerSave->value, []],
    'paused with nothing waiting' => [PrinterStatusFlag::Paused->value, []],
]);

it('shows toner low as information, not a problem', function (): void {
    $flag = PrinterStatusFlag::TonerLow;

    expect($flag->isInfo())->toBeTrue()->and($flag->isProblem())->toBeFalse();
});

it('parses a snapshot and drops software printers whatever their case', function (): void {
    $snapshot = PrinterSnapshot::fromArray([
        'trigger' => 'tick',
        'collected_at' => '2026-10-07T09:00:00Z',
        'spooler_available' => true,
        'printers' => [
            ['name' => 'microsoft print to pdf', 'status' => 0, 'jobs_count' => 0, 'jobs' => []],
            ['name' => 'HP Color LaserJet M553', 'status' => 0, 'jobs_count' => 0, 'jobs' => []],
        ],
    ], config('printers.ignored_names'));

    expect($snapshot->printers->map(fn (PrinterQueue $queue): string => $queue->name)->all())->toBe(['HP Color LaserJet M553'])
        ->and($snapshot->isSpoolerAvailable)->toBeTrue()
        ->and($snapshot->collectedAt->toIso8601ZuluString())->toBe('2026-10-07T09:00:00Z');
});

it('round-trips a queue through its stored form', function (): void {
    $queue = queueSeen(status: 0x80, jobs: [[0x2, 3]]);

    expect(PrinterQueue::fromArray($queue->toArray(), $queue->collectedAt)->toArray())->toBe($queue->toArray());
});
