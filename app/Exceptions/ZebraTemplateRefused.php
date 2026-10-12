<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Concerns\Httpable;
use App\Models\DevicePrinter;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A printer that cannot become the Zebra template.
 */
final class ZebraTemplateRefused extends RuntimeException implements HttpExceptionInterface
{
    use Httpable;

    private int $status = 422;

    public static function notZDesigner(DevicePrinter $printer): self
    {
        $driver = $printer->queue()->driverName ?? 'an unknown driver';

        return new self("{$printer->name} uses {$driver}. Only a printer on the ZDesigner driver can be the Zebra template, since its settings only restore onto that driver. Install Zebra on this PC first.");
    }
}
