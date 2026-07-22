<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\RoomController;
use App\Helpers\ResponseHelper;
use App\Exceptions\HttpException;
use App\Services\RoomService;
use App\Repositories\RoomRepository;

try {
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $requestUri = trim($requestUri, '/');
    $segments = $requestUri ? explode('/', $requestUri) : [];

    $resource = $segments[0] ?? '';
    $action   = $segments[1] ?? null;
    $method   = $_SERVER['REQUEST_METHOD'];


    $roomRepo = new RoomRepository();
    $roomService = new RoomService($roomRepo);
    $roomController = new RoomController($roomService);

    switch ($resource) {
        case 'room':
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
