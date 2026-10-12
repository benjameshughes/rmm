<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ZebraTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The one Zebra label printer setup every PC gets: a ZDesigner queue's
 * printer settings (paper, darkness, speed, media) as saved by
 * `printui /Ss`, gzipped and base64 encoded. Captured from one PC's printer
 * and restored by install-zebra, which receives it only as a secret when the
 * agent fetches the command. Capturing again replaces it. It outlives the PC
 * it came from, which is why the hostname is kept too.
 */
final class ZebraTemplate extends Model
{
    /** @use HasFactory<ZebraTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'source_hostname',
        'printer_name',
        'driver_name',
        'settings',
        'captured_at',
    ];

    protected $hidden = [
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Whether a driver is Zebra's own ZDesigner one, the only kind a
     * template is captured from or restored onto.
     */
    public static function isZDesignerDriver(?string $driverName): bool
    {
        return $driverName !== null && Str::startsWith(Str::lower($driverName), Str::lower(config('zebra.driver.prefix')));
    }

    public static function current(): ?self
    {
        return self::query()->latest('captured_at')->latest('id')->first();
    }

    /**
     * e.g. "ZDesigner GK420d on LABEL-PC-1, captured 2 days ago".
     */
    public function summary(): string
    {
        return "{$this->printer_name} on {$this->source_hostname}, captured {$this->captured_at->diffForHumans()}";
    }
}
