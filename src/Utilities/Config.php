<?php

declare(strict_types=1);

namespace App\Utilities;

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
        'ALLOWED_ORIGINS' => 'http://localhost',
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

        if (in_array($key, self::REQUIRED_KEYS, true)) {
            throw new \RuntimeException("Required configuration key '{$key}' is not set.");
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        if (func_num_args() >= 2) {
            return $default;
        }

        return null;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = (int) self::get($key, $default);
        if ($key === 'JWT_EXPIRY' && $value <= 0) {
            throw new \InvalidArgumentException('JWT_EXPIRY must be a positive integer.');
        }
        return $value;
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
        return (string) self::get($key, $default);
    }


  public static function getArray(string $key, string $separator = ',', array $default = []): array
    {
        $value = self::get($key, null);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return $default;
        }
        $parts = array_map('trim', explode($separator, $value));
        return array_values(array_filter($parts, static fn($part) => $part !== ''));
    }
}
