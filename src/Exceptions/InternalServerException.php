<?php

declare(strict_types=1);

namespace App\Exceptions;

class InternalServerException extends HttpException
{
    public function __construct(string $message = 'Internal server error')
    {
        parent::__construct($message, 500);
    }
}