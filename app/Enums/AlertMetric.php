<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertMetric: string
{
    case Cpu = 'cpu';
    case Ram = 'ram';
    case Disk = 'disk';
    case Offline = 'offline';
    case AgentOutdated = 'agent_outdated';

    /** @return array<int, self> The metrics a user can build an alert rule around */
    public static function thresholdBased(): array
    {
        return array_values(array_filter(self::cases(), fn (self $metric): bool => $metric->isThresholdBased()));
    }

    public function label(): string
    {
        return match ($this) {
            self::Cpu => 'CPU Usage',
            self::Ram => 'RAM Usage',
            self::Disk => 'Disk Usage',
            self::Offline => 'Device Offline',
            self::AgentOutdated => 'Agent Outdated',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Cpu, self::Ram, self::Disk => '%',
            self::Offline => ' min',
            self::AgentOutdated => '',
        };
    }

    /**
     * Agent versions are not numbers, so that alert is raised by agent:check-version
     * from a built-in rule rather than by comparing a metric against a threshold.
     */
    public function isThresholdBased(): bool
    {
        return match ($this) {
            self::Cpu, self::Ram, self::Disk, self::Offline => true,
            self::AgentOutdated => false,
        };
    }
}
