<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\RoomController;
use App\Utilities\ResponseHelper;
use App\Exceptions\HttpException;
use App\Services\RoomService;
use App\Repositories\RoomRepository;
use App\Utilities\Config;


try {

    Config::load();

    header('Content-Type: application/json');

    $allowedOrigins = Config::getArray('ALLOWED_ORIGINS', ',', ['http://localhost']);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
        http_response_code(403);
        echo json_encode(['message' => 'Origin not allowed.']);
        exit;
    }

    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $requestMethod = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? null;
        $requestHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? null;

        if ($requestMethod) {
            header('Access-Control-Allow-Methods: ' . $requestMethod);
        }
        if ($requestHeaders) {
            header('Access-Control-Allow-Headers: ' . $requestHeaders);
        }

        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
        exit;
    }

    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $requestUri = trim($requestUri, '/');
    $segments = $requestUri ? explode('/', $requestUri) : [];

    $resource = $segments[0] ?? '';
    $action   = $segments[1] ?? null;
    $method   = $_SERVER['REQUEST_METHOD'];

    switch ($resource) {
        case 'room':
            $roomRepo = new RoomRepository();
            $roomService = new RoomService($roomRepo);
            $roomController = new RoomController($roomService);
            switch ($action) {
                case 'create':
                    if ($method === 'POST') {
                        $roomController->create();
                    } else {
                        ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                case 'join':
                    if ($method === 'POST') {
                        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
                        $roomController->join($data);
                    } else {
                        ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                case 'list':
                    if ($method === 'GET') {
                        $roomController->list();
                    } else {
                        ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                case 'leave':
                    if ($method === 'POST') {
                        $roomController->leave();
                    } else {
                        ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                case 'ready':
                    if ($method === 'POST') {
                        $roomController->ready();
                    } else {
                        ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                case 'current':
                    if ($method === 'GET') {
                        $roomController->getCurrent();
                    } else {
                        ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                case 'finish':
                    if ($method === 'POST') {
                        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
                        $roomController->finish($data);
                    } else {
                        ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                default:
                    ResponseHelper::sendResponse(404, ['message' => 'Not Found']);
                    break;
            }
            break;
        default:
            ResponseHelper::sendResponse(404, ['message' => 'Not Found']);
            break;
    }
} catch (HttpException $e) {
    ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('Unhandled router error: ' . $e->getMessage());
    ResponseHelper::sendResponse(500, ['error' => 'Internal server error.']);
}
