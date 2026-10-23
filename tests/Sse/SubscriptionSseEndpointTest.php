<?php

declare(strict_types=1);

namespace Tests\Sse;

use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\GraphQLSubscriptions\ChannelRoute;
use EzPhp\GraphQLSubscriptions\GraphQLTransportWsHandler;
use EzPhp\GraphQLSubscriptions\Protocol;
use EzPhp\GraphQLSubscriptions\Redis\RedisBroadcastRelay;
use EzPhp\GraphQLSubscriptions\Redis\RedisSubscriber;
use EzPhp\GraphQLSubscriptions\Sse\BufferedConnection;
use EzPhp\GraphQLSubscriptions\Sse\SseSubscriptionEndpoint;
use EzPhp\GraphQLSubscriptions\Subscription;
use EzPhp\GraphQLSubscriptions\SubscriptionManager;
use EzPhp\GraphQLSubscriptions\SubscriptionRegistry;
use EzPhp\Http\Request;
use EzPhp\Http\Sse\SseEvent;
use EzPhp\Http\StreamedResponse;
use EzPhp\WebSocket\ConnectionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\SubscriptionFakeRedisServer;
use Tests\Support\SubscriptionTestSchema;
use Tests\TestCase;

/**
 * SseSubscriptionEndpoint: queries answer once, subscriptions stream relayed
 * Redis events, authorization/validation failures end with an error event.
 */
#[CoversClass(SseSubscriptionEndpoint::class)]
#[CoversClass(BufferedConnection::class)]
#[UsesClass(GraphQLTransportWsHandler::class)]
#[UsesClass(SubscriptionManager::class)]
#[UsesClass(SubscriptionRegistry::class)]
#[UsesClass(ChannelRoute::class)]
#[UsesClass(Subscription::class)]
#[UsesClass(Protocol::class)]
#[UsesClass(RedisSubscriber::class)]
#[UsesClass(RedisBroadcastRelay::class)]
final class SubscriptionSseEndpointTest extends TestCase
{
    private ?SubscriptionFakeRedisServer $fake = null;

    protected function tearDown(): void
    {
        $this->fake?->close();
        parent::tearDown();
    }

    /**
     * @param list<string> $pushes RESP bytes the fake Redis sends right after the subscriber connects.
     */
    private function endpoint(array $pushes = [], ?\Closure $authorize = null, ?\Closure $context = null): SseSubscriptionEndpoint
    {
        $schema = SubscriptionTestSchema::make();
        $fake = $this->fake = new SubscriptionFakeRedisServer();

        return new SseSubscriptionEndpoint(
            $schema,
            new GraphQLExecutor($schema),
            (new SubscriptionRegistry())
                ->channel('orderShipped', 'orders', 'shipped')
                ->channel('whoAmI', 'users'),
            static function () use ($fake, $pushes): RedisSubscriber {
                $subscriber = $fake->connect();

                foreach ($pushes as $push) {
                    $fake->write($push);
                }

                return $subscriber;
            },
            $authorize,
            $context,
            heartbeatSeconds: 1,
            maxSeconds: 1,
        );
    }

    /**
     * @param iterable<SseEvent> $events
     *
     * @return list<string>
     */
    private function frames(iterable $events): array
    {
        $out = [];

        foreach ($events as $event) {
            $out[] = $event->toString();
        }

        return $out;
    }

    public function test_a_query_yields_next_and_complete_without_redis(): void
    {
        $frames = $this->frames($this->endpoint()->events(['query' => '{ hello }']));

        $this->assertCount(2, $frames);
        $this->assertStringContainsString("event: next\n", $frames[0]);
        $this->assertStringContainsString('{"data":{"hello":"world"}}', $frames[0]);
        $this->assertStringContainsString("event: complete\n", $frames[1]);
    }

    public function test_a_subscription_streams_relayed_events_until_max_seconds(): void
    {
        $push = SubscriptionFakeRedisServer::pmessage('*', 'orders', '{"event":"shipped","payload":{"id":"A-7"}}');
        $frames = $this->frames($this->endpoint([$push])->events(['query' => 'subscription { orderShipped }']));

        $this->assertStringContainsString('{"data":{"orderShipped":"A-7"}}', $frames[0]);
        $this->assertStringContainsString("event: complete\n", end($frames) ?: '');
    }

    public function test_authorization_and_context_use_the_authorization_header(): void
    {
        $endpoint = $this->endpoint(
            [SubscriptionFakeRedisServer::pmessage('*', 'users', '{"event":"ping","payload":{}}')],
            static fn (ConnectionInterface $c, array $payload): bool => ($payload['authorization'] ?? null) === 'Bearer ok',
            static fn (ConnectionInterface $c, array $payload): mixed => 'alice',
        );

        $denied = $this->frames($endpoint->events(['query' => 'subscription { whoAmI }']));
        $this->assertStringContainsString("event: error\n", $denied[0]);
        $this->assertStringContainsString('"code":4403', $denied[0]);

        $allowed = $this->frames($endpoint->events(['query' => 'subscription { whoAmI }'], ['authorization' => 'Bearer ok']));
        $this->assertStringContainsString('{"data":{"whoAmI":"alice"}}', $allowed[0]);
    }

    public function test_an_invalid_query_ends_with_an_error_event(): void
    {
        $frames = $this->frames($this->endpoint()->events(['query' => '{ nope }']));

        $this->assertCount(1, $frames);
        $this->assertStringContainsString("event: error\n", $frames[0]);
    }

    public function test_invoke_reads_the_operation_from_the_query_string_and_streams_sse(): void
    {
        $request = new Request('GET', '/graphql/stream', query: ['query' => '{ hello }', 'variables' => '{}'], headers: ['Authorization' => 'Bearer x']);

        $response = ($this->endpoint())($request);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('text/event-stream', $response->headers()['Content-Type'] ?? null);
    }

    public function test_invoke_reads_a_json_body_for_post(): void
    {
        $request = new Request('POST', '/graphql/stream', rawBody: '{"query":"{ hello }"}');

        $response = ($this->endpoint())($request);
        $chunks = '';
        $response->writeBody(static function (string $chunk) use (&$chunks): void {
            $chunks .= $chunk;
        });

        $this->assertStringContainsString('{"data":{"hello":"world"}}', $chunks);
    }
}
