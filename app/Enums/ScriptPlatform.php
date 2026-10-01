<?php

declare(strict_types=1);

namespace App\Enums;

enum ScriptPlatform: string
{
    case Windows = 'windows';
    case Linux = 'linux';
    case MacOs = 'macos';
    case All = 'all';
}
