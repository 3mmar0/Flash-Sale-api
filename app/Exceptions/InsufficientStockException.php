<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    public function __construct(string $message = 'Insufficient stock available')
    {
        parent::__construct($message, 422);
    }
}

