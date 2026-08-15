<?php

declare(strict_types=1);

namespace App;

use App\Controllers\RoomController;
use App\Services\RoomService;
use App\Repositories\RoomRepository;
use App\Http\Request;
use App\Http\Response;
use App\Exceptions\{
    ValidationException,
    AuthenticationException,
    HttpException,
    NotFoundException,
    ConflictException,
    BadRequestException,
    InternalServerException
};

class Router
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function dispatch(): void
    {
        $exceptionMap = [
            ValidationException::class      => 422,
            AuthenticationException::class  => 401,
            NotFoundException::class        => 404,
            ConflictException::class        => 409,
            BadRequestException::class      => 400,
            InternalServerException::class  => 500,
        ];

       try {
            $segments = $this->request->getSegments();
            $resource = $segments[0] ?? '';
            $action   = $segments[1] ?? null;
            $method   = $this->request->getMethod();

            switch ($resource) {
                case 'room':
                    $this->handleRoomRoutes($action, $method);
                    break;

                default:
                    Response::error(404, 'Not Found');
            }
        } catch (\Throwable $e) {
            $this->handleException($e, $exceptionMap);
        }
    }

    private function handleRoomRoutes(?string $action, string $method): void
    {
        $roomRepo = new RoomRepository();
        $roomService = new RoomService($roomRepo);
        $roomController = new RoomController($roomService);

        switch ($action) {
            case 'create':
                if ($method !== 'POST') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $roomController->create();
                break;

            case 'join':
                if ($method !== 'POST') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $data = $this->request->getJsonBody();
                $roomController->join($data);
                break;

            case 'list':
                if ($method !== 'GET') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $roomController->list();
                break;

            case 'leave':
                if ($method !== 'POST') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $roomController->leave();
                break;

            case 'ready':
                if ($method !== 'POST') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $roomController->ready();
                break;

            case 'current':
                if ($method !== 'GET') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $roomController->getCurrent();
                break;

            case 'finish':
                if ($method !== 'POST') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $data = $this->request->getJsonBody();
                $roomController->finish($data);
                break;

            default:
                Response::error(404, 'Not Found');
        }
    }

    private function handleException(\Throwable $e, array $exceptionMap): void
    {
        if ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            $message = $e->getMessage();
        } else {
            $status = $exceptionMap[get_class($e)] ?? 500;
            $message = ($status === 500) ? 'Internal server error.' : $e->getMessage();
        }
        error_log('Request error: ' . (string) $e);
        Response::error($status, $message);
    }
}