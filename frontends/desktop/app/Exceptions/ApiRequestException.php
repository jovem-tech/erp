<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ApiRequestException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly ?array $details = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function details(): ?array
    {
        return $this->details;
    }
}
