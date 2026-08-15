<?php
declare(strict_types=1);

namespace App\Utilities;

use App\Middleware\AuthMiddleware;
use App\Exceptions\AuthenticationException;

class AuthHelper
{
    public static function getAuthenticatedUser(): array
    {
        $decoded = AuthMiddleware::authenticate();
        if (!isset($decoded->user_id) || !isset($decoded->username)) {
            throw new AuthenticationException('Invalid token payload.');
        }
        $userId = $decoded->user_id;
        $username = $decoded->username;
        if (!$userId) {
            throw new AuthenticationException('Invalid token payload.');
        }
        return ['user_id' => $userId, 'username' => $username];
    }
}