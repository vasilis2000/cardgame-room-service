<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Utilities\JwtHelper;
use App\Exceptions\AuthenticationException;

class AuthMiddleware
{
    public static function authenticate(): ?object
    {
          $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            throw new AuthenticationException('No token provided');
        }

        $token = $matches[1];
        $decoded = JwtHelper::validateToken($token);

        if (!$decoded) {
            throw new AuthenticationException('Invalid or expired token');
        }

        return $decoded;
    }
}