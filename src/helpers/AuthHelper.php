<?php
require_once __DIR__ . '/../services/AuthMiddleware.php';

class AuthHelper
{
    public static function getAuthenticatedUser(): array
    {
        $decoded = AuthMiddleware::authenticate();
      
        if (!isset($decoded->user_id) || !isset($decoded->username)) {
            ResponseHelper::sendResponse(401, ['error' => 'Invalid token payload.']);
        }
        $userId = $decoded->user_id;
        $username = $decoded->username;
        if (!$userId) {
            ResponseHelper::sendResponse(401, ['error' => 'Invalid token payload.']);
        }
        return ['user_id' => $userId, 'username' => $username];
    }
}
