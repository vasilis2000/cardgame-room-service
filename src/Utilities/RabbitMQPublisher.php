<?php

declare(strict_types=1);

namespace App\Utilities;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Exception;

class RabbitMQPublisher
{
    private $connection;
    private $channel;

    private const CONFIRM_TIMEOUT = 5;

    public function __construct()
    {
        $host = Config::getString('RABBITMQ_HOST');
        $port = Config::getInt('RABBITMQ_PORT');
        $user = Config::getString('RABBITMQ_USER');
        $pass = Config::getString('RABBITMQ_PASS');

        try {
            $this->connection = new AMQPStreamConnection($host, $port, $user, $pass);
            $this->channel = $this->connection->channel();
            $this->channel->queue_declare('start_game_queue', false, true, false, false);

            $this->channel->confirm_select();
        } catch (Exception $e) {
            error_log('RabbitMQ connection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function publishStartGame(array $players, string $roomid): void
    {
        $message = new AMQPMessage(
            json_encode([
                'roomid'  => $roomid,
                'players' => $players
            ]),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        try {
            $this->channel->basic_publish($message, '', 'start_game_queue');

            $this->channel->wait_for_pending_acks(self::CONFIRM_TIMEOUT);
        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
            error_log('RabbitMQ publish confirm timed out for room ' . $roomid . ': ' . $e->getMessage());
            throw $e;
        } catch (Exception $e) {
            error_log('RabbitMQ publish failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function __destruct()
    {
        try {
            $this->channel->close();
            $this->connection->close();
        } catch (Exception $e) {
        }
    }
}