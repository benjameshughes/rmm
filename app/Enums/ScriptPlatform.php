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
}
