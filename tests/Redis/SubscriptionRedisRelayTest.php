<?php

declare(strict_types=1);

namespace Tests\Redis;

use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\GraphQLSubscriptions\ChannelRoute;
use EzPhp\GraphQLSubscriptions\GraphQLTransportWsHandler;
use EzPhp\GraphQLSubscriptions\Protocol;
use EzPhp\GraphQLSubscriptions\Redis\RedisBroadcastRelay;
use EzPhp\GraphQLSubscriptions\Redis\RedisSubscriber;
use EzPhp\GraphQLSubscriptions\Subscription;
use EzPhp\GraphQLSubscriptions\SubscriptionManager;
use EzPhp\GraphQLSubscriptions\SubscriptionRegistry;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\LoopHooks;
use EzPhp\WebSocket\Opcode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\SubscriptionFakeConnection;
use Tests\Support\SubscriptionFakeRedisServer;
use Tests\Support\SubscriptionTestSchema;
use Tests\TestCase;

/**
 * RedisBroadcastRelay: broadcast's RedisDriver JSON from another process reaches
 * the WebSocket subscribers; LoopHooks wiring; foreign messages are ignored.
 */
#[CoversClass(RedisBroadcastRelay::class)]
#[UsesClass(RedisSubscriber::class)]
#[UsesClass(SubscriptionManager::class)]
#[UsesClass(GraphQLTransportWsHandler::class)]
#[UsesClass(SubscriptionRegistry::class)]
#[UsesClass(ChannelRoute::class)]
#[UsesClass(Subscription::class)]
#[UsesClass(Protocol::class)]
final class SubscriptionRedisRelayTest extends TestCase
{
    public function test_relayed_events_reach_subscribers_through_the_loop_hook(): void
    {
        $schema = SubscriptionTestSchema::make();
        $executor = new GraphQLExecutor($schema);
        $manager = new SubscriptionManager($executor);
        $handler = new GraphQLTransportWsHandler($schema, $executor, (new SubscriptionRegistry())->channel('orderShipped', 'orders', 'shipped'), $manager);

        $conn = new SubscriptionFakeConnection();
        $handler->onOpen($conn);
        $handler->onMessage($conn, new Frame(Opcode::TEXT, '{"type":"connection_init"}'));
        $handler->onMessage($conn, new Frame(Opcode::TEXT, '{"type":"subscribe","id":"s","payload":{"query":"subscription { orderShipped }"}}'));
        $conn->messages = [];

        $fake = new SubscriptionFakeRedisServer();
        $relay = new RedisBroadcastRelay($fake->connect(), $manager, ['orders']);
        $hooks = new LoopHooks();
        $relay->attach($hooks);

        $this->assertStringContainsString('PSUBSCRIBE', $fake->received());
        $this->assertCount(1, $hooks->readStreams());

        $fake->write(SubscriptionFakeRedisServer::pmessage('orders', 'orders', '{"event":"shipped","payload":{"id":"A-1"}}'));
        $fake->write(SubscriptionFakeRedisServer::pmessage('orders', 'orders', 'not json'));
        usleep(20_000);
        $hooks->dispatchReadable($hooks->readStreams()[0]);

        $this->assertSame([['type' => 'next', 'id' => 's', 'payload' => ['data' => ['orderShipped' => 'A-1']]]], $conn->messages);
        $fake->close();
    }

    public function test_pump_counts_relayed_messages(): void
    {
        $fake = new SubscriptionFakeRedisServer();
        $relay = new RedisBroadcastRelay($fake->connect(), new SubscriptionManager(new GraphQLExecutor(SubscriptionTestSchema::make())));

        $fake->write(SubscriptionFakeRedisServer::pmessage('*', 'x', '{"event":"e","payload":{}}'));
        $fake->write(SubscriptionFakeRedisServer::pmessage('*', 'x', '{"event":1}'));

        $this->assertSame(1, $relay->pump(0.5));
        $fake->close();
    }

    public function test_decode(): void
    {
        $this->assertSame(['shipped', ['id' => 1]], RedisBroadcastRelay::decode('{"event":"shipped","payload":{"id":1}}'));
        $this->assertNull(RedisBroadcastRelay::decode('{"event":"x"}'));
        $this->assertNull(RedisBroadcastRelay::decode('nope'));
    }
}
