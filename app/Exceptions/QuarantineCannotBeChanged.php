<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Concerns\Httpable;
use App\Models\DeviceQuarantine;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A quarantined file or folder that can no longer be purged or restored.
 */
final class QuarantineCannotBeChanged extends RuntimeException implements HttpExceptionInterface
{
    use Httpable;

    private int $status = 422;

    public static function released(DeviceQuarantine $quarantine): self
    {
        return new self("{$quarantine->path} has already been purged or restored.");
    }
}
