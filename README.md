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

## Limitations

- **Same process only.** Events must be published inside the WebSocket server process. Delivery from a separate HTTP process (via Redis Pub/Sub) and an SSE fallback are not implemented yet.
- No connection-init timeout (4408): `ez-php/websocket` has no timers. Unacknowledged connections still cannot subscribe.
- The legacy `subscriptions-transport-ws` (Apollo) protocol is not supported.

## License

MIT
