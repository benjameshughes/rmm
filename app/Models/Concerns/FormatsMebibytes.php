<?php

declare(strict_types=1);

namespace App\Models\Concerns;

trait FormatsMebibytes
{
    protected function mebibytesForHumans(float $mib): string
    {
        return $mib >= 1024
            ? number_format($mib / 1024, 1).' GB'
            : number_format($mib, 1).' MB';
    }
}
