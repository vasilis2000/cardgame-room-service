<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Helpers\MongoDBConnection;
use App\Helpers\RedisConnection;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Predis\Client;
use Exception;

class RoomRepository
{
    private \MongoDB\Database $db;
    private \MongoDB\Collection $rooms;
    private Client $redis;

    public function __construct()
    {
        $this->db    = MongoDBConnection::getDatabase();
        $this->rooms = $this->db->selectCollection('rooms');
        $this->redis = RedisConnection::getClient();
    }

    private function cacheRoom(string $id, array $room): void
    {
        try {
            $this->redis->setex("room:{$id}", 30, json_encode($room));
        } catch (\Exception $e) {
        }
    }

    private function getCachedRoom(string $id): ?array
    {
        try {
            $data = $this->redis->get("room:{$id}");
            return $data ? json_decode($data, true) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function deleteRoomCache(string $id): void
    {
        try {
            $this->redis->del("room:{$id}");
        } catch (\Exception $e) {
        }
    }

    private function deleteUserRoomCache(int $userId): void
    {
        try {
            $this->redis->del("user_room:{$userId}");
        } catch (\Exception $e) {
        }
    }

    private function deleteAvailableRoomsCache(): void
    {
        try {
            $this->redis->del('available_rooms');
        } catch (\Exception $e) {
        }
    }

    private function clearAllPlayerRoomCaches(string $roomId): void
    {
        $room = $this->findById($roomId);
        if (!$room) return;
        foreach ($room['players'] as $p) {
            $this->deleteUserRoomCache((int)$p['user_id']);
        }
    }

    private function mapRoom(array $doc): array
    {
        $doc['id'] = (string) $doc['_id'];
        unset($doc['_id']);
        $doc['max_players']     = (int)$doc['max_players'];
        $doc['current_players'] = (int)$doc['current_players'];
        return $doc;
    }

    public function createWithPlayer(string $gameType, int $maxPlayers, int $userId, string $username): string
    {
        $doc = [
            'game_type'      => $gameType,
            'max_players'    => $maxPlayers,
            'current_players' => 1,
            'status'         => 'waiting',
            'winner'         => '',
            'players'        => [
                [
                    'user_id'  => $userId,
                    'username' => $username,
                    'is_ready' => false,
                ]
            ],
            'created_at'     => new UTCDateTime()
        ];

        $result = $this->rooms->insertOne($doc);
        $roomId = (string) $result->getInsertedId();

        $this->deleteAvailableRoomsCache();
        $room = $this->findById($roomId);
        if ($room) {
            $this->cacheRoom($roomId, $room);
        }
        return $roomId;
    }

    public function addPlayer(string $roomId, int $userId, string $username): void
    {
        try {
            $oid = new ObjectId($roomId);
        } catch (\InvalidArgumentException $e) {
            throw new Exception('Invalid room ID.');
        }

        $newPlayer = [
            'user_id'  => $userId,
            'username' => $username,
            'is_ready' => false,
        ];

        $result = $this->rooms->updateOne(
            [
                '_id' => $oid,
                'players.user_id' => ['$ne' => $userId],
                '$expr' => ['$lt' => ['$current_players', '$max_players']]
            ],
            [
                '$push' => ['players' => $newPlayer],
                '$inc'  => ['current_players' => 1]
            ]
        );

        if ($result->getMatchedCount() === 0) {
            $room = $this->rooms->findOne(['_id' => $oid]);
            if (!$room) throw new NotFoundException('Room not found.');
            if (in_array($userId, array_column($room['players'], 'user_id'))) {
                throw new ConflictException('User already in this room.');
            }
            throw new ConflictException('Room is full.');
        }

        $this->deleteRoomCache($roomId);
        $this->clearAllPlayerRoomCaches($roomId);
        $this->deleteUserRoomCache((int)$userId);
        $this->deleteAvailableRoomsCache();
    }

    public function findById(string $id): ?array
    {
        $cached = $this->getCachedRoom($id);
        if ($cached) {
            return $cached;
        }

        try {
            $oid = new ObjectId($id);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        $doc = (array) $this->rooms->findOne(['_id' => $oid]);
        if (!$doc) {
            return null;
        }
        $room = $this->mapRoom($doc);
        $this->cacheRoom($id, $room);
        return $room;
    }

    public function findActiveRoomForUser(int $userId): ?array
    {
        $doc = (array) $this->rooms->findOne([
            'players.user_id' => $userId,
            'status' => ['$ne' => 'finished']
        ]);
        return $doc ? $this->mapRoom((array)$doc) : null;
    }

    public function getAvailableRooms(): array
    {
        try {
            $cached = $this->redis->get('available_rooms');
            if ($cached) {
                return json_decode($cached, true);
            }
        } catch (\Exception $e) {
        }

        $cursor = $this->rooms->find([
            'status' => 'waiting',
            '$expr' => ['$lt' => ['$current_players', '$max_players']]
        ]);
        $rooms = [];
        foreach ($cursor as $doc) {
            $rooms[] = $this->mapRoom((array)$doc);
        }

        try {
            $this->redis->setex('available_rooms', 30, json_encode($rooms));
        } catch (\Exception $e) {
        }
        return $rooms;
    }

    public function removePlayer(string $roomId, int $userId): void
    {
        try {
            $oid = new ObjectId($roomId);
        } catch (\InvalidArgumentException $e) {
            throw new \App\Exceptions\BadRequestException('Invalid room ID format.');
        }

        $update = [
            '$pull' => ['players' => ['user_id' => $userId]],
            '$inc'  => ['current_players' => -1],
        ];

        $updatedRoom = $this->rooms->findOneAndUpdate(
            ['_id' => $oid, 'players.user_id' => $userId],
            $update,
            ['returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );

        if (!$updatedRoom) {
            throw new ConflictException('User is not a player in this room.');
        }

        if ($updatedRoom['current_players'] == 0 && $updatedRoom['status'] === 'waiting') {
            $this->rooms->deleteOne([
                '_id' => $oid,
                'current_players' => 0,
                'status' => 'waiting'
            ]);
        }

        $this->deleteRoomCache($roomId);
        foreach ($updatedRoom['players'] as $p) {
            $this->deleteUserRoomCache((int)$p['user_id']);
        }
        $this->deleteAvailableRoomsCache();
    }

    public function setReady(string $roomId, int $userId, bool $ready): void
    {
        try {
            $oid = new ObjectId($roomId);
        } catch (\InvalidArgumentException $e) {
            throw new Exception('Invalid room ID.');
        }

        $result = $this->rooms->updateOne(
            ['_id' => $oid, 'players.user_id' => $userId],
            ['$set' => ['players.$.is_ready' => $ready]]
        );
        if ($result->getMatchedCount() === 0) {
            throw new Exception('User not in this room.');
        }
        $this->deleteRoomCache($roomId);
        $this->clearAllPlayerRoomCaches($roomId);
        $this->deleteAvailableRoomsCache();
    }

    public function areAllReady(string $roomId): bool
    {
        try {
            $oid = new ObjectId($roomId);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $room = $this->rooms->findOne(['_id' => $oid]);
        if (!$room) return false;
        if (count($room['players']) < $room['max_players']) return false;
        foreach ($room['players'] as $p) {
            if (!$p['is_ready']) return false;
        }
        return true;
    }

    public function getPlayerReadyStatus(string $roomId, int $userId): ?bool
    {
        try {
            $oid = new ObjectId($roomId);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        $room = $this->rooms->findOne(['_id' => $oid]);
        if (!$room) return null;
        foreach ($room['players'] as $p) {
            if ((int)$p['user_id'] === $userId) {
                return (bool)$p['is_ready'];
            }
        }
        return null;
    }

    public function getRoomWithPlayerid(int $userId): ?array
    {
        $cachedRoomId = null;
        try {
            $cachedRoomId = $this->redis->get("user_room:{$userId}");
        } catch (\Exception $e) {
        }

        $room = null;
        if ($cachedRoomId) {
            $roomData = $this->findById($cachedRoomId);
            if ($roomData && $roomData['status'] !== 'finished') {
                $room = $roomData;
            }
        }

        if (!$room) {
            $doc = (array) $this->rooms->findOne(['players.user_id' => $userId]);
            if (!$doc) {
                return null;
            }
            $room = $this->mapRoom($doc);
            try {
                $this->redis->setex("user_room:{$userId}", 30, (string)$room['id']);
            } catch (\Exception $e) {
            }
            $this->cacheRoom($room['id'], $room);
        }

        $players = [];
        foreach ($room['players'] as $p) {
            $players[] = [
                'id'       => (int)$p['user_id'],
                'username' => $p['username'],
                'is_ready' => (bool)$p['is_ready'],
            ];
        }

        return [
            'room'    => $room,
            'players' => $players,
        ];
    }

    public function markRoomAsStarting(string $roomId): bool
    {
        try {
            $oid = new ObjectId($roomId);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $result = $this->rooms->updateOne(
            [
                '_id' => $oid,
                'status' => 'waiting',
                '$expr' => ['$eq' => ['$current_players', '$max_players']],
                'players.is_ready' => ['$not' => ['$elemMatch' => ['$eq' => false]]]
            ],
            ['$set' => ['status' => 'starting']]
        );
        $success = $result->getModifiedCount() > 0;

        if ($success) {
            $this->deleteRoomCache($roomId);
            $this->clearAllPlayerRoomCaches($roomId);
            $this->deleteAvailableRoomsCache();
        }

        return $success;
    }

    public function revertRoomStatus(string $roomId, string $status): bool
    {
        try {
            $oid = new ObjectId($roomId);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $result = $this->rooms->updateOne(
            ['_id' => $oid],
            ['$set' => ['status' => $status]]
        );

        if ($result->getModifiedCount() > 0) {
            $this->deleteRoomCache($roomId);
            $this->clearAllPlayerRoomCaches($roomId);
            $this->deleteAvailableRoomsCache();
            return true;
        }
        return false;
    }

    public function resetAllReady(string $roomId): bool
    {
        try {
            $oid = new ObjectId($roomId);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $result = $this->rooms->updateOne(
            ['_id' => $oid],
            ['$set' => ['players.$[].is_ready' => false]]
        );

        if ($result->getModifiedCount() > 0) {
            $this->deleteRoomCache($roomId);
            $this->clearAllPlayerRoomCaches($roomId);
            return true;
        }
        return false;
    }

    public function finishRoom(string $roomId, int $winnerId): bool
    {
        try {
            $oid = new ObjectId($roomId);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $result = $this->rooms->updateOne(
            ['_id' => $oid],
            ['$set' => ['status' => 'finished', 'winner' => (string)$winnerId]]
        );

        if ($result->getModifiedCount() > 0) {
            $this->deleteRoomCache($roomId);
            $this->clearAllPlayerRoomCaches($roomId);
            $this->deleteAvailableRoomsCache();
            return true;
        }
        return false;
    }
}
