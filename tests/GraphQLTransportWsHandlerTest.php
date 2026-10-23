<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\GraphQLSubscriptions\ChannelRoute;
use EzPhp\GraphQLSubscriptions\GraphQLTransportWsHandler;
use EzPhp\GraphQLSubscriptions\MessageType;
use EzPhp\GraphQLSubscriptions\Protocol;
use EzPhp\GraphQLSubscriptions\Subscription;
use EzPhp\GraphQLSubscriptions\SubscriptionException;
use EzPhp\GraphQLSubscriptions\SubscriptionManager;
use EzPhp\GraphQLSubscriptions\SubscriptionRegistry;
use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\Opcode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\SubscriptionFakeConnection;
use Tests\Support\SubscriptionTestSchema;

/**
 * Class GraphQLTransportWsHandlerTest
 *
 * @package Tests
 */
#[CoversClass(GraphQLTransportWsHandler::class)]
#[CoversClass(Protocol::class)]
#[CoversClass(MessageType::class)]
#[UsesClass(SubscriptionManager::class)]
#[UsesClass(SubscriptionRegistry::class)]
#[UsesClass(Subscription::class)]
#[UsesClass(ChannelRoute::class)]
#[UsesClass(SubscriptionException::class)]
final class GraphQLTransportWsHandlerTest extends TestCase
{
    private SubscriptionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SubscriptionManager(new GraphQLExecutor(SubscriptionTestSchema::make()));
    }

    /**
     * @param (Closure(ConnectionInterface, array<string, mixed>): bool)|null  $authorize
     * @param (Closure(ConnectionInterface, array<string, mixed>): mixed)|null $context
     */
    private function handler(?Closure $authorize = null, ?Closure $context = null): GraphQLTransportWsHandler
    {
        $schema = SubscriptionTestSchema::make();
        $registry = (new SubscriptionRegistry())
            ->channel('orderShipped', 'orders', 'shipped')
            ->channel('orderUpdated', fn (array $args): string => 'orders.' . (is_string($args['id']) ? $args['id'] : ''))
            ->channel('whoAmI', 'users');

        return new GraphQLTransportWsHandler($schema, new GraphQLExecutor($schema), $registry, $this->manager, $authorize, $context);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function send(GraphQLTransportWsHandler $handler, ConnectionInterface $conn, array $message): void
    {
        $handler->onMessage($conn, new Frame(Opcode::TEXT, json_encode($message, JSON_THROW_ON_ERROR)));
    }

    private function acknowledged(GraphQLTransportWsHandler $handler, SubscriptionFakeConnection $conn): void
    {
        $handler->onOpen($conn);
        $this->send($handler, $conn, ['type' => 'connection_init']);
        $conn->messages = [];
    }

    public function testOpenClosesWhenSubprotocolWasNotNegotiated(): void
    {
        $conn = new SubscriptionFakeConnection('c', null);

        $this->handler()->onOpen($conn);

        $this->assertSame(4406, $conn->closeCode);
    }

    public function testInitIsAcknowledged(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $handler->onOpen($conn);

        $this->send($handler, $conn, ['type' => 'connection_init', 'payload' => ['token' => 'x']]);

        $this->assertNull($conn->closeCode);
        $this->assertSame([['type' => 'connection_ack']], $conn->messages);
    }

    public function testSecondInitClosesWith4429(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $this->acknowledged($handler, $conn);

        $this->send($handler, $conn, ['type' => 'connection_init']);

        $this->assertSame(4429, $conn->closeCode);
    }

    public function testRejectedAuthorizationClosesWith4403(): void
    {
        $seen = null;
        $handler = $this->handler(function (ConnectionInterface $conn, array $payload) use (&$seen): bool {
            $seen = $payload;

            return false;
        });
        $conn = new SubscriptionFakeConnection();

        $this->send($handler, $conn, ['type' => 'connection_init', 'payload' => ['token' => 'bad']]);

        $this->assertSame(['token' => 'bad'], $seen);
        $this->assertSame(4403, $conn->closeCode);
        $this->assertSame([], $conn->messages);
    }

    public function testInvalidMessagesCloseWith4400(): void
    {
        foreach (['not json', '{"no":"type"}', '{"type":"unknown"}', '{"type":"next","id":"1"}'] as $raw) {
            $conn = new SubscriptionFakeConnection();
            $this->handler()->onMessage($conn, new Frame(Opcode::TEXT, $raw));

            $this->assertSame(4400, $conn->closeCode, $raw);
        }
    }

    public function testInitWithNonObjectPayloadClosesWith4400(): void
    {
        $conn = new SubscriptionFakeConnection();

        $this->send($this->handler(), $conn, ['type' => 'connection_init', 'payload' => 'nope']);

        $this->assertSame(4400, $conn->closeCode);
    }

    public function testPingIsAnsweredWithPongAndPongIsIgnored(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();

        $this->send($handler, $conn, ['type' => 'ping']);
        $this->send($handler, $conn, ['type' => 'pong']);

        $this->assertSame([['type' => 'pong']], $conn->messages);
        $this->assertNull($conn->closeCode);
    }

    public function testSubscribeBeforeAckClosesWith4401(): void
    {
        $conn = new SubscriptionFakeConnection();

        $this->send($this->handler(), $conn, ['type' => 'subscribe', 'id' => '1', 'payload' => ['query' => '{ hello }']]);

        $this->assertSame(4401, $conn->closeCode);
    }

    public function testMalformedSubscribeClosesWith4400(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $this->acknowledged($handler, $conn);

        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => '', 'payload' => ['query' => '{ hello }']]);

        $this->assertSame(4400, $conn->closeCode);
    }

    public function testQueryAndMutationAreExecutedOnceThenCompleted(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $this->acknowledged($handler, $conn);

        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 'q', 'payload' => ['query' => '{ hello }']]);
        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 'm', 'payload' => [
            'query' => 'mutation Shout($t: String!) { shout(text: $t) }',
            'variables' => ['t' => 'hi'],
        ]]);

        $this->assertSame([
            ['type' => 'next', 'id' => 'q', 'payload' => ['data' => ['hello' => 'world']]],
            ['type' => 'complete', 'id' => 'q'],
            ['type' => 'next', 'id' => 'm', 'payload' => ['data' => ['shout' => 'HI']]],
            ['type' => 'complete', 'id' => 'm'],
        ], $conn->messages);
        $this->assertSame(0, $this->manager->count());
    }

    public function testSubscriptionReceivesMatchingBroadcastEvents(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $this->acknowledged($handler, $conn);

        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 's1', 'payload' => [
            'query' => 'subscription Order($id: ID!) { orderUpdated(id: $id) }',
            'variables' => ['id' => '42'],
        ]]);

        $this->assertSame([], $conn->messages);
        $this->assertSame(1, $this->manager->count());

        $this->manager->publish('orders.7', 'updated', ['status' => 'ignored']);
        $this->manager->publish('orders.42', 'updated', ['status' => 'packed']);

        $this->assertSame(
            [['type' => 'next', 'id' => 's1', 'payload' => ['data' => ['orderUpdated' => 'packed']]]],
            $conn->messages,
        );
    }

    public function testSubscriptionUsesConnectionContext(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler(context: fn (ConnectionInterface $c, array $payload): mixed => $payload['user'] ?? null);
        $handler->onOpen($conn);
        $this->send($handler, $conn, ['type' => 'connection_init', 'payload' => ['user' => 'alice']]);

        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 's', 'payload' => ['query' => 'subscription { whoAmI }']]);
        $this->manager->publish('users', 'ping', []);

        $this->assertSame(['data' => ['whoAmI' => 'alice']], $conn->lastMessage()['payload'] ?? null);
    }

    public function testDuplicateSubscriptionIdClosesWith4409(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $this->acknowledged($handler, $conn);
        $subscribe = ['type' => 'subscribe', 'id' => 's', 'payload' => ['query' => 'subscription { orderShipped }']];

        $this->send($handler, $conn, $subscribe);
        $this->send($handler, $conn, $subscribe);

        $this->assertSame(4409, $conn->closeCode);
    }

    public function testValidationErrorsAreSentAsErrorMessage(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $this->acknowledged($handler, $conn);

        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 's', 'payload' => ['query' => 'subscription { nope }']]);

        $message = $conn->lastMessage();
        $this->assertSame('error', $message['type'] ?? null);
        $this->assertSame('s', $message['id'] ?? null);
        $this->assertIsArray($message['payload'] ?? null);
        $this->assertNotEmpty($message['payload']);
        $this->assertNull($conn->closeCode);
        $this->assertSame(0, $this->manager->count());
    }

    public function testUnregisteredSubscriptionFieldIsAnError(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $this->acknowledged($handler, $conn);

        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 's', 'payload' => ['query' => 'subscription { broken }']]);

        $this->assertSame(
            ['type' => 'error', 'id' => 's', 'payload' => [['message' => 'Subscription field "broken" has no broadcast channel registered.']]],
            $conn->lastMessage(),
        );
    }

    public function testInvalidVariablesAreAnError(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $this->acknowledged($handler, $conn);

        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 's', 'payload' => [
            'query' => 'subscription Order($id: ID!) { orderUpdated(id: $id) }',
        ]]);

        $this->assertSame(
            ['type' => 'error', 'id' => 's', 'payload' => [['message' => 'Subscription variables are invalid.']]],
            $conn->lastMessage(),
        );
    }

    public function testUnknownOperationNameIsAnError(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $this->acknowledged($handler, $conn);

        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 's', 'payload' => [
            'query' => 'subscription A { orderShipped }',
            'operationName' => 'B',
        ]]);

        $this->assertSame('error', $conn->lastMessage()['type'] ?? null);
    }

    public function testCompleteAndCloseRemoveSubscriptions(): void
    {
        $conn = new SubscriptionFakeConnection();
        $handler = $this->handler();
        $this->acknowledged($handler, $conn);
        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 'a', 'payload' => ['query' => 'subscription { orderShipped }']]);
        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 'b', 'payload' => ['query' => 'subscription { orderShipped }']]);

        $this->send($handler, $conn, ['type' => 'complete', 'id' => 'a']);
        $this->assertSame(1, $this->manager->count());

        $handler->onClose($conn);
        $this->assertSame(0, $this->manager->count());

        // state is reset: the same connection id must initialise again
        $this->send($handler, $conn, ['type' => 'subscribe', 'id' => 'c', 'payload' => ['query' => 'subscription { orderShipped }']]);
        $this->assertSame(4401, $conn->closeCode);
    }

    public function testCompleteWithoutIdClosesWith4400(): void
    {
        $conn = new SubscriptionFakeConnection();

        $this->send($this->handler(), $conn, ['type' => 'complete']);

        $this->assertSame(4400, $conn->closeCode);
    }

    public function testOnErrorIsANoOp(): void
    {
        $conn = new SubscriptionFakeConnection();

        $this->handler()->onError($conn, new \RuntimeException('boom'));

        $this->assertNull($conn->closeCode);
        $this->assertSame(Protocol::SUBPROTOCOL, $conn->subprotocol());
    }
}
