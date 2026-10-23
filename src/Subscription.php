<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions;

use EzPhp\WebSocket\ConnectionInterface;

/**
 * Class Subscription
 *
 * One active subscription: the client connection and operation id it belongs
 * to, the document to execute for every matching event, and the broadcast
 * route it listens on.
 *
 * @package EzPhp\GraphQLSubscriptions
 */
final readonly class Subscription
{
    /**
     * @param ConnectionInterface  $connection    Client connection the results are sent to.
     * @param string               $id            Client-chosen operation id (unique per connection).
     * @param string               $query         Validated subscription document.
     * @param array<string, mixed> $variables     Raw variables from the subscribe message.
     * @param string|null          $operationName Operation to execute in a multi-operation document.
     * @param ChannelRoute         $route         Broadcast channel/event this subscription listens on.
     * @param mixed                $context       Resolver context established at connection_init.
     */
    public function __construct(
        public ConnectionInterface $connection,
        public string $id,
        public string $query,
        public array $variables,
        public ?string $operationName,
        public ChannelRoute $route,
        public mixed $context = null,
    ) {
    }
}
