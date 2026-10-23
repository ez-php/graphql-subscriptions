<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions;

/**
 * Class ChannelRoute
 *
 * The broadcast channel (and optional event name) one subscription listens to,
 * as resolved by SubscriptionRegistry from the subscription field and its
 * arguments.
 *
 * @package EzPhp\GraphQLSubscriptions
 */
final readonly class ChannelRoute
{
    /**
     * @param string      $channel Broadcast channel name, e.g. "orders.42".
     * @param string|null $event   Only events with this name match; null matches every event on the channel.
     */
    public function __construct(
        public string $channel,
        public ?string $event = null,
    ) {
    }

    /**
     * Whether a published (channel, event) pair is delivered to this route.
     *
     * @param string $channel
     * @param string $event
     *
     * @return bool
     */
    public function matches(string $channel, string $event): bool
    {
        return $this->channel === $channel && ($this->event === null || $this->event === $event);
    }
}
