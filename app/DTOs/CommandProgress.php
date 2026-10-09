<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Number;

/**
 * How far a running command has got, from the rmm.progress/1 object its
 * script printed on a PROGRESS: line. Read leniently: a field that is
 * missing, the wrong type or out of range is simply left out, strings are
 * capped and unknown keys are dropped, so a sloppy script never breaks a page.
 */
final readonly class CommandProgress
{
    public function __construct(
        public string $schema,
        public ?float $percent = null,
        public ?int $done = null,
        public ?int $total = null,
        public ?string $unit = null,
        public ?int $bytesDone = null,
        public ?int $bytesTotal = null,
        public ?int $etaSeconds = null,
        public ?string $message = null,
        public ?string $current = null,
    ) {}

    /**
     * @param  array<string, mixed>  $progress
     */
    public static function fromArray(array $progress): self
    {
        $percent = self::number($progress['percent'] ?? null);

        return new self(
            schema: self::text($progress['schema'] ?? null, 50) ?? 'rmm.progress/1',
            percent: $percent === null ? null : min(100.0, $percent),
            done: self::count($progress['done'] ?? null),
            total: self::count($progress['total'] ?? null),
            unit: self::text($progress['unit'] ?? null, config('commands.progress.max_unit_length')),
            bytesDone: self::count($progress['bytes_done'] ?? null),
            bytesTotal: self::count($progress['bytes_total'] ?? null),
            etaSeconds: self::count($progress['eta_seconds'] ?? null),
            message: self::text($progress['message'] ?? null, config('commands.progress.max_message_length')),
            current: self::text($progress['current'] ?? null, config('commands.progress.max_current_length')),
        );
    }

    /**
     * The stored shape: the schema's own keys, nulls left out.
     *
     * @return array<string, string|int|float>
     */
    public function toArray(): array
    {
        return array_filter([
            'schema' => $this->schema,
            'percent' => $this->percent,
            'done' => $this->done,
            'total' => $this->total,
            'unit' => $this->unit,
            'bytes_done' => $this->bytesDone,
            'bytes_total' => $this->bytesTotal,
            'eta_seconds' => $this->etaSeconds,
            'message' => $this->message,
            'current' => $this->current,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * 0 to 100: what the script said, or worked out from its counts or bytes.
     * Null when nothing says how far along it is.
     */
    public function percent(): ?float
    {
        return $this->percent
            ?? self::ratio($this->done, $this->total)
            ?? self::ratio($this->bytesDone, $this->bytesTotal);
    }

    /**
     * "42%", rounded down so it never claims done before it is.
     */
    public function percentLabel(): ?string
    {
        $percent = $this->percent();

        return $percent === null ? null : ((int) floor($percent)).'%';
    }

    /**
     * "42% · 7,612 / 18,128 files · 1.2 / 3.1 GB · ~5 min left", with any part it has no figures for left out.
     */
    public function label(): string
    {
        return collect([
            $this->percentLabel(),
            $this->countLabel(),
            $this->bytesLabel(),
            $this->etaLabel(),
        ])->filter()->implode(' · ');
    }

    /**
     * The item being worked on, cut in the middle so both the drive and the file name stay visible.
     */
    public function currentForDisplay(): ?string
    {
        $limit = config('commands.progress.current_display_length');

        if ($this->current === null || mb_strlen($this->current) <= $limit) {
            return $this->current;
        }

        $kept = $limit - 1;
        $head = (int) ceil($kept / 2);

        return mb_substr($this->current, 0, $head).'…'.mb_substr($this->current, -($kept - $head));
    }

    private function countLabel(): ?string
    {
        if ($this->done === null) {
            return null;
        }

        $unit = $this->unit === null ? '' : " {$this->unit}";

        return $this->total === null
            ? number_format($this->done).$unit
            : number_format($this->done).' / '.number_format($this->total).$unit;
    }

    /**
     * Both sides in the total's unit: "1.2 / 3.1 GB".
     */
    private function bytesLabel(): ?string
    {
        if ($this->bytesDone === null) {
            return null;
        }

        if ($this->bytesTotal === null) {
            return Number::fileSize($this->bytesDone, precision: 1);
        }

        $units = collect(['B', 'KB', 'MB', 'GB', 'TB']);
        $power = (int) min($units->count() - 1, $this->bytesTotal < 1024 ? 0 : floor(log($this->bytesTotal, 1024)));
        $decimals = $power === 0 ? 0 : 1;
        $inUnit = fn (int $bytes): string => number_format($bytes / (1024 ** $power), $decimals);

        return $inUnit($this->bytesDone).' / '.$inUnit($this->bytesTotal).' '.$units->get($power);
    }

    private function etaLabel(): ?string
    {
        return match (true) {
            $this->etaSeconds === null => null,
            $this->etaSeconds < 60 => 'under a minute left',
            $this->etaSeconds < 3600 => '~'.(int) round($this->etaSeconds / 60).' min left',
            default => $this->hoursLeft(),
        };
    }

    private function hoursLeft(): string
    {
        $hours = intdiv((int) $this->etaSeconds, 3600);
        $minutes = (int) round(((int) $this->etaSeconds % 3600) / 60);

        return collect(["~{$hours} h", $minutes > 0 ? "{$minutes} min" : null])->filter()->implode(' ').' left';
    }

    private static function ratio(?int $done, ?int $total): ?float
    {
        return $done === null || ! $total ? null : min(100.0, $done / $total * 100);
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
    }

    private static function count(mixed $value): ?int
    {
        $number = self::number($value);

        return $number === null || $number > PHP_INT_MAX ? null : (int) $number;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $limit);
    }
}
