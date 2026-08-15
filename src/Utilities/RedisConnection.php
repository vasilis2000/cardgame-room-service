<?php

declare(strict_types=1);

namespace App\Utilities;

use Predis\Client;

class RedisConnection
{
    private static ?Client $instance = null;

    public static function getClient(): Client
    {
        if (self::$instance === null) {
            $redisUrl = Config::getString('REDIS_URL');
            self::$instance = new Client($redisUrl);
        }
        return self::$instance;
    }
}