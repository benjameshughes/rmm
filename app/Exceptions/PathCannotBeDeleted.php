<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Concerns\Httpable;
use App\Models\Device;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A file or folder the RMM will not delete or quarantine, or a PC it will not delete anything on.
 */
final class PathCannotBeDeleted extends RuntimeException implements HttpExceptionInterface
{
    use Httpable;

    private function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public static function blank(): self
    {
        return new self('Type the full path of a file or folder, such as C:\\Veeam Backup Cache.');
    }

    public static function notAbsolute(string $path): self
    {
        return new self("{$path} is not a full path. Start with the drive, such as C:\\.");
    }

    public static function unc(string $path): self
    {
        return new self("{$path} is a network or device path. Only paths on the PC's own drives can be deleted.");
    }

    public static function variable(string $path): self
    {
        return new self("{$path} contains a variable. Type the path exactly as it is on the PC.");
    }

    public static function wildcard(string $path): self
    {
        return new self("{$path} contains a wildcard. Delete one file or folder at a time.");
    }

    public static function invalidCharacters(string $path): self
    {
        return new self("{$path} contains characters Windows paths cannot have.");
    }

    public static function traversal(string $path): self
    {
        return new self("{$path} contains . or .. folders. Type the path without them.");
    }

    public static function shortName(string $path): self
    {
        return new self("{$path} uses a short 8.3 name such as PROGRA~1. Type the full folder names.");
    }

    public static function trailingDot(string $path): self
    {
        return new self("{$path} has a name ending in a dot or space, which Windows silently drops. Type the real name.");
    }

    public static function driveRoot(string $path): self
    {
        return new self("{$path} is a whole drive and can never be deleted.");
    }

    public static function protected(string $path): self
    {
        return new self("{$path} is protected: Windows, installed programs, user profile roots and the RMM's own folders are never deleted from the RMM.");
    }

    public static function monitorOnly(Device $device): self
    {
        return new self("{$device->hostname} is monitor only and never runs commands.", status: 403);
    }

    public static function notWindows(Device $device): self
    {
        return new self("{$device->hostname} is not a Windows PC. Paths are only deleted on Windows PCs.", status: 403);
    }
}
