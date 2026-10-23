<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions;

use EzPhp\Broadcast\BroadcastDriverInterface;

/**
 * Class SubscriptionBroadcastDriver
 *
 * ez-php/broadcast driver that feeds GraphQL subscriptions: every publish()
 * is handed to the SubscriptionManager, which runs the matching
 * subscriptions. An optional inner driver still receives the event too —
 * e.g. the WebSocketDriver for plain channel subscribers, or a LogDriver.
 *
 * Same-process only: install it as the broadcast driver of the process that
 * runs the WebSocket server, so a resolver or handler publishing there
 * reaches the connected subscribers.
 *
 *   Broadcast::setBroadcaster(new Broadcaster(new SubscriptionBroadcastDriver($manager)));
 *
 * @package EzPhp\GraphQLSubscriptions
 */
final class SubscriptionBroadcastDriver implements BroadcastDriverInterface
{
    /**
     * @param SubscriptionManager           $manager
     * @param BroadcastDriverInterface|null $inner   Also receives every event; null = subscriptions only.
     */
    public function __construct(
        private readonly SubscriptionManager $manager,
        private readonly ?BroadcastDriverInterface $inner = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function publish(string $channel, string $event, array $payload): void
    {
        $this->inner?->publish($channel, $event, $payload);

        $this->manager->publish($channel, $event, $payload);
    }
}
