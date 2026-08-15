<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Router;
use App\Http\Request;
use App\Http\Response;
use App\Utilities\Config;
use App\Middleware\CorsMiddleware;

Config::load();

$request = new Request();

$cors = new CorsMiddleware();
$cors->handle($request);

try {
    $router = new Router($request);
    $router->dispatch();
} catch (\Throwable $e) {
    error_log('Unhandled router error: ' . (string) $e);
    Response::error(500, 'Internal server error.');
}