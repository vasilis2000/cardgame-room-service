<?php

declare(strict_types=1);

namespace App\Exceptions;

class HttpException extends \Exception
{
    protected int $statusCode;

    public function __construct(string $message, int $statusCode = 500, \Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}