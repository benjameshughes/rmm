<?php

declare(strict_types=1);

namespace App\DTOs\Printers;

use App\Enums\PrintJobStatusFlag;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One job in a Windows print queue, as the agent read it from the spooler.
 */
final readonly class PrintJob
{
    public function __construct(
        public int $id,
        public ?string $document,
        public ?string $userName,
        public int $status,
        public ?string $statusText,
        public ?Carbon $submitted,
        public ?int $totalPages,
        public ?int $pagesPrinted,
        public ?int $size,
        public ?int $position,
        public ?int $priority,
    ) {}

    /**
     * @param  array{id: int, document?: ?string, user_name?: ?string, status?: ?int, status_text?: ?string, submitted?: ?string, total_pages?: ?int, pages_printed?: ?int, size?: ?int, position?: ?int, priority?: ?int}  $job
     */
    public static function fromArray(array $job): self
    {
        return new self(
            id: (int) $job['id'],
            document: $job['document'] ?? null,
            userName: $job['user_name'] ?? null,
            status: (int) ($job['status'] ?? 0),
            statusText: $job['status_text'] ?? null,
            submitted: isset($job['submitted']) ? Carbon::parse($job['submitted'])->utc() : null,
            totalPages: $job['total_pages'] ?? null,
            pagesPrinted: $job['pages_printed'] ?? null,
            size: $job['size'] ?? null,
            position: $job['position'] ?? null,
            priority: $job['priority'] ?? null,
        );
    }

    /**
     * @return array{id: int, document: ?string, user_name: ?string, status: int, status_text: ?string, submitted: ?string, total_pages: ?int, pages_printed: ?int, size: ?int, position: ?int, priority: ?int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'document' => $this->document,
            'user_name' => $this->userName,
            'status' => $this->status,
            'status_text' => $this->statusText,
            'submitted' => $this->submitted?->toIso8601ZuluString(),
            'total_pages' => $this->totalPages,
            'pages_printed' => $this->pagesPrinted,
            'size' => $this->size,
            'position' => $this->position,
            'priority' => $this->priority,
        ];
    }

    /** @return Collection<int, PrintJobStatusFlag> */
    public function statusFlags(): Collection
    {
        return PrintJobStatusFlag::fromBits($this->status);
    }

    public function hasProblem(): bool
    {
        return $this->statusFlags()->contains(fn (PrintJobStatusFlag $flag): bool => $flag->isProblem());
    }

    /**
     * Whole minutes the job had waited when the snapshot was taken, or null without a submitted time.
     */
    public function minutesWaitedAt(CarbonInterface $collectedAt): ?int
    {
        return $this->submitted === null ? null : (int) max(0, $this->submitted->diffInMinutes($collectedAt));
    }

    /**
     * How long it had waited when the snapshot was taken, e.g. "12m".
     */
    public function ageForHumans(CarbonInterface $collectedAt): ?string
    {
        return $this->submitted?->diffForHumans($collectedAt, syntax: CarbonInterface::DIFF_ABSOLUTE, short: true);
    }

    public function submittedForHumans(): ?string
    {
        return $this->submitted?->copy()->inDisplayTimezone()->format('j M, H:i');
    }

    /**
     * "1 of 3", or just the total when nothing has printed yet; drivers often report no pages at all.
     */
    public function pagesForHumans(): string
    {
        return match (true) {
            ! $this->totalPages => '—',
            ! $this->pagesPrinted => (string) $this->totalPages,
            default => "{$this->pagesPrinted} of {$this->totalPages}",
        };
    }
}
