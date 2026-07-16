<?php

declare(strict_types=1);

class Config
{
    private static ?array $values = null;
    private static bool $loaded = false;

    private const REQUIRED_KEYS = [
        'MONGO_URI',
        'MONGO_DB',
        'RABBITMQ_HOST',
        'RABBITMQ_PORT',
        'JWT_SECRET',
        'RABBITMQ_USER',
        'RABBITMQ_PASS',
        'REDIS_URL',
    ];


    private const DEFAULTS = [
        'JWT_EXPIRY' => 3600,
    ];


    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }


        if (!self::isEnvLoaded()) {
            if (class_exists(\Dotenv\Dotenv::class)) {
                $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
                $dotenv->load();
            } else {

                error_log('Warning: vlucas/phpdotenv not found. Relying on existing environment variables.');
            }
        }

        $values = [];
        $missing = [];

        foreach (self::REQUIRED_KEYS as $key) {
            $value = getenv($key);
            if ($value === false) {
                $missing[] = $key;
            } else {
                $values[$key] = $value;
            }
        }

        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $values)) {
                $envValue = getenv($key);
                $values[$key] = ($envValue === false) ? $default : $envValue;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Missing required environment variables: ' . implode(', ', $missing)
            );
        }

        self::$values = $values;
        self::$loaded = true;
    }

    private static function isEnvLoaded(): bool
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (getenv($key) === false) {
                return false;
            }
        }
        return true;
    }

    public static function get(string $key, $default = null)
    {
        self::load();

        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        if (func_num_args() >= 2) {
            return $default;
        }

        if (in_array($key, self::REQUIRED_KEYS)) {
            throw new \RuntimeException("Required configuration key '{$key}' is not set.");
        }

        return null;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);
        return (int) $value;
    }


    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? 'true' : 'false');
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }


    public static function getString(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);
        return (string) $value;
    }
}
