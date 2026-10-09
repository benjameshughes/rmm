<?php

declare(strict_types=1);

namespace App\Exceptions\Concerns;

/**
 * Lets a domain exception implement HttpExceptionInterface, so it renders as
 * a proper HTTP response from a request or Livewire call and is reported as
 * normal from the CLI or a queue. The exception sets $status.
 */
trait Httpable
{
    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
