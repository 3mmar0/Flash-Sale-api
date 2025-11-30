<?php

namespace App\Exceptions;

use Exception;

class InvalidHoldException extends Exception
{
    public function __construct(string $message = 'Invalid or expired hold')
    {
        parent::__construct($message, 422);
    }
}

