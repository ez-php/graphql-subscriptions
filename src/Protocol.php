<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions;

/**
 * Class Protocol
 *
 * graphql-transport-ws constants and message encoding.
 *
 * @package EzPhp\GraphQLSubscriptions
 */
final class Protocol
{
    /**
     * The WebSocket subprotocol name; pass it to ez-php/websocket's Server
     * (`subprotocols: [Protocol::SUBPROTOCOL]`) so the handshake negotiates it.
     */
    public const string SUBPROTOCOL = 'graphql-transport-ws';

    public const int CLOSE_INTERNAL_ERROR = 4500;

    public const int CLOSE_INVALID_MESSAGE = 4400;

    public const int CLOSE_UNAUTHORIZED = 4401;

    public const int CLOSE_FORBIDDEN = 4403;

    public const int CLOSE_SUBPROTOCOL_NOT_ACCEPTABLE = 4406;

    public const int CLOSE_INIT_TIMEOUT = 4408;

    public const int CLOSE_SUBSCRIBER_EXISTS = 4409;

    public const int CLOSE_TOO_MANY_INIT_REQUESTS = 4429;

    /**
     * Encode one protocol message as JSON.
     *
     * @param MessageType $type
     * @param string|null $id      Operation id (`next`, `error`, `complete`); null for connection-level messages.
     * @param mixed       $payload Message payload; null omits the key.
     *
     * @return string
     */
    public static function encode(MessageType $type, ?string $id = null, mixed $payload = null): string
    {
        $message = ['type' => $type->value];

        if ($id !== null) {
            $message['id'] = $id;
        }

        if ($payload !== null) {
            $message['payload'] = $payload;
        }

        return json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
