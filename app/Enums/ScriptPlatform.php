<?php

declare(strict_types=1);

namespace App\Enums;

enum ScriptPlatform: string
{
    case Windows = 'windows';
    case Linux = 'linux';
    case MacOs = 'macos';
    case All = 'all';

    /**
     * Shells offered for a command typed on a device's page, the first being the default.
     *
     * @return array<int, ScriptType>
     */
    public function adHocScriptTypes(): array
    {
        return match ($this) {
            self::Windows => [ScriptType::Powershell, ScriptType::Cmd],
            self::Linux, self::MacOs => [ScriptType::Bash],
            self::All => ScriptType::cases(),
        };
    }

    /**
     * What the OS calls memory paged out to disk.
     */
    public function swapLabel(): string
    {
        return match ($this) {
            self::Windows => 'Page File',
            default => 'Swap',
        };
    }

    /**
     * What to show when no swap figure arrived: Linux agents leave swap out when the box has none.
     */
    public function missingSwapLabel(): string
    {
        return match ($this) {
            self::Linux => 'No swap',
            default => '—',
        };
    }

    /**
     * Linux agents send adapter errors and drops as counters since boot; Windows sends per-second rates.
     */
    public function hasCumulativeNetworkCounters(): bool
    {
        return $this === self::Linux;
    }
}
