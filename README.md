# ez-php/graphql-subscriptions

GraphQL subscriptions for ez-php. Runs the [`graphql-transport-ws`](https://github.com/enisdenjo/graphql-ws/blob/master/PROTOCOL.md) protocol (the one the `graphql-ws` client speaks) on `ez-php/websocket`, and feeds subscriptions from `ez-php/broadcast` events.

## Installation

```bash
composer require ez-php/graphql-subscriptions
```

## Usage

The WebSocket server is a long-running CLI process, e.g. `bin/graphql-ws.php`:

```php
use EzPhp\Broadcast\Broadcast;
use EzPhp\Broadcast\Broadcaster;
use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\GraphQL\SchemaBuilder;
use EzPhp\GraphQLSubscriptions\GraphQLTransportWsHandler;
use EzPhp\GraphQLSubscriptions\Protocol;
use EzPhp\GraphQLSubscriptions\SubscriptionBroadcastDriver;
use EzPhp\GraphQLSubscriptions\SubscriptionManager;
use EzPhp\GraphQLSubscriptions\SubscriptionRegistry;
use EzPhp\WebSocket\Server;
use GraphQL\Type\Definition\Type;

$schema = SchemaBuilder::create()
    ->query(['order' => [/* ... */]])
    ->mutation([
        'shipOrder' => [
            'type' => Type::string(),
            'args' => ['id' => ['type' => Type::nonNull(Type::id())]],
            'resolve' => function ($root, array $args): string {
                // ... persist ...
                Broadcast::to('orders.' . $args['id'], 'updated', ['status' => 'shipped']);

                return 'shipped';
            },
        ],
    ])
    ->subscription([
        'orderUpdated' => [
            'type' => Type::string(),
            'args' => ['id' => ['type' => Type::nonNull(Type::id())]],
            // the broadcast payload arrives as the root value
            'resolve' => fn (array $payload): string => $payload['status'],
        ],
    ])
    ->build();

$executor = new GraphQLExecutor($schema);
$registry = (new SubscriptionRegistry())
    ->channel('orderUpdated', fn (array $args): string => 'orders.' . $args['id']);
$manager = new SubscriptionManager($executor);

// Publishes in this process now reach the matching subscriptions.
Broadcast::setBroadcaster(new Broadcaster(new SubscriptionBroadcastDriver($manager)));

(new Server('0.0.0.0', 8080, subprotocols: [Protocol::SUBPROTOCOL]))
    ->run(new GraphQLTransportWsHandler($schema, $executor, $registry, $manager));
```

The `subprotocols` argument matters: browsers abort a handshake whose requested subprotocol isn't echoed back, and the handler closes connections that didn't negotiate `graphql-transport-ws` (4406).

Client side, with [`graphql-ws`](https://github.com/enisdenjo/graphql-ws):

```js
const client = createClient({ url: 'ws://localhost:8080' });
client.subscribe({ query: 'subscription { orderUpdated(id: "42") }' }, { next: console.log, error: console.error, complete: () => {} });
```

### Channels

`SubscriptionRegistry::channel($field, $channel, $event = null)` maps a Subscription field to a broadcast channel — a fixed name, or a closure that receives the field's coerced arguments. `$event` restricts delivery to one event name on that channel.

### Authorization and context

```php
new GraphQLTransportWsHandler(
    $schema, $executor, $registry, $manager,
    authorize: fn (ConnectionInterface $conn, array $initPayload): bool => verifyToken($initPayload['token'] ?? ''),
    context: fn (ConnectionInterface $conn, array $initPayload): mixed => userFromToken($initPayload['token'] ?? ''),
);
```

`authorize` returning anything but `true` closes with 4403. The `context` value reaches every resolver of that connection as its third argument.

Pass `maxSubscriptionsPerConnection: 20` (default `null`, unlimited) to cap how many subscriptions one socket may hold; a `subscribe` beyond the cap is answered with an `error` message and the socket stays open.

### Queries and mutations

A `subscribe` message carrying a query or mutation is executed once and answered with `next` + `complete`, so a client can send mutations over the same socket — their `Broadcast::to()` calls then reach subscribers in the same process.

### Events from other processes (Redis)

Your HTTP application and workers publish with `ez-php/broadcast`'s Redis driver as usual; the WebSocket process relays those events to its subscribers:

```php
use EzPhp\GraphQLSubscriptions\Redis\RedisBroadcastRelay;
use EzPhp\GraphQLSubscriptions\Redis\RedisSubscriber;

$server = new Server('0.0.0.0', 8080, subprotocols: [Protocol::SUBPROTOCOL]);
$handler = new GraphQLTransportWsHandler($schema, $executor, $registry, $manager, initTimeoutSeconds: 10);

(new RedisBroadcastRelay(new RedisSubscriber('redis', 6379), $manager))->attach($server->hooks());
$server->hooks()->onTick($handler->tick(...));   // closes connections without connection_init (4408)

$server->run($handler);
```

`RedisSubscriber` speaks Redis' wire protocol over a plain socket (no ext-redis needed); pass a password as third argument when Redis requires `AUTH`. Works the same with `ez-php/websocket-tls`'s `TlsServer`.

### SSE fallback

For clients without WebSockets, `SseSubscriptionEndpoint` streams one operation per HTTP request as server-sent events:

```php
use EzPhp\GraphQLSubscriptions\Sse\SseSubscriptionEndpoint;

$endpoint = new SseSubscriptionEndpoint(
    $schema, $executor, $registry,
    fn (): RedisSubscriber => new RedisSubscriber('redis', 6379),
    authorize: fn ($conn, array $init): bool => verifyToken($init['authorization'] ?? ''),
    heartbeatSeconds: 15,
    maxSeconds: 300,
);
$router->get('/graphql/stream', $endpoint);   // ?query=…&variables={…}  (or POST a JSON body)
```

Events: `next` (the execution result), `error`, `complete`, and `ping` as a keep-alive once `heartbeatSeconds` pass without any other event (traffic on unrelated channels does not count). The `Authorization` header is handed to `authorize`/`context` as the `connection_init` payload, so the same rules apply as on the WebSocket.

## Limitations

- Relayed and SSE delivery need Redis (published through `ez-php/broadcast`'s Redis driver); without it, only events published inside the WebSocket process reach subscribers.
- Each SSE request holds one PHP worker and one Redis connection for its lifetime — size PHP-FPM accordingly, or prefer the WebSocket.
- The legacy `subscriptions-transport-ws` (Apollo) protocol is not supported.

## License

MIT
