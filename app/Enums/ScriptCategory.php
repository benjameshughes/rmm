<?php

declare(strict_types=1);

namespace App\Enums;

enum ScriptCategory: string
{
    case Power = 'power';
    case Network = 'network';
    case Maintenance = 'maintenance';
    case Security = 'security';
    case Info = 'info';
    case Services = 'services';
    case Processes = 'processes';
    case Updates = 'updates';
    case User = 'user';
}
