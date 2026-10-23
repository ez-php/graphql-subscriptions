<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\GraphQLSubscriptions\ChannelRoute;
use EzPhp\GraphQLSubscriptions\Protocol;
use EzPhp\GraphQLSubscriptions\Subscription;
use EzPhp\GraphQLSubscriptions\SubscriptionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\SubscriptionFakeConnection;
use Tests\Support\SubscriptionTestSchema;

/**
 * Class SubscriptionManagerTest
 *
 * @package Tests
 */
#[CoversClass(SubscriptionManager::class)]
#[CoversClass(Subscription::class)]
#[UsesClass(ChannelRoute::class)]
#[UsesClass(Protocol::class)]
final class SubscriptionManagerTest extends TestCase
{
    private SubscriptionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SubscriptionManager(new GraphQLExecutor(SubscriptionTestSchema::make()));
    }

    private function subscription(SubscriptionFakeConnection $conn, string $id, string $query, ChannelRoute $route, mixed $context = null): Subscription
    {
        return new Subscription($conn, $id, $query, [], null, $route, $context);
    }

    public function testPublishExecutesMatchingSubscriptionsWithPayloadAsRoot(): void
    {
        $conn = new SubscriptionFakeConnection();
        $this->manager->add($this->subscription($conn, 'op-1', 'subscription { orderShipped }', new ChannelRoute('orders', 'shipped')));

        $this->manager->publish('orders', 'shipped', ['id' => '42']);

        $this->assertSame(
            [['type' => 'next', 'id' => 'op-1', 'payload' => ['data' => ['orderShipped' => '42']]]],
            $conn->messages,
        );
    }

    public function testPublishSkipsNonMatchingChannelsAndEvents(): void
    {
        $conn = new SubscriptionFakeConnection();
        $this->manager->add($this->subscription($conn, 'op-1', 'subscription { orderShipped }', new ChannelRoute('orders', 'shipped')));

        $this->manager->publish('orders', 'created', ['id' => '1']);
        $this->manager->publish('invoices', 'shipped', ['id' => '2']);

        $this->assertSame([], $conn->messages);
    }

    public function testPublishPassesConnectionContext(): void
    {
        $conn = new SubscriptionFakeConnection();
        $this->manager->add($this->subscription($conn, 'op-1', 'subscription { whoAmI }', new ChannelRoute('users'), 'user-7'));

        $this->manager->publish('users', 'ping', []);

        $this->assertSame(['data' => ['whoAmI' => 'user-7']], $conn->lastMessage()['payload'] ?? null);
    }

    public function testExecutionErrorsAreDeliveredInNextPayloadAndSubscriptionStays(): void
    {
        $conn = new SubscriptionFakeConnection();
        $this->manager->add($this->subscription($conn, 'op-1', 'subscription { broken }', new ChannelRoute('orders')));

        $this->manager->publish('orders', 'x', []);

        $message = $conn->lastMessage();
        $this->assertSame('next', $message['type'] ?? null);
        $this->assertIsArray($message['payload'] ?? null);
        $this->assertArrayHasKey('errors', $message['payload']);
        $this->assertSame(1, $this->manager->count());
    }

    public function testDisconnectedConnectionsAreDroppedOnPublish(): void
    {
        $conn = new SubscriptionFakeConnection();
        $this->manager->add($this->subscription($conn, 'op-1', 'subscription { orderShipped }', new ChannelRoute('orders')));
        $conn->disconnect();

        $this->manager->publish('orders', 'shipped', ['id' => '1']);

        $this->assertSame([], $conn->messages);
        $this->assertSame(0, $this->manager->count());
    }

    public function testRemoveAndRemoveAll(): void
    {
        $a = new SubscriptionFakeConnection('a');
        $b = new SubscriptionFakeConnection('b');
        $this->manager->add($this->subscription($a, 'op-1', 'subscription { orderShipped }', new ChannelRoute('orders')));
        $this->manager->add($this->subscription($a, 'op-2', 'subscription { orderShipped }', new ChannelRoute('orders')));
        $this->manager->add($this->subscription($b, 'op-1', 'subscription { orderShipped }', new ChannelRoute('orders')));

        $this->assertSame(3, $this->manager->count());
        $this->assertTrue($this->manager->has($a, 'op-1'));

        $this->manager->remove($a, 'op-1');
        $this->assertFalse($this->manager->has($a, 'op-1'));
        $this->assertTrue($this->manager->has($b, 'op-1'));

        $this->manager->remove($a, 'op-2');
        $this->manager->removeAll($b);
        $this->assertSame(0, $this->manager->count());
    }
}
