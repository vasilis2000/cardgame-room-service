<?php

class AuthMiddleware
{
    public static function authenticate()
    {
       $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
    
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            ResponseHelper::sendResponse(401, ['error' => 'No token provided']);
        }
     
        $token = $matches[1];
        $decoded = JwtHelper::validateToken($token);

        if (!$decoded) {
            ResponseHelper::sendResponse(401, ['error' => 'Invalid or expired token']);
        }

        return $decoded;
    }
}
