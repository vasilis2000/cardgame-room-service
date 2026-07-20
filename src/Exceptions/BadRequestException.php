<?php

declare(strict_types=1);

namespace App\Exceptions;

class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Bad request')
    {
        parent::__construct($message, 400);
    }
}