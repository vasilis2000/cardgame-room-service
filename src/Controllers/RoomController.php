<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ResponseHelper;
use App\Helpers\AuthHelper;
use App\Exceptions\HttpException;
use App\Services\RoomService;

class RoomController
{
    private RoomService $service;

     public function __construct(RoomService $service)
    {
        $this->service = $service;
    }

    public function create(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $room = $this->service->createRoom($user['user_id'], $user['username'], "card", 2);
            ResponseHelper::sendResponse(201, [
                'message' => 'Room created successfully.',
                'room'    => $room,
            ]);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (\Exception $e) {
            error_log('Create error: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'Internal server error.']);
        }
    }

    public function join(array $data): void
    {
        if (empty($data['room_id'])) {
            ResponseHelper::sendResponse(422, ['message' => 'Room ID is required.']);
        }
        if (!preg_match('/^[0-9a-f]{24}$/i', $data['room_id'])) {
            ResponseHelper::sendResponse(400, ['message' => 'Invalid room ID format.']);
        }

        try {
            $user = AuthHelper::getAuthenticatedUser();
            $this->service->joinRoom($user['user_id'], $user['username'], $data['room_id']);
            ResponseHelper::sendResponse(200, ['message' => 'Joined room successfully.']);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (\Exception $e) {
            error_log('Join error: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'Internal server error.']);
        }
    }

    public function list(): void
    {
        try {
            AuthHelper::getAuthenticatedUser();
            $rooms = $this->service->listAvailableRooms();
            ResponseHelper::sendResponse(200, $rooms);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (\Exception $e) {
            error_log('List error: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'Internal server error.']);
        }
    }

    public function leave(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $this->service->leaveRoom($user['user_id']);
            ResponseHelper::sendResponse(200, ['message' => 'Left room successfully.']);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (\Exception $e) {
            error_log('Leave error: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'Internal server error.']);
        }
    }

    public function ready(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $result = $this->service->toggleReady($user['user_id']);
            ResponseHelper::sendResponse(200, $result);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (\Exception $e) {
            error_log('Ready error: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'Internal server error.']);
        }
    }

    public function getCurrent(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $room = $this->service->getCurrentRoom($user['user_id']);
            if (!$room) {
                ResponseHelper::sendResponse(404, ['message' => 'You are not in a room.']);
            }
            ResponseHelper::sendResponse(200, $room);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (\Exception $e) {
            error_log('Get room error: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'Internal server error.']);
        }
    }

    public function finish(array $data): void
    {
        if (empty($data['room_id']) || empty($data['winner'])) {
            ResponseHelper::sendResponse(422, ['message' => 'Room ID and winner are required.']);
        }

        try {
            $this->service->finishRoom($data['room_id'], (int)$data['winner']);
            ResponseHelper::sendResponse(200, ['message' => 'Room finished successfully.']);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (\Exception $e) {
            error_log('Finish error: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'Internal server error.']);
        }
    }
}
