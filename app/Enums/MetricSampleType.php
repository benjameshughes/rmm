<?php

declare(strict_types=1);

namespace App\Enums;

use App\DTOs\NetdataSystemMetrics;
use App\DTOs\NetdataV3Metrics;
use Illuminate\Support\Collection;

/**
 * The per-second series kept from each Netdata window, so a short spike
 * survives that a report's average would smooth away.
 */
enum MetricSampleType: string
{
    case Cpu = 'cpu';
    case Ram = 'ram';
    case Load = 'load';
    case Swap = 'swap';
    case NetworkIn = 'network_in';
    case NetworkOut = 'network_out';

    /**
     * The raw agent request key holding the Netdata context this series is read from.
     */
    public function payloadKey(): string
    {
        return match ($this) {
            self::Cpu => 'netdata_cpu',
            self::Ram => 'netdata_ram',
            self::Load => 'netdata_load',
            self::Swap => 'netdata_swap',
            self::NetworkIn, self::NetworkOut => 'netdata_net',
        };
    }

    /**
     * Values keyed by unix time, newest first. Load is the one-minute average;
     * network is machine-wide throughput from `system.net`.
     *
     * @return Collection<int, float>
     */
    public function series(NetdataV3Metrics $response): Collection
    {
        return match ($this) {
            self::Cpu => (new NetdataSystemMetrics($response))->cpuUsageSeries(),
            self::Ram => (new NetdataSystemMetrics($response))->ramUsageSeries(),
            self::Load => $response->dimensionSeries('load1'),
            self::Swap => (new NetdataSystemMetrics($response))->swapUsageSeries(),
            self::NetworkIn => $response->dimensionSeries('received'),
            self::NetworkOut => $response->dimensionSeries('sent'),
        };
    }
}
