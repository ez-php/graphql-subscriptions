<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions;

use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\WebSocket\ConnectionInterface;

/**
 * Class SubscriptionManager
 *
 * Holds the active subscriptions of every connection and delivers broadcast
 * events to them: publish() executes each matching subscription's document
 * with the event payload as root value and sends the result as a `next`
 * message.
 *
 * Lives in the WebSocket server process; SubscriptionBroadcastDriver feeds it
 * from ez-php/broadcast publishes made in that same process.
 *
 * @package EzPhp\GraphQLSubscriptions
 */
final class SubscriptionManager
{
    /**
     * @var array<string, array<string, Subscription>> connection id => operation id => subscription
     */
    private array $subscriptions = [];

    /**
     * @param GraphQLExecutor $executor Executes the subscription document per event (depth/complexity limits apply).
     */
    public function __construct(private readonly GraphQLExecutor $executor)
    {
    }

    /**
     * Register a subscription, replacing one with the same connection and id.
     *
     * @param Subscription $subscription
     *
     * @return void
     */
    public function add(Subscription $subscription): void
    {
        $this->subscriptions[$subscription->connection->id()][$subscription->id] = $subscription;
    }

    /**
     * Whether the connection has an active subscription with this id.
     *
     * @param ConnectionInterface $connection
     * @param string              $id
     *
     * @return bool
     */
    public function has(ConnectionInterface $connection, string $id): bool
    {
        return isset($this->subscriptions[$connection->id()][$id]);
    }

    /**
     * Remove one subscription.
     *
     * @param ConnectionInterface $connection
     * @param string              $id
     *
     * @return void
     */
    public function remove(ConnectionInterface $connection, string $id): void
    {
        unset($this->subscriptions[$connection->id()][$id]);

        if (($this->subscriptions[$connection->id()] ?? null) === []) {
            unset($this->subscriptions[$connection->id()]);
        }
    }

    /**
     * Remove every subscription of a connection (on close).
     *
     * @param ConnectionInterface $connection
     *
     * @return void
     */
    public function removeAll(ConnectionInterface $connection): void
    {
        unset($this->subscriptions[$connection->id()]);
    }

    /**
     * Number of active subscriptions of one connection.
     *
     * @param ConnectionInterface $connection
     *
     * @return int
     */
    public function countFor(ConnectionInterface $connection): int
    {
        return count($this->subscriptions[$connection->id()] ?? []);
    }

    /**
     * Number of active subscriptions across all connections.
     *
     * @return int
     */
    public function count(): int
    {
        return array_sum(array_map('count', $this->subscriptions));
    }

    /**
     * Deliver a broadcast event to every matching subscription.
     *
     * Execution errors are part of the `next` payload (`errors` key), as the
     * protocol specifies; the subscription stays active. Subscriptions of a
     * connection that is no longer open are dropped.
     *
     * @param string               $channel
     * @param string               $event
     * @param array<string, mixed> $payload Passed to the subscription field resolver as its root value.
     *
     * @return void
     */
    public function publish(string $channel, string $event, array $payload): void
    {
        foreach ($this->subscriptions as $subscriptions) {
            foreach ($subscriptions as $subscription) {
                if (!$subscription->route->matches($channel, $event)) {
                    continue;
                }

                if (!$subscription->connection->isConnected()) {
                    $this->removeAll($subscription->connection);

                    break;
                }

                $result = $this->executor->execute(
                    $subscription->query,
                    $subscription->variables,
                    $subscription->operationName,
                    $subscription->context,
                    $payload,
                );

                $subscription->connection->send(Protocol::encode(MessageType::Next, $subscription->id, $result));
            }
        }
    }
}
