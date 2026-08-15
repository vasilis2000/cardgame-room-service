# Room Service

A PHP microservice that handles multiplayer **room / lobby management** for a game platform: creating rooms, joining them, marking players ready, and starting or finishing a match. It sits between the client, MongoDB (persistence), Redis (caching), and RabbitMQ (notifying a downstream game-start consumer).

## Features

- JWT-based authentication for all room actions
- Create, join, leave, and list game rooms
- Player "ready" toggling with automatic game start once every player is ready
- Room state caching in Redis (rooms, per-user room lookup, available-rooms list)
- Game-start events published to RabbitMQ for a worker to pick up
- CORS handling with an allow-list of origins
- Centralized HTTP exception handling with consistent JSON error responses

The service follows a simple layered structure:

| Layer | Responsibility |
|---|---|
| **Controllers** | Parse/validate HTTP input, call services, format responses |
| **Services** | Business logic (room lifecycle, ready checks, error mapping) |
| **Repositories** | MongoDB persistence + Redis caching for rooms |
| **Utilities** | Config loading, JWT validation, DB/queue connections |
| **Middleware** | CORS and authentication |
| **Exceptions** | Typed HTTP exceptions mapped to status codes |

## Requirements

- PHP 8.1+
- Composer
- MongoDB
- Redis
- RabbitMQ
- Docker & Docker Compose (recommended for local development)

## Configuration

Configuration is loaded via `App\Utilities\Config` from environment variables (using `vlucas/phpdotenv` if a `.env` file is present). Copy `.env.example` to `.env` (or create `.env`) and set the following:

| Variable | Required | Description |
|---|---|---|
| `MONGO_URI` | Yes | MongoDB connection string |
| `MONGO_DB` | Yes | MongoDB database name |
| `RABBITMQ_HOST` | Yes | RabbitMQ host |
| `RABBITMQ_PORT` | Yes | RabbitMQ port |
| `RABBITMQ_USER` | Yes | RabbitMQ username |
| `RABBITMQ_PASS` | Yes | RabbitMQ password |
| `REDIS_URL` | Yes | Redis connection URL |
| `JWT_SECRET` | Yes | Secret used to verify incoming JWTs (HS256) |
| `JWT_EXPIRY` | No (default `3600`) | Token expiry in seconds, used where tokens are issued |
| `ALLOWED_ORIGINS` | No (default `http://localhost`) | Comma-separated list of allowed CORS origins |

Startup fails fast with a clear error if any required variable is missing.

## Running Locally

### With Docker Compose (recommended)

```bash
docker-compose up --build
```

This brings up the PHP app alongside its dependencies as defined in `docker-compose.yml`.

### Manually

```bash
composer install
php -S localhost:8000 -t public
```

Make sure MongoDB, Redis, and RabbitMQ are running and reachable per your `.env` settings.

### Game-start worker

`bin/start_game_worker.php` consumes messages from the `start_game_queue` (published when a room becomes full and every player is ready) and should be run as a separate long-lived process:

```bash
php bin/start_game_worker.php
```

## API Reference

All endpoints are under `/room` and require a `Bearer <JWT>` token in the `Authorization` header unless noted otherwise. Responses are JSON.

### `POST /room/create`

Creates a new room with the authenticated user as the first player (defaults: game type `card`, `max_players = 2`).

- **201** — Room created
  ```json
  { "message": "Room created successfully.", "room": { "...": "..." } }
  ```
- **409** — User is already in a room

### `POST /room/join`

Body:
```json
{ "room_id": "<24-char hex Mongo ObjectId>" }
```

- **200** — Joined room
- **400** — Invalid room ID format
- **404** — Room not found
- **409** — Already in a room / room not waiting / room full
- **422** — `room_id` missing

### `GET /room/list`

Lists rooms currently open for joining (`status = waiting` and not full).

- **200** — Array of available rooms

### `POST /room/leave`

Removes the authenticated user from their current room. If the room is empty afterward it is deleted (when waiting), or fully cleaned up (when finished).

- **200** — Left room
- **404** — Not currently in a room
- **409** — Room is already starting/playing (can't leave)

### `POST /room/ready`

Toggles the authenticated user's ready state. When all seats are filled and every player is ready, the room is marked `starting` and a `start_game` event is published to RabbitMQ.

- **200** — One of: `"Ready status updated."`, `"Room started successfully."`, `"Room is being started by another player."`
- **409** — Room already started or finished
- **500** — Game could not be started (status/ready flags are rolled back automatically)

### `GET /room/current`

Returns the room the authenticated user is currently in, along with player details.

- **200** — Room + player list
- **404** — Not currently in a room

### `POST /room/finish`

Marks a room as finished with a winner. *(Not currently behind auth — intended for internal/service-to-service use; consider restricting network access or adding auth before exposing publicly.)*

Body:
```json
{ "room_id": "<room id>", "winner": "<user_id>" }
```

- **200** — Room finished
- **404** — Room not found
- **422** — Missing fields, room not in `starting` state, or winner not a player in the room

## Error Format

All errors share a consistent shape:

```json
{ "message": "Human readable error description." }
```

| Status | Meaning |
|---|---|
| 400 | Bad Request — malformed input (e.g. invalid ObjectId) |
| 401 | Authentication required / invalid token |
| 403 | CORS origin not allowed |
| 404 | Resource not found |
| 405 | HTTP method not allowed for the route |
| 409 | Conflict — invalid state transition (already in a room, room full, etc.) |
| 422 | Validation error — missing/invalid fields |
| 500 | Internal server error |

## Caching Strategy

`RoomRepository` caches in Redis to reduce MongoDB load:

- `room:{id}` — individual room document (30s TTL)
- `user_room:{userId}` — pointer from a user to their active room id (30s TTL)
- `available_rooms` — list of joinable rooms (30s TTL)

Caches are invalidated on any mutation (join, leave, ready toggle, status change, finish, delete).

## Tech Stack

- **PHP 8.1+**, `declare(strict_types=1)` throughout
- **MongoDB PHP Library** for persistence
- **Predis** for Redis caching
- **php-amqplib** for RabbitMQ publishing
- **firebase/php-jwt** for JWT verification
- **vlucas/phpdotenv** for environment configuration
