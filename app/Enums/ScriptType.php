<?php

declare(strict_types=1);

namespace App\Enums;

enum ScriptType: string
{
    case Powershell = 'powershell';
    case Bash = 'bash';
    case Cmd = 'cmd';
    case Sh = 'sh';

    public function label(): string
    {
        return match ($this) {
            self::Powershell => 'PowerShell',
            self::Bash => 'Bash',
            self::Cmd => 'Command Prompt',
            self::Sh => 'sh',
        };
    }
}
