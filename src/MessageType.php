<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions;

/**
 * Enum MessageType
 *
 * The `type` values of the graphql-transport-ws protocol
 * (https://github.com/enisdenjo/graphql-ws/blob/master/PROTOCOL.md).
 *
 * @package EzPhp\GraphQLSubscriptions
 */
enum MessageType: string
{
    case ConnectionInit = 'connection_init';
    case ConnectionAck = 'connection_ack';
    case Ping = 'ping';
    case Pong = 'pong';
    case Subscribe = 'subscribe';
    case Next = 'next';
    case Error = 'error';
    case Complete = 'complete';
}
