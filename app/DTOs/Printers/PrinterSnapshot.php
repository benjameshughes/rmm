<?php

declare(strict_types=1);

namespace App\DTOs\Printers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every print queue on a Windows PC as its agent saw them, sent on each
 * spooler change and once a minute. Software printers are already dropped.
 */
final readonly class PrinterSnapshot
{
    /**
     * @param  Collection<int, PrinterQueue>  $printers
     */
    public function __construct(
        public string $trigger,
        public Carbon $collectedAt,
        public bool $isSpoolerAvailable,
        public Collection $printers,
    ) {}

    /**
     * @param  array{trigger: string, collected_at: string, spooler_available: bool, printers?: array<int, array<string, mixed>>}  $payload  Validated by PrintersRequest
     * @param  array<int, string>  $ignoredNames  Str::is patterns for software printers
     */
    public static function fromArray(array $payload, array $ignoredNames): self
    {
        $collectedAt = Carbon::parse($payload['collected_at'])->utc();

        return new self(
            trigger: $payload['trigger'],
            collectedAt: $collectedAt,
            isSpoolerAvailable: (bool) $payload['spooler_available'],
            printers: collect($payload['printers'] ?? [])
                ->map(fn (array $printer): PrinterQueue => PrinterQueue::fromArray($printer, $collectedAt))
                ->reject(fn (PrinterQueue $queue): bool => $queue->isIgnored($ignoredNames))
                ->values(),
        );
    }
}
