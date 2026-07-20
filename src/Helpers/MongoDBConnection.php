<?php

declare(strict_types=1);

namespace App\Helpers;

use MongoDB\Client;
use Exception;

class MongoDBConnection
{
    private static ?Client $client = null;

    public static function getClient(): Client
    {
        if (self::$client === null) {
            $connectionString = Config::getString('MONGO_URI');
            try {
                self::$client = new Client($connectionString);
            } catch (Exception $e) {
                error_log('MongoDB connection failed: ' . $e->getMessage());
                throw $e;
            }
        }
        return self::$client;
    }

    public static function getDatabase(): \MongoDB\Database
    {
        $dbName = Config::getString('MONGO_DB');
        return self::getClient()->selectDatabase($dbName);
    }
}