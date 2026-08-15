<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoomRepository;
use App\Utilities\RabbitMQPublisher;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ConflictException;
use App\Exceptions\InternalServerException;

class RoomService
{
    private RoomRepository $repo;
    private ?RabbitMQPublisher $publisher = null;

    public function __construct(RoomRepository $repo)
    {
        $this->repo = $repo;
    }

    private function getPublisher(): RabbitMQPublisher
    {
        if ($this->publisher === null) {
            $this->publisher = new RabbitMQPublisher();
        }
        return $this->publisher;
    }

    public function createRoom(int $userId, string $username, string $gameType = 'card', int $maxPlayers = 2): array
    {
        if ($this->repo->findActiveRoomForUser($userId)) {
            throw new ConflictException('You are already in a room.');
        }

        if ($maxPlayers < 2 || $maxPlayers > 4) {
            throw new ValidationException('max_players must be between 2 and 4.');
        }

        $roomId = $this->repo->createWithPlayer($gameType, $maxPlayers, $userId, $username);
        $room = $this->repo->findById($roomId);
        if (!$room) {
            throw new InternalServerException('Failed to create room.');
        }

        return $room;
    }

    public function joinRoom(int $userId, string $username, string $roomId): void
    {
        if ($this->repo->findActiveRoomForUser($userId)) {
            throw new ConflictException('You are already in a room.');
        }

        $room = $this->repo->findById($roomId);
        if (!$room) {
            throw new NotFoundException('Room not found.');
        }
        if ($room['status'] !== 'waiting') {
            throw new ConflictException('Room is not waiting for players.');
        }
        if ($room['current_players'] >= $room['max_players']) {
            throw new ConflictException('Room is full.');
        }

        $this->repo->addPlayer($roomId, $userId, $username);
    }

    public function listAvailableRooms(): array
    {
        return $this->repo->getAvailableRooms();
    }

    public function leaveRoom(int $userId): void
    {
        $roomData = $this->repo->getRoomWithPlayerid($userId);
        if (!$roomData) {
            throw new NotFoundException('You are not in a room.');
        }

        $room = $roomData['room'];
        $status = $room['status'];
        if ($status !== 'waiting' && $status !== 'finished') {
            throw new ConflictException('Cannot leave a room that is already starting or playing.');
        }

        $this->repo->removePlayer($room['id'], $userId);
        if ($room['status'] === 'finished' && $room['current_players'] - 1 === 0) {
            $this->repo->deleteRoom($room['id']);
        }
    }

    public function toggleReady(int $userId): array
    {
        $roomData = $this->repo->getRoomWithPlayerid($userId);
        if (!$roomData) {
            throw new NotFoundException('You are not in a room.');
        }

        $room = $roomData['room'];
        $roomId = $room['id'];

        if ($room['status'] !== 'waiting') {
            throw new ConflictException('Room has already started or finished.');
        }

        $currentReady = $this->repo->getPlayerReadyStatus($roomId, $userId);
        $newReady = !$currentReady;
        $this->repo->setReady($roomId, $userId, $newReady);

        if ($this->repo->areAllReady($roomId)) {
            if ($this->repo->markRoomAsStarting($roomId)) {
                try {
                    $players = array_map(function ($p) {
                        return ['user_id' => $p['user_id'], 'username' => $p['username']];
                    }, $room['players']);
                    $this->getPublisher()->publishStartGame($players, $roomId);
                    return ['message' => 'Room started successfully.'];
                } catch (\Exception $e) {
                    $this->repo->revertRoomStatus($roomId, 'waiting');
                    $this->repo->resetAllReady($roomId);
                    error_log(sprintf(
                        'Failed to publish start game for room %s: %s',
                        $roomId,
                        $e->getMessage()
                    ));
                    throw new InternalServerException('Could not start game, please retry.');
                }
            } else {
                return ['message' => 'Room is being started by another player.'];
            }
        }

        return ['message' => 'Ready status updated.'];
    }

    public function getCurrentRoom(int $userId): ?array
    {
        return $this->repo->getRoomWithPlayerid($userId);
    }

    public function finishRoom(string $roomId, int $winnerId): void
    {
        $room = $this->repo->findById($roomId);
        if (!$room) {
            throw new NotFoundException('Room not found.');
        }
        if ($room['status'] !== 'starting') {
            throw new ValidationException('Room is not in a state that can be finished.');
        }

        $players = array_column($room['players'], 'user_id');
        if (!in_array($winnerId, $players, true)) {
            throw new ValidationException('Winner must be a player in the room.');
        }

        $success = $this->repo->finishRoom($roomId, $winnerId);
        if (!$success) {
            throw new InternalServerException('Failed to finish room.');
        }
    }
}
