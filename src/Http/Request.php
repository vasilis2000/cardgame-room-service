<?php

declare(strict_types=1);

namespace App\Http;

class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $segments;
    private array $queryParams;
    private array $headers;
    private ?array $jsonBody = null;
    private string $rawBody;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $parsedUrl = parse_url($this->uri);
        $this->path = $parsedUrl['path'] ?? '/';
        $this->segments = $this->path === '/' ? [] : explode('/', trim($this->path, '/'));
        $this->queryParams = [];
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $this->queryParams);
        }
        $this->headers = $this->parseHeaders();
        $this->rawBody = file_get_contents('php://input') ?: '';
    }

    public function getJsonBody(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $contentType = $this->getHeader('content-type') ?? '';
        if (!str_contains($contentType, 'application/json')) {
            throw new \RuntimeException('Content-Type must be application/json');
        }

        if ($this->rawBody === '') {
            throw new \RuntimeException('Empty request body');
        }

        $data = json_decode($this->rawBody, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }
        if (!is_array($data)) {
            throw new \RuntimeException('JSON body must be an object');
        }

        $this->jsonBody = $data;
        return $this->jsonBody;
    }

    public function getJsonString(string $key, ?string $default = null): ?string
    {
        try {
            $body = $this->getJsonBody();
            $value = $body[$key] ?? null;
            return is_string($value) ? trim($value) : $default;
        } catch (\RuntimeException) {
            return $default;
        }
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getSegments(): array
    {
        return $this->segments;
    }

    public function getHeader(string $name): ?string
    {
        $normalized = strtolower($name);
        return $this->headers[$normalized] ?? null;
    }

    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->method;
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }
}