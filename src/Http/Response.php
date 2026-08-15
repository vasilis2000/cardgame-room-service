<?php

declare(strict_types=1);

namespace App\Http;


class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private ?array $payload = null;
    private bool $sent = false;

    public function setStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function setJson(array $data): self
    {
        $this->payload = $data;
        return $this;
    }

    public function send(): void
    {
        if ($this->sent) {
            return;
        }
        http_response_code($this->statusCode);
        $this->headers['Content-Type'] = 'application/json';
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->payload !== null) {
            echo json_encode($this->payload);
        }
        $this->sent = true;
        exit;
    }


    public static function json(int $status, array $data): void
    {
        $response = new self();
        $response->setStatus($status)->setJson($data)->send();
    }

    public static function success(array $data = []): void
    {
        self::json(200, $data);
    }

 
    public static function error(int $status, string $message): void
    {
        self::json($status, ['message' => $message]);
    }
}