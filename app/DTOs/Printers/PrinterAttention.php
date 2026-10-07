<?php

declare(strict_types=1);

namespace App\DTOs\Printers;

use App\Models\Device;
use Carbon\CarbonInterface;

/**
 * One printing problem on a plainly online PC, for the dashboard: a printer,
 * or the PC's print spooler when printerName is null. Lower rank is worse.
 */
final readonly class PrinterAttention
{
    public function __construct(
        public Device $device,
        public ?string $printerName,
        public string $problem,
        public CarbonInterface $since,
        public int $rank,
    ) {}

    public function key(): string
    {
        return $this->device->id.'-'.md5($this->printerName ?? 'spooler');
    }

    public function sinceForHumans(): string
    {
        return $this->since->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true);
    }
}
