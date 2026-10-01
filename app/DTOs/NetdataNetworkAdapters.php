<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Per-adapter network rows merged by adapter name from the separate `net.net`,
 * `net.errors`, `net.drops` and `net.speed` responses (each grouped by
 * instance and dimension), falling back to the machine-wide `system.net`
 * totals that agents older than 0.6.0 send.
 */
final class NetdataNetworkAdapters
{
    private NetdataV3Metrics $throughput;

    private NetdataV3Metrics $errors;

    private NetdataV3Metrics $drops;

    private NetdataV3Metrics $speed;

    private NetdataV3Metrics $totals;

    public function __construct(mixed $throughput, mixed $errors = [], mixed $drops = [], mixed $speed = [], mixed $totals = [])
    {
        $this->throughput = new NetdataV3Metrics($throughput);
        $this->errors = new NetdataV3Metrics($errors);
        $this->drops = new NetdataV3Metrics($drops);
        $this->speed = new NetdataV3Metrics($speed);
        $this->totals = new NetdataV3Metrics($totals);
    }

    /** @param array<string, mixed> $payload A raw agent metrics request */
    public static function fromPayload(array $payload): self
    {
        return new self(
            $payload['netdata_net_interfaces'] ?? [],
            $payload['netdata_net_errors'] ?? [],
            $payload['netdata_net_drops'] ?? [],
            $payload['netdata_net_speed'] ?? [],
            $payload['netdata_net'] ?? [],
        );
    }

    public function isReported(): bool
    {
        return $this->byAdapter($this->throughput, 'net')->isNotEmpty();
    }

    /**
     * Per-adapter rows when the agent reports them, otherwise one 'total' row.
     * An agent that reports adapters which are all ignored stores none.
     *
     * @param  array<int, string>  $ignoredAdapterPatterns
     * @return array<int, array<string, string|float|int|null>>
     */
    public function rows(array $ignoredAdapterPatterns = []): array
    {
        if ($this->isReported()) {
            return $this->parse($ignoredAdapterPatterns);
        }

        $totals = $this->totals->parseNetworkTotals();

        return $totals === null ? [] : [['interface' => 'total', ...$totals]];
    }

    /**
     * Netdata reports sent traffic as a negative number.
     *
     * @param  array<int, string>  $ignoredAdapterPatterns
     * @return array<int, array{interface: string, received_kbps: float|null, sent_kbps: float|null, errors_inbound: float|null, errors_outbound: float|null, drops_inbound: float|null, drops_outbound: float|null, link_speed_kbps: int|null}>
     */
    public function parse(array $ignoredAdapterPatterns = []): array
    {
        $errors = $this->byAdapter($this->errors, 'net_errors');
        $drops = $this->byAdapter($this->drops, 'net_drops');
        $speed = $this->byAdapter($this->speed, 'net_speed');

        return $this->byAdapter($this->throughput, 'net')
            ->reject(fn (Collection $dimensions, int|string $adapter): bool => Str::is($ignoredAdapterPatterns, (string) $adapter))
            ->map(fn (Collection $throughput, int|string $adapter): array => [
                'interface' => (string) $adapter,
                'received_kbps' => $this->absolute($throughput->get('received')),
                'sent_kbps' => $this->absolute($throughput->get('sent')),
                'errors_inbound' => $this->absolute($errors->get($adapter)?->get('inbound')),
                'errors_outbound' => $this->absolute($errors->get($adapter)?->get('outbound')),
                'drops_inbound' => $this->absolute($drops->get($adapter)?->get('inbound')),
                'drops_outbound' => $this->absolute($drops->get($adapter)?->get('outbound')),
                'link_speed_kbps' => $this->linkSpeed($speed->get($adapter)?->get('speed')),
            ])
            ->values()
            ->all();
    }

    /** @return Collection<string, Collection<string, float>> adapter => dimension => value */
    private function byAdapter(NetdataV3Metrics $response, string $prefix): Collection
    {
        return $response->groupedDimensions($prefix)
            ->groupBy('instance')
            ->map(fn (Collection $dimensions): Collection => $dimensions->pluck('value', 'dimension'));
    }

    private function absolute(?float $value): ?float
    {
        return $value === null ? null : round(abs($value), 2);
    }

    private function linkSpeed(?float $kbps): ?int
    {
        return $kbps === null || $kbps <= 0 ? null : (int) round($kbps);
    }
}
