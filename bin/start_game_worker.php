#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/helpers/Config.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;
 Config::load();
$host = Config::getString('RABBITMQ_HOST');
$port = Config::getString('RABBITMQ_PORT');
$user = Config::getString('RABBITMQ_USER');
$pass = Config::getString('RABBITMQ_PASS');

$baseUrl = rtrim(Config::getString('SERVER_BASE_URL'), '/');
$startGameUrl = $baseUrl . '/game/start';

function connectWithRetry($host, $port, $user, $pass, $maxAttempts = 30, $sleep = 3) {
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
}

function callStartGameEndpoint($roomId, $players) {
    global $startGameUrl;
    $payload = json_encode(['roomid' => $roomId, 'players' => $players]);

    $ch = curl_init($startGameUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo " [✗] cURL error: $error\n";
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        echo " [✓] Game start triggered successfully (HTTP $httpCode)\n";
        return true;
    } else {
        echo " [✗] Game start failed with HTTP $httpCode, response: $response\n";
        return false;
    }
}

function consume() {
    global $host, $port, $user, $pass;

    try {
        $connection = connectWithRetry($host, $port, $user, $pass);
        $channel = $connection->channel();
        $channel->queue_declare('start_game_queue', false, true, false, false);

        echo " [*] Waiting for start game messages. To exit press CTRL+C\n";

        $callback = function (AMQPMessage $msg) {
            $data = json_decode($msg->body, true);
            $roomId = $data['roomid'] ?? null;  
            $players = $data['players'] ?? [];

            if (!$roomId) {
                echo " [x] Invalid message: missing roomid, rejecting\n";
                $msg->nack(false, false); 
                return;
            }

            echo " [x] Received start game request for room $roomId\n";

            $success = callStartGameEndpoint($roomId, $players);

            if ($success) {
                $msg->ack();
                echo " [✓] Message acknowledged\n";
            } else {
                $msg->nack(false, true);
                echo " [⚠] Message nacked and requeued\n";
            }
        };

        $channel->basic_qos(null, 1, null);
        $channel->basic_consume('start_game_queue', '', false, false, false, false, $callback);

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

    } catch (Exception $e) {
        echo " [!] Fatal error: " . $e->getMessage() . "\n";
        sleep(5);
        consume(); 
    }
}

consume();