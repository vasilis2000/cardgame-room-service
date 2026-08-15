<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Response;
use App\Utilities\AuthHelper;
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
            Response::json(201, [
                'message' => 'Room created successfully.',
                'room'    => $room,
            ]);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (\Exception $e) {
            error_log('Create error: ' . $e->getMessage());
            Response::error(500, 'Internal server error.');
        }
    }

    public function join(array $data): void
    {
        if (empty($data['room_id'])) {
            Response::error(422, 'Room ID is required.');
        }
        if (!preg_match('/^[0-9a-f]{24}$/i', $data['room_id'])) {
            Response::error(400, 'Invalid room ID format.');
        }

        try {
            $user = AuthHelper::getAuthenticatedUser();
            $this->service->joinRoom($user['user_id'], $user['username'], $data['room_id']);
            Response::json(200, ['message' => 'Joined room successfully.']);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (\Exception $e) {
            error_log('Join error: ' . $e->getMessage());
            Response::error(500, 'Internal server error.');
        }
    }

    public function list(): void
    {
        try {
            AuthHelper::getAuthenticatedUser();
            $rooms = $this->service->listAvailableRooms();
            Response::json(200, $rooms);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (\Exception $e) {
            error_log('List error: ' . $e->getMessage());
            Response::error(500, 'Internal server error.');
        }
    }

    public function leave(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $this->service->leaveRoom($user['user_id']);
            Response::json(200, ['message' => 'Left room successfully.']);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (\Exception $e) {
            error_log('Leave error: ' . $e->getMessage());
            Response::error(500, 'Internal server error.');
        }
    }

    public function ready(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $result = $this->service->toggleReady($user['user_id']);
            Response::json(200, $result);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (\Exception $e) {
            error_log('Ready error: ' . $e->getMessage());
            Response::error(500, 'Internal server error.');
        }
    }

    public function getCurrent(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $room = $this->service->getCurrentRoom($user['user_id']);
            if (!$room) {
                Response::error(404, 'You are not in a room.');
            }
            Response::json(200, $room);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (\Exception $e) {
            error_log('Get room error: ' . $e->getMessage());
            Response::error(500, 'Internal server error.');
        }
    }

    public function finish(array $data): void
    {
        if (empty($data['room_id']) || empty($data['winner'])) {
            Response::error(422, 'Room ID and winner are required.');
        }

        try {
            $this->service->finishRoom($data['room_id'], (int)$data['winner']);
            Response::json(200, ['message' => 'Room finished successfully.']);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (\Exception $e) {
            error_log('Finish error: ' . $e->getMessage());
            Response::error(500, 'Internal server error.');
        }
    }
}