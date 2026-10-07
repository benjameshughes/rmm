<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\DeviceStatus;
use App\Enums\VirtualPrinterState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Linnworks' Virtual Printer runs per user, so a label PC sitting at the
 * login screen cannot print. Every metrics report stamps when the app was
 * last seen; while a print station is plainly online without it, the time
 * it went missing is kept. That stamp is cleared whenever the PC stops
 * being plainly online, so only time spent online counts as down.
 */
trait WatchesVirtualPrinter
{
    public function virtualPrinterState(): VirtualPrinterState
    {
        return match (true) {
            ! $this->isPrintStation, ! $this->isPlainlyOnline => VirtualPrinterState::Unwatched,
            $this->hasVirtualPrinterBeenMissingFor(minutes: config('devices.watched_apps.virtual_printer.down_after_minutes')) => VirtualPrinterState::Down,
            default => VirtualPrinterState::Ready,
        };
    }

    /**
     * "Ready", or how long it has been down, e.g. "Down for 14h".
     */
    public function virtualPrinterLabel(): string
    {
        $state = $this->virtualPrinterState();

        return $state === VirtualPrinterState::Down ? "Down for {$this->virtualPrinterDownForHumans()}" : $state->label();
    }

    /**
     * Whether the app has been missing, while online, for at least the given minutes.
     */
    public function hasVirtualPrinterBeenMissingFor(int $minutes): bool
    {
        return $this->virtual_printer_missing_since?->lessThanOrEqualTo(now()->subMinutes($minutes)) ?? false;
    }

    /**
     * How long the app has been missing while online, e.g. "14h".
     */
    public function virtualPrinterDownForHumans(): ?string
    {
        return $this->virtual_printer_missing_since?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true);
    }

    /**
     * What one metrics report changes: seeing the app clears any missing stamp;
     * a print station reporting without it starts the clock once. A PC still
     * powering on is not plainly online, so its reports start no clock.
     *
     * @return array{virtual_printer_seen_at?: CarbonInterface, virtual_printer_missing_since?: CarbonInterface|null}
     */
    public function virtualPrinterReportAttributes(bool $isRunning): array
    {
        return match (true) {
            $isRunning => ['virtual_printer_seen_at' => now(), 'virtual_printer_missing_since' => null],
            $this->isPrintStation && ! $this->isPoweringOn && $this->virtual_printer_missing_since === null => ['virtual_printer_missing_since' => now()],
            default => [],
        };
    }

    protected function isVirtualPrinterDown(): Attribute
    {
        return Attribute::get(fn (): bool => $this->virtualPrinterState() === VirtualPrinterState::Down);
    }

    protected function isPrintStation(): Attribute
    {
        return Attribute::get(fn (): bool => $this->virtual_printer_seen_at?->greaterThan(self::printStationCutoff()) ?? false);
    }

    /**
     * Approved, checking in and not powering off or on: what the status badge shows as plain Online.
     */
    protected function isPlainlyOnline(): Attribute
    {
        return Attribute::get(fn (): bool => $this->status === DeviceStatus::Active && $this->isOnline && ! $this->isPoweringOn);
    }

    private static function printStationCutoff(): CarbonInterface
    {
        return now()->subDays(config('devices.watched_apps.virtual_printer.station_lookback_days'));
    }
}
