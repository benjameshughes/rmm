<?php

declare(strict_types=1);

namespace App\DTOs\Printers;

use App\Enums\PrinterStatusFlag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One Windows print queue and its jobs at the moment the agent took the
 * snapshot. Raw facts in, interpretation out: whether it is a problem and why.
 * Job ages are measured against collectedAt, the agent's own clock, so a
 * skewed PC clock does not age its jobs.
 */
final readonly class PrinterQueue
{
    /**
     * @param  Collection<int, PrintJob>  $jobs
     */
    public function __construct(
        public string $name,
        public ?string $portName,
        public ?string $driverName,
        public int $status,
        public int $attributes,
        public int $jobsCount,
        public Collection $jobs,
        public Carbon $collectedAt,
    ) {}

    /**
     * @param  array{name: string, port_name?: ?string, driver_name?: ?string, status?: ?int, attributes?: ?int, jobs_count?: ?int, jobs?: array<int, array<string, mixed>>}  $printer
     */
    public static function fromArray(array $printer, Carbon $collectedAt): self
    {
        $jobs = collect($printer['jobs'] ?? [])->map(PrintJob::fromArray(...))->values();

        return new self(
            name: $printer['name'],
            portName: $printer['port_name'] ?? null,
            driverName: $printer['driver_name'] ?? null,
            status: (int) ($printer['status'] ?? 0),
            attributes: (int) ($printer['attributes'] ?? 0),
            jobsCount: max((int) ($printer['jobs_count'] ?? 0), $jobs->count()),
            jobs: $jobs,
            collectedAt: $collectedAt,
        );
    }

    /**
     * @return array{name: string, port_name: ?string, driver_name: ?string, status: int, attributes: int, jobs_count: int, jobs: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'port_name' => $this->portName,
            'driver_name' => $this->driverName,
            'status' => $this->status,
            'attributes' => $this->attributes,
            'jobs_count' => $this->jobsCount,
            'jobs' => $this->jobs->map(fn (PrintJob $job): array => $job->toArray())->all(),
        ];
    }

    /**
     * The port and driver, e.g. "USB001 · ZDesigner GK420d".
     */
    public function connectionForHumans(): string
    {
        return collect([$this->portName, $this->driverName])->filter()->implode(' · ');
    }

    /**
     * Jobs the queue holds beyond the first $shown, for "and 52 more".
     */
    public function jobsBeyond(int $shown): int
    {
        return max(0, $this->jobsCount - min($shown, $this->jobs->count()));
    }

    /**
     * @param  array<int, string>  $patterns  Str::is wildcards, matched in any case
     */
    public function isIgnored(array $patterns): bool
    {
        return collect($patterns)->contains(fn (string $pattern): bool => Str::is($pattern, $this->name, ignoreCase: true));
    }

    /** @return Collection<int, PrinterStatusFlag> */
    public function statusFlags(): Collection
    {
        return PrinterStatusFlag::fromBits($this->status);
    }

    /** @return Collection<int, PrinterStatusFlag> */
    public function problemFlags(): Collection
    {
        return $this->statusFlags()->filter(fn (PrinterStatusFlag $flag): bool => $flag->isProblem())->values();
    }

    /** @return Collection<int, PrintJob> */
    public function problemJobs(): Collection
    {
        return $this->jobs->filter(fn (PrintJob $job): bool => $job->hasProblem())->values();
    }

    public function oldestJob(): ?PrintJob
    {
        return $this->jobs
            ->reject(fn (PrintJob $job): bool => $job->submitted === null)
            ->sortBy(fn (PrintJob $job): int => $job->submitted->getTimestamp())
            ->first();
    }

    public function isStuck(): bool
    {
        return ($this->oldestJob()?->minutesWaitedAt($this->collectedAt) ?? 0) >= config('printers.problem.oldest_job_minutes');
    }

    public function isFlooded(): bool
    {
        return $this->jobsCount > config('printers.problem.max_jobs');
    }

    /**
     * Why the queue is a problem, in words, worst first; empty when it is fine.
     *
     * @return Collection<int, string>
     */
    public function problems(): Collection
    {
        $problemJobCount = $this->problemJobs()->count();

        return $this->problemFlags()
            ->map(fn (PrinterStatusFlag $flag): string => $flag->label())
            ->when($problemJobCount > 0, fn (Collection $problems): Collection => $problems->push($problemJobCount.' '.Str::plural('job', $problemJobCount).' in error'))
            ->when($this->isFlooded(), fn (Collection $problems): Collection => $problems->push("{$this->jobsCount} jobs queued"))
            ->when($this->isStuck(), fn (Collection $problems): Collection => $problems->push('oldest waiting '.$this->oldestJob()->ageForHumans($this->collectedAt)))
            ->values();
    }

    public function hasProblem(): bool
    {
        return $this->problems()->isNotEmpty();
    }

    /**
     * e.g. "Error, Offline, 3 jobs in error", or null when the queue is fine.
     */
    public function problemSummary(): ?string
    {
        return $this->hasProblem() ? Str::ucfirst($this->problems()->implode(', ')) : null;
    }

    /**
     * Lower is worse: a printer Windows flags as broken, then failing jobs, then a backed-up queue.
     */
    public function problemRank(): int
    {
        return match (true) {
            $this->problemFlags()->isNotEmpty() => 0,
            $this->problemJobs()->isNotEmpty() => 1,
            $this->hasProblem() => 2,
            default => 3,
        };
    }
}
