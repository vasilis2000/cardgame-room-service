<?php

declare(strict_types=1);

namespace App\Exceptions;

class ValidationException extends HttpException
{
    public function __construct(string $message = 'Validation error')
    {
        parent::__construct($message, 422);
    }
}