<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\DevicePrinter;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Windows agents post every print queue on the PC whenever the spooler
 * changes and once a minute. Problems only count while the PC is plainly
 * online and its printers were reported recently: an offline PC already
 * shows as such, and a reporter that stopped must not leave an old
 * problem on show.
 */
trait WatchesPrinters
{
    public function printers(): HasMany
    {
        return $this->hasMany(DevicePrinter::class)->orderBy('name');
    }

    public function problemPrinters(): HasMany
    {
        return $this->hasMany(DevicePrinter::class)->whereNotNull('problem_since');
    }

    /**
     * The printers that are a problem right now, worst first; none when the PC is not being watched.
     *
     * @return Collection<int, DevicePrinter>
     */
    public function currentPrinterProblems(): Collection
    {
        return $this->isWatchingPrinters
            ? $this->problemPrinters
                ->sortBy(fn (DevicePrinter $printer): array => [$printer->queue()->problemRank(), -$printer->queue()->jobsCount, $printer->name])
                ->values()
            : collect();
    }

    /**
     * The worst printer problem in words, e.g. "Zebra GK420d - ZPL: 104 jobs queued (+1 more)", or null when printing is fine.
     */
    public function printerProblemLabel(): ?string
    {
        $problems = $this->currentPrinterProblems();
        $more = $problems->count() > 1 ? ' (+'.($problems->count() - 1).' more)' : '';

        return match (true) {
            $this->isSpoolerDown => 'Print spooler not running',
            $problems->isNotEmpty() => $problems->first()->problemLabel().$more,
            default => null,
        };
    }

    public function printersReportedForHumans(): ?string
    {
        return $this->printers_reported_at === null ? null : 'Reported '.$this->printers_reported_at->diffForHumans().'.';
    }

    /**
     * Plainly online with a recent printer report, so its printers are judged.
     */
    protected function isWatchingPrinters(): Attribute
    {
        return Attribute::get(fn (): bool => $this->isPlainlyOnline
            && ($this->printers_reported_at?->greaterThan(now()->subMinutes(config('printers.stale_after_minutes'))) ?? false));
    }

    protected function isSpoolerDown(): Attribute
    {
        return Attribute::get(fn (): bool => $this->isWatchingPrinters && $this->spooler_down_since !== null);
    }
}
