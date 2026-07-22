#!/usr/bin/env php
<?php

declare(strict_types=1);


$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fwrite(STDERR, "❌ Unable to determine project root.\n");
    exit(1);
}

$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "❌ Composer autoloader not found at $autoloadPath\n");
    exit(1);
}
require_once $autoloadPath;


$envFile = $projectRoot . '/.env';
if (file_exists($envFile)) {
    try {
        $dotenv = \Dotenv\Dotenv::createImmutable($projectRoot);
        $dotenv->load();
        echo "✓ .env loaded from $projectRoot\n";
    } catch (\Exception $e) {
        fwrite(STDERR, "⚠️ Failed to load .env: " . $e->getMessage() . "\n");
    }
} else {
    fwrite(STDERR, "⚠️ No .env file found – relying on existing environment variables.\n");
}

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;

$host = getenv('RABBITMQ_HOST') ?: 'localhost';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5672);
$user = getenv('RABBITMQ_USER') ?: 'guest';
$pass = getenv('RABBITMQ_PASS') ?: 'guest';
$queue = 'start_game_queue';
$startGameUrl = 'http://host.docker.internal:8083/game/start';

$maxRetries = 3;
$retryDelay = 5;
$maxReconnectAttempts = 30;
$reconnectSleep = 3;

function connectWithRetry(string $host, int $port, string $user, string $pass, int $maxAttempts, int $sleep): AMQPStreamConnection
{
    for ($i = 1; $i <= $maxAttempts; $i++) {
        try {
            echo " [*] Attempt $i: Connecting to RabbitMQ at $host:$port...\n";
            $connection = new AMQPStreamConnection($host, $port, $user, $pass);
            echo " [✓] Connected to RabbitMQ\n";
            return $connection;
        } catch (AMQPIOException $e) {
            echo " [!] Connection failed: " . $e->getMessage() . "\n";
            if ($i === $maxAttempts) {
                throw $e;
            }
            sleep($sleep);
        }
    }
    throw new \RuntimeException('Unable to connect to RabbitMQ after maximum attempts.');
}

function callStartGameEndpoint(string $roomId, array $players, string $url, int $maxRetries, int $retryDelay): bool
{
    $payload = json_encode(['roomid' => $roomId, 'players' => $players]);
    $attempt = 1;

    while ($attempt <= $maxRetries) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            echo " [✗] cURL error (attempt $attempt): $error\n";
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            echo " [✓] Game start triggered successfully (HTTP $httpCode)\n";
            return true;
        } else {
            echo " [✗] Game start failed (attempt $attempt) with HTTP $httpCode, response: $response\n";
        }

        if ($attempt < $maxRetries) {
            sleep($retryDelay * $attempt); // exponential backoff
        }
        $attempt++;
    }

    echo " [✗] All HTTP retries exhausted for room $roomId\n";
    return false;
}


function consume(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $queue,
    string $startGameUrl,
    int $maxRetries,
    int $retryDelay,
    int $maxReconnectAttempts,
    int $reconnectSleep
): void {
    while (true) {
        try {
            $connection = connectWithRetry($host, $port, $user, $pass, $maxReconnectAttempts, $reconnectSleep);
            $channel = $connection->channel();
            $channel->queue_declare($queue, false, true, false, false);

            echo " [*] Waiting for start game messages. To exit press CTRL+C\n";

            $callback = function (AMQPMessage $msg) use ($startGameUrl, $maxRetries, $retryDelay) {
                $data = json_decode($msg->body, true);
                if (!isset($data['roomid']) || !is_string($data['roomid'])) {
                    echo " [x] Invalid message: missing or invalid roomid, rejecting (no requeue)\n";
                    $msg->nack(false, false);
                    return;
                }
                $roomId = $data['roomid'];
                $players = $data['players'] ?? [];

                echo " [x] Received start game request for room $roomId\n";

                $success = callStartGameEndpoint($roomId, $players, $startGameUrl, $maxRetries, $retryDelay);

                if ($success) {
                    $msg->ack();
                    echo " [✓] Message acknowledged\n";
                } else {
                    echo " [⚠] Message processing failed; rejecting without requeue.\n";
                    $msg->nack(false, false);
                }
            };

            $channel->basic_qos(null, 1, null);
            $channel->basic_consume($queue, '', false, false, false, false, $callback);

            while ($channel->is_consuming()) {
                try {
                    $channel->wait();
                } catch (AMQPIOException $e) {
                    echo " [!] Connection lost, reconnecting...\n";
                    break;
                }
            }

            $channel->close();
            $connection->close();
        } catch (\Exception $e) {
            echo " [!] Fatal error: " . $e->getMessage() . "\n";
            sleep($reconnectSleep);
        }
    }
}

consume(
    $host,
    $port,
    $user,
    $pass,
    $queue,
    $startGameUrl,
    $maxRetries,
    $retryDelay,
    $maxReconnectAttempts,
    $reconnectSleep
);
