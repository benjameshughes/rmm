<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Concerns\Httpable;
use App\Models\Script;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A script the generic Run Script pickers and schedules will not queue.
 */
final class ScriptCannotBeRunDirectly extends RuntimeException implements HttpExceptionInterface
{
    use Httpable;

    private function __construct(string $message, private int $status = 403)
    {
        parent::__construct($message);
    }

    public static function internal(Script $script): self
    {
        return new self("{$script->name} is queued by its own feature, with its own checks, and cannot be run directly.");
    }
}
