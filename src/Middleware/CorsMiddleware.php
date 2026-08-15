<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Utilities\Config;

class CorsMiddleware
{
    public function handle(Request $request): void
    {
        $allowedOrigins = Config::getArray('ALLOWED_ORIGINS', ',', ['http://localhost']);
        $origin = $request->getHeader('origin') ?? '';
        if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
            http_response_code(403);
            echo json_encode(['message' => 'Origin not allowed.']);
            exit;
        }

        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        } else {
            header('Access-Control-Allow-Origin: *');
        }

        if ($request->isMethod('OPTIONS')) {
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Max-Age: 86400');
            http_response_code(204);
            exit;
        }
    }
}