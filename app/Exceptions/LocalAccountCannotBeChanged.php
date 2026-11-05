<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Concerns\Httpable;
use App\Models\DeviceLocalAccount;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A local Windows account the RMM will not adopt, disable or delete from a PC's Users tab.
 */
final class LocalAccountCannotBeChanged extends RuntimeException implements HttpExceptionInterface
{
    use Httpable;

    private function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public static function builtIn(DeviceLocalAccount $account): self
    {
        return new self("{$account->username} is a built-in Windows account and is never changed by the RMM.");
    }

    public static function managed(DeviceLocalAccount $account): self
    {
        return new self("{$account->username} is already a staff account. Change it on the Staff page.");
    }

    public static function signedIn(DeviceLocalAccount $account): self
    {
        return new self("{$account->username} is signed in, or the PC could not say, so it is left in place.");
    }
}
