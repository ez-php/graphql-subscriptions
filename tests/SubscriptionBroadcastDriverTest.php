<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Broadcast\Broadcaster;
use EzPhp\Broadcast\Driver\ArrayDriver;
use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\GraphQLSubscriptions\ChannelRoute;
use EzPhp\GraphQLSubscriptions\Protocol;
use EzPhp\GraphQLSubscriptions\Subscription;
use EzPhp\GraphQLSubscriptions\SubscriptionBroadcastDriver;
use EzPhp\GraphQLSubscriptions\SubscriptionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\SubscriptionFakeConnection;
use Tests\Support\SubscriptionTestSchema;

/**
 * Class SubscriptionBroadcastDriverTest
 *
 * @package Tests
 */
#[CoversClass(SubscriptionBroadcastDriver::class)]
#[UsesClass(SubscriptionManager::class)]
#[UsesClass(Subscription::class)]
#[UsesClass(ChannelRoute::class)]
#[UsesClass(Protocol::class)]
final class SubscriptionBroadcastDriverTest extends TestCase
{
    public function testBroadcasterPublishReachesSubscribersAndInnerDriver(): void
    {
        $manager = new SubscriptionManager(new GraphQLExecutor(SubscriptionTestSchema::make()));
        $conn = new SubscriptionFakeConnection();
        $manager->add(new Subscription($conn, 'op-1', 'subscription { orderShipped }', [], null, new ChannelRoute('orders', 'shipped')));
        $inner = new ArrayDriver();

        (new Broadcaster(new SubscriptionBroadcastDriver($manager, $inner)))->to('orders', 'shipped', ['id' => '42']);

        $this->assertSame(['data' => ['orderShipped' => '42']], $conn->lastMessage()['payload'] ?? null);
        $this->assertCount(1, $inner->eventsOn('orders'));
    }

    public function testWorksWithoutInnerDriver(): void
    {
        $manager = new SubscriptionManager(new GraphQLExecutor(SubscriptionTestSchema::make()));
        $conn = new SubscriptionFakeConnection();
        $manager->add(new Subscription($conn, 'op-1', 'subscription { orderShipped }', [], null, new ChannelRoute('orders')));

        (new SubscriptionBroadcastDriver($manager))->publish('orders', 'shipped', ['id' => '7']);

        $this->assertSame(['data' => ['orderShipped' => '7']], $conn->lastMessage()['payload'] ?? null);
    }
}
