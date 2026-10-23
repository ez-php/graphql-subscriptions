<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions\Sse;

use Closure;
use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\GraphQLSubscriptions\GraphQLTransportWsHandler;
use EzPhp\GraphQLSubscriptions\Redis\RedisBroadcastRelay;
use EzPhp\GraphQLSubscriptions\Redis\RedisSubscriber;
use EzPhp\GraphQLSubscriptions\SubscriptionManager;
use EzPhp\GraphQLSubscriptions\SubscriptionRegistry;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Sse\SseEvent;
use EzPhp\Http\StreamedResponse;
use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\Opcode;
use GraphQL\Type\Schema;

/**
 * Class SseSubscriptionEndpoint
 *
 * SSE fallback for clients that cannot open a WebSocket: one HTTP request runs
 * one GraphQL operation and streams its results as server-sent events.
 *
 * The request carries `query`, optional `variables` (JSON) and `operationName`,
 * as query parameters (GET) or a JSON body (POST). The endpoint drives the same
 * GraphQLTransportWsHandler over an in-memory connection, so validation,
 * routing, authorization (`$authorize` receives `['authorization' => <header>]`
 * as its init payload) and the context closure behave exactly as on the
 * WebSocket. Events arrive from other processes through Redis Pub/Sub —
 * ez-php/broadcast's RedisDriver format — read by a RedisSubscriber per request.
 *
 * SSE events: `next` (data = execution result), `error` (data = GraphQL errors
 * or `{"message": …, "code": …}`), `complete`, and `ping` every
 * `$heartbeatSeconds` without traffic (keeps proxies from closing the stream).
 * A query or mutation yields one `next` and `complete`. The stream ends after
 * `$maxSeconds` (0 = until the client disconnects); clients reconnect.
 *
 * @package EzPhp\GraphQLSubscriptions\Sse
 */
final readonly class SseSubscriptionEndpoint
{
    /**
     * @param Schema                                                     $schema
     * @param GraphQLExecutor                                            $executor
     * @param SubscriptionRegistry                                       $registry
     * @param Closure(): RedisSubscriber                                 $subscribers      Opens one subscriber per streaming request.
     * @param (Closure(ConnectionInterface, array<string, mixed>): bool)|null  $authorize Same contract as on GraphQLTransportWsHandler.
     * @param (Closure(ConnectionInterface, array<string, mixed>): mixed)|null $context   Same contract as on GraphQLTransportWsHandler.
     * @param int                                                        $heartbeatSeconds Seconds without events before a `ping` (≥ 1).
     * @param int                                                        $maxSeconds       Stream lifetime; 0 = unlimited.
     */
    public function __construct(
        private Schema $schema,
        private GraphQLExecutor $executor,
        private SubscriptionRegistry $registry,
        private Closure $subscribers,
        private ?Closure $authorize = null,
        private ?Closure $context = null,
        private int $heartbeatSeconds = 15,
        private int $maxSeconds = 0,
    ) {
    }

    /**
     * Answer the request with an SSE stream (usable directly as a route handler).
     *
     * @param RequestInterface $request
     *
     * @return StreamedResponse
     */
    public function __invoke(RequestInterface $request): StreamedResponse
    {
        $operation = self::operation($request);
        $authorization = $request->header('authorization');

        return StreamedResponse::sse(fn (): \Generator => $this->events(
            $operation,
            is_string($authorization) ? ['authorization' => $authorization] : [],
        ));
    }

    /**
     * The SSE events of one operation.
     *
     * @param array<string, mixed> $operation  `query`, `variables`, `operationName`.
     * @param array<string, mixed> $initPayload connection_init payload handed to `$authorize`/`$context`.
     *
     * @return \Generator<int, SseEvent>
     */
    public function events(array $operation, array $initPayload = []): \Generator
    {
        $manager = new SubscriptionManager($this->executor);
        $handler = new GraphQLTransportWsHandler($this->schema, $this->executor, $this->registry, $manager, $this->authorize, $this->context);
        $conn = new BufferedConnection(bin2hex(random_bytes(8)));

        $handler->onOpen($conn);
        $send = static function (array $message) use ($handler, $conn): void {
            $handler->onMessage($conn, new Frame(Opcode::TEXT, json_encode($message, JSON_THROW_ON_ERROR)));
        };

        $send(['type' => 'connection_init', 'payload' => $initPayload === [] ? new \stdClass() : $initPayload]);
        $send(['type' => 'subscribe', 'id' => 'sse', 'payload' => $operation]);

        $done = false;

        foreach ($this->translate($conn, $done) as $event) {
            yield $event;
        }

        if ($done || $manager->count() === 0) {
            return;
        }

        $subscriber = ($this->subscribers)();
        $subscriber->subscribe('*');
        $deadline = $this->maxSeconds > 0 ? microtime(true) + $this->maxSeconds : null;

        try {
            while ($deadline === null || microtime(true) < $deadline) {
                $wait = (float) max(1, $this->heartbeatSeconds);

                if ($deadline !== null) {
                    $wait = min($wait, max(0.0, $deadline - microtime(true)));
                }

                $messages = $subscriber->read($wait);

                if ($messages === []) {
                    yield new SseEvent('{}', 'ping');

                    continue;
                }

                foreach ($messages as $message) {
                    $decoded = RedisBroadcastRelay::decode($message['message']);

                    if ($decoded !== null) {
                        $manager->publish($message['channel'], $decoded[0], $decoded[1]);
                    }
                }

                foreach ($this->translate($conn, $done) as $event) {
                    yield $event;
                }

                if ($done) {
                    return;
                }
            }

            yield new SseEvent('{}', 'complete');
        } finally {
            $subscriber->close();
            $handler->onClose($conn);
        }
    }

    /**
     * Turn the handler's protocol messages (and a close) into SSE events.
     *
     * @param BufferedConnection $conn
     * @param bool               $done Set when the stream must end (error, complete, close).
     *
     * @return list<SseEvent>
     */
    private function translate(BufferedConnection $conn, bool &$done): array
    {
        $events = [];

        foreach ($conn->drain() as $message) {
            $type = $message['type'] ?? null;
            $payload = json_encode($message['payload'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $payload = $payload !== false ? $payload : '{}';

            if ($type === 'next') {
                $events[] = new SseEvent($payload, 'next');
            } elseif ($type === 'error') {
                $events[] = new SseEvent($payload, 'error');
                $done = true;
            } elseif ($type === 'complete') {
                $events[] = new SseEvent('{}', 'complete');
                $done = true;
            }
        }

        if ($conn->closeCode() !== null) {
            $error = json_encode(['message' => $conn->closeReason() ?? 'Closed', 'code' => $conn->closeCode()]);
            $events[] = new SseEvent($error !== false ? $error : '{}', 'error');
            $done = true;
        }

        return $events;
    }

    /**
     * `query`, `variables` and `operationName` from the query string (GET) or a JSON body.
     *
     * @param RequestInterface $request
     *
     * @return array<string, mixed>
     */
    private static function operation(RequestInterface $request): array
    {
        if ($request->method() !== 'GET') {
            $body = json_decode($request->rawBody(), true);

            /** @var array<string, mixed> */
            return is_array($body) ? $body : [];
        }

        $variables = $request->query('variables');
        $decoded = is_string($variables) ? json_decode($variables, true) : null;

        return array_filter([
            'query' => $request->query('query'),
            'variables' => is_array($decoded) ? $decoded : [],
            'operationName' => $request->query('operationName'),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
