<?php

declare(strict_types=1);

namespace App\Enums;

enum ScriptType: string
{
    case Powershell = 'powershell';
    case Bash = 'bash';
    case Cmd = 'cmd';
    case Sh = 'sh';
}
