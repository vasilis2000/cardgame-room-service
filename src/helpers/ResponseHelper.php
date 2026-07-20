<?php

declare(strict_types=1);

namespace App\Helpers;

class ResponseHelper
{
    public static function sendResponse(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
