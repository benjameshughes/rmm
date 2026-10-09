<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertMetric: string
{
    case Cpu = 'cpu';
    case Ram = 'ram';
    case Disk = 'disk';
    case CpuQueue = 'cpu_queue';
    case DiskBusy = 'disk_busy';
    case PageFile = 'page_file';
    case Offline = 'offline';
    case AgentOutdated = 'agent_outdated';
    case ScriptFailed = 'script_failed';
    case VirtualPrinterDown = 'virtual_printer_down';
    case PrinterProblem = 'printer_problem';
    case SpoolerDown = 'spooler_down';
    case BackupOverdue = 'backup_overdue';
    case NetdataRepairFailed = 'netdata_repair_failed';
    case ServerBackupProblem = 'server_backup_problem';

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
            self::CpuQueue => 'CPU Queue',
            self::DiskBusy => 'Disk Busy',
            self::PageFile => 'Page File Usage',
            self::Offline => 'Device Offline',
            self::AgentOutdated => 'Agent Outdated',
            self::ScriptFailed => 'Scheduled Script Failed',
            self::VirtualPrinterDown => 'Virtual Printer Down',
            self::PrinterProblem => 'Printer Problem',
            self::SpoolerDown => 'Print Spooler Down',
            self::BackupOverdue => 'Backup Overdue or Failed',
            self::NetdataRepairFailed => 'Netdata Repair Failed',
            self::ServerBackupProblem => 'Server Backup Overdue, Failed or Shrunk',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Cpu, self::Ram, self::Disk, self::DiskBusy, self::PageFile => '%',
            self::CpuQueue => ' threads',
            self::Offline => ' min',
            self::AgentOutdated, self::ScriptFailed, self::VirtualPrinterDown, self::PrinterProblem, self::SpoolerDown, self::BackupOverdue, self::NetdataRepairFailed, self::ServerBackupProblem => '',
        };
    }

    /**
     * Agent versions are not numbers, so that alert is raised by agent:check-version
     * from a built-in rule rather than by comparing a metric against a threshold.
     * Scheduled script failures likewise come from a built-in rule, raised when a
     * scheduled command finishes, and a print station missing its Virtual
     * Printer from one raised as metrics reports arrive. Printer problems and
     * a stopped print spooler come from built-in rules raised as printer
     * reports arrive, and overdue or failed backups from one checked hourly
     * and after every backup run. A failed Netdata repair comes from one
     * raised when the repair script finishes, and a server backup that is
     * overdue, failed or shrunk from one raised as reports arrive and hourly.
     */
    public function isThresholdBased(): bool
    {
        return match ($this) {
            self::Cpu, self::Ram, self::Disk, self::CpuQueue, self::DiskBusy, self::PageFile, self::Offline => true,
            self::AgentOutdated, self::ScriptFailed, self::VirtualPrinterDown, self::PrinterProblem, self::SpoolerDown, self::BackupOverdue, self::NetdataRepairFailed, self::ServerBackupProblem => false,
        };
    }
}
