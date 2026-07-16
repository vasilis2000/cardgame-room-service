<?php
require_once __DIR__ . '/../repos/rooms.php';

class RoomController
{
    private RoomRepository $repo;

    public function __construct()
    {
        
        $this->repo = new RoomRepository();
    }

    public function create(): void
    {
        $user = AuthHelper::getAuthenticatedUser();

        if ($this->repo->findActiveRoomForUser($user['user_id'])) {
            ResponseHelper::sendResponse(409, ['message' => 'You are already in a room.']);
        }

        try {
            $gameType   = Config::getString('DEFAULT_GAME_TYPE', 'card');
            $maxPlayers = Config::getInt('DEFAULT_MAX_PLAYERS', 2);

            $roomId = $this->repo->create($gameType, $maxPlayers);
            $this->repo->addPlayer($roomId, $user['user_id'], $user['username']);
            $room = $this->repo->findById($roomId);
            ResponseHelper::sendResponse(201, [
                'message' => 'Room created successfully.',
                'room'    => $room,
            ]);
        } catch (Exception $e) {
            ResponseHelper::sendResponse(500, ['message' => $e->getMessage()]);
        }
    }

    public function join(array $data): void
    {
        if (empty($data['room_id'])) {
            ResponseHelper::sendResponse(422, ['message' => 'Room ID is required.']);
        }

        $user   = AuthHelper::getAuthenticatedUser();
        $roomId = $data['room_id'];

        if (!preg_match('/^[0-9a-f]{24}$/i', $roomId)) {
            ResponseHelper::sendResponse(400, ['message' => 'Invalid room ID format.']);
        }

        if ($this->repo->findActiveRoomForUser($user['user_id'])) {
            ResponseHelper::sendResponse(409, ['message' => 'You are already in a room.']);
        }

        $room = $this->repo->findById($roomId);
        if (!$room) {
            ResponseHelper::sendResponse(404, ['message' => 'Room not found.']);
        }
        if ($room['status'] !== 'waiting') {
            ResponseHelper::sendResponse(409, ['message' => 'Room is not waiting for players.']);
        }
        if ($room['current_players'] >= $room['max_players']) {
            ResponseHelper::sendResponse(409, ['message' => 'Room is full.']);
        }

        try {
            $this->repo->addPlayer($room['id'], $user['user_id'], $user['username']);
            ResponseHelper::sendResponse(200, ['message' => 'Joined room successfully.']);
        } catch (Exception $e) {
            ResponseHelper::sendResponse(500, ['message' => $e->getMessage()]);
        }
    }

    public function list(): void
    {
       
        try {
            AuthHelper::getAuthenticatedUser();
            $rooms = $this->repo->getAvailableRooms();
            ResponseHelper::sendResponse(200, $rooms);
        } catch (Exception $e) {
            ResponseHelper::sendResponse(500, ['message' => $e->getMessage()]);
        }
    }

    public function leave(): void
    {
        $userId = AuthHelper::getAuthenticatedUser()['user_id'];
        $roomData = $this->repo->getRoomWithPlayerid($userId);
        if (!$roomData) {
            ResponseHelper::sendResponse(404, ['message' => 'You are not in a room.']);
        }
        if ($roomData['room']['status'] !== 'waiting') {
            ResponseHelper::sendResponse(409, ['message' => 'Cannot leave a room that is already starting or playing.']);
        }
        $roomId = $roomData['room']['id'];

        try {
            $this->repo->removePlayer($roomId, $userId);
            ResponseHelper::sendResponse(200, ['message' => 'Left room successfully.']);
        } catch (Exception $e) {
            ResponseHelper::sendResponse(500, ['message' => $e->getMessage()]);
        }
    }

    public function ready(): void
    {
        $userId = AuthHelper::getAuthenticatedUser()['user_id'];
        $roomData = $this->repo->getRoomWithPlayerid($userId);
        if (!$roomData) {
            ResponseHelper::sendResponse(404, ['message' => 'You are not in a room.']);
        }

        $room = $roomData['room'];
        $roomId = $room['id'];

        if ($room['status'] === 'starting' || $room['status'] === 'playing') {
            ResponseHelper::sendResponse(409, ['message' => 'Room has already started.']);
        }

        $currentReady = $this->repo->getPlayerReadyStatus($roomId, $userId);
        $ready = !$currentReady;

        try {
            $this->repo->setReady($roomId, $userId, $ready);

            if ($this->repo->areAllReady($roomId)) {
                $started = $this->repo->markRoomAsStarting($roomId);

                if ($started) {
                    require_once __DIR__ . '/../helpers/RabbitMQPublisher.php';
                    $publisher = new RabbitMQPublisher();
                    try {
                        $publisher->publishStartGame($room['players'], $roomId);
                        ResponseHelper::sendResponse(200, ['message' => 'Room started successfully.']);
                    } catch (Exception $e) {
                        $this->repo->revertRoomStatus($roomId, 'waiting');
                        error_log(sprintf(
                            'Failed to publish start game for room %s: %s',
                            $roomId,
                            $e->getMessage()
                        ));
                        ResponseHelper::sendResponse(500, ['message' => 'Could not start game, please retry.']);
                    }
                } else {
                    ResponseHelper::sendResponse(200, ['message' => 'Room is being started by another player.']);
                }
            } else {
                ResponseHelper::sendResponse(200, ['message' => 'Ready status updated.']);
            }
        } catch (Exception $e) {
            ResponseHelper::sendResponse(500, ['message' => $e->getMessage()]);
        }
    }

    public function getroom(): void
    {
        try {
            $userId = AuthHelper::getAuthenticatedUser()['user_id'];
            $rooms = $this->repo->getRoomWithPlayerid($userId);
            if (!$rooms) {
                ResponseHelper::sendResponse(404, ['message' => 'You are not in a room.']);
            }
            ResponseHelper::sendResponse(200, $rooms);
        } catch (Exception $e) {
            ResponseHelper::sendResponse(500, ['message' => $e->getMessage()]);
        }
    }
}
