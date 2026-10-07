<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Printers\PrinterQueue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The latest snapshot of one print queue on a device, kept raw. problem_since
 * marks when the queue last turned into a problem while its PC was plainly
 * online, and is cleared once it is fine or the PC stops being plainly online.
 */
final class DevicePrinter extends Model
{
    /** @use HasFactory<\Database\Factories\DevicePrinterFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'name',
        'snapshot',
        'collected_at',
        'problem_since',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'collected_at' => 'datetime',
            'problem_since' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * The stored snapshot read back, built once per model.
     */
    public function queue(): PrinterQueue
    {
        return once(fn (): PrinterQueue => PrinterQueue::fromArray($this->snapshot, $this->collected_at));
    }

    /**
     * e.g. "Zebra GK420d - ZPL: 104 jobs queued, oldest waiting 2h".
     */
    public function problemLabel(): string
    {
        return "{$this->name}: {$this->queue()->problemSummary()}";
    }
}
