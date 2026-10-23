<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions;

use Closure;

/**
 * Class SubscriptionRegistry
 *
 * Maps each Subscription root field to the ez-php/broadcast channel that
 * feeds it. A channel is either a fixed name or a closure deriving it from
 * the field's (coerced) arguments, so `orderUpdated(id: 42)` can listen on
 * "orders.42":
 *
 *   $registry = (new SubscriptionRegistry())
 *       ->channel('orderShipped', 'orders', event: 'shipped')
 *       ->channel('orderUpdated', fn (array $args): string => 'orders.' . $args['id']);
 *
 * @package EzPhp\GraphQLSubscriptions
 */
final class SubscriptionRegistry
{
    /**
     * @var array<string, array{channel: string|Closure(array<string, mixed>): string, event: string|null}>
     */
    private array $routes = [];

    /**
     * Register the broadcast channel for a subscription field.
     *
     * @param string                                       $field   Subscription root field name.
     * @param string|Closure(array<string, mixed>): string $channel Channel name, or a closure receiving the field arguments.
     * @param string|null                                  $event   Only deliver events with this name; null = every event.
     *
     * @return self
     */
    public function channel(string $field, string|Closure $channel, ?string $event = null): self
    {
        $this->routes[$field] = ['channel' => $channel, 'event' => $event];

        return $this;
    }

    /**
     * Whether a channel is registered for the field.
     *
     * @param string $field
     *
     * @return bool
     */
    public function has(string $field): bool
    {
        return isset($this->routes[$field]);
    }

    /**
     * Resolve the route for a subscription field and its arguments.
     *
     * @param string               $field
     * @param array<string, mixed> $args  Coerced field arguments.
     *
     * @throws SubscriptionException When no channel is registered for the field.
     *
     * @return ChannelRoute
     */
    public function resolve(string $field, array $args): ChannelRoute
    {
        $route = $this->routes[$field] ?? throw new SubscriptionException(
            sprintf('Subscription field "%s" has no broadcast channel registered.', $field)
        );

        $channel = $route['channel'] instanceof Closure ? ($route['channel'])($args) : $route['channel'];

        return new ChannelRoute($channel, $route['event']);
    }
}
