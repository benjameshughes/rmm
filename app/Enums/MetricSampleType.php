<?php

declare(strict_types=1);

namespace App\Enums;

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

    public function label(): string
    {
        return match ($this) {
            self::Cpu => 'CPU Usage',
            self::Ram => 'RAM Usage',
            self::Load => 'Load Average',
            self::Swap => 'Swap Usage',
            self::NetworkIn => 'Network In',
            self::NetworkOut => 'Network Out',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Cpu, self::Ram, self::Swap => '%',
            self::Load => '',
            self::NetworkIn, self::NetworkOut => ' kbps',
        };
    }

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
            self::Cpu => $response->cpuUsageSeries(),
            self::Ram => $response->ramUsageSeries(),
            self::Load => $response->dimensionSeries('load1'),
            self::Swap => $response->swapUsageSeries(),
            self::NetworkIn => $response->dimensionSeries('received'),
            self::NetworkOut => $response->dimensionSeries('sent'),
        };
    }
}
