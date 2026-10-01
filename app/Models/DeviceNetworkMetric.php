<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceNetworkMetric extends Model
{
    protected $fillable = [
        'device_metric_id',
        'interface',
        'received_kbps',
        'sent_kbps',
        'received_bytes',
        'sent_bytes',
        'errors_inbound',
        'errors_outbound',
        'drops_inbound',
        'drops_outbound',
        'link_speed_kbps',
    ];

    protected function casts(): array
    {
        return [
            'received_kbps' => 'float',
            'sent_kbps' => 'float',
            'errors_inbound' => 'float',
            'errors_outbound' => 'float',
            'drops_inbound' => 'float',
            'drops_outbound' => 'float',
            'link_speed_kbps' => 'integer',
        ];
    }

    public function deviceMetric(): BelongsTo
    {
        return $this->belongsTo(DeviceMetric::class);
    }

    public function linkSpeedForHumans(): ?string
    {
        return $this->link_speed_kbps === null ? null : $this->bitRateForHumans($this->link_speed_kbps);
    }

    public function receivedForHumans(): ?string
    {
        return $this->received_kbps === null ? null : $this->bitRateForHumans($this->received_kbps);
    }

    public function sentForHumans(): ?string
    {
        return $this->sent_kbps === null ? null : $this->bitRateForHumans($this->sent_kbps);
    }

    /** Per-second rates as "inbound / outbound". */
    public function errorsForHumans(): ?string
    {
        return $this->ratePairForHumans($this->errors_inbound, $this->errors_outbound);
    }

    public function dropsForHumans(): ?string
    {
        return $this->ratePairForHumans($this->drops_inbound, $this->drops_outbound);
    }

    public function errorsColor(): string
    {
        return $this->hasErrors ? 'red' : 'zinc';
    }

    public function dropsColor(): string
    {
        return $this->hasDrops ? 'amber' : 'zinc';
    }

    protected function hasErrors(): Attribute
    {
        return Attribute::get(fn (): bool => ($this->errors_inbound ?? 0) + ($this->errors_outbound ?? 0) > 0);
    }

    protected function hasDrops(): Attribute
    {
        return Attribute::get(fn (): bool => ($this->drops_inbound ?? 0) + ($this->drops_outbound ?? 0) > 0);
    }

    private function bitRateForHumans(float|int $kbps): string
    {
        return match (true) {
            $kbps >= 1000 ** 2 => $this->trimmedNumber($kbps / 1000 ** 2, 1).' Gbps',
            $kbps >= 1000 => $this->trimmedNumber($kbps / 1000, 1).' Mbps',
            default => $this->trimmedNumber($kbps, 1).' kbps',
        };
    }

    private function ratePairForHumans(?float $inbound, ?float $outbound): ?string
    {
        if ($inbound === null && $outbound === null) {
            return null;
        }

        return $this->trimmedNumber($inbound ?? 0, 2).' / '.$this->trimmedNumber($outbound ?? 0, 2);
    }

    /** 1.0 reads as "1", 2.50 as "2.5". */
    private function trimmedNumber(float|int $value, int $decimals): string
    {
        return rtrim(rtrim(number_format($value, $decimals), '0'), '.');
    }
}
