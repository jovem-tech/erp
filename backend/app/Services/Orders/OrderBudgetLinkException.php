<?php

namespace App\Services\Orders;

use RuntimeException;

final class OrderBudgetLinkException extends RuntimeException
{
    public function __construct(
        private readonly string $resultCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function resultCode(): string
    {
        return $this->resultCode;
    }
}
