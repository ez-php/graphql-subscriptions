<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions;

use Closure;
use EzPhp\GraphQL\GraphQLExecutor;
use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use GraphQL\Executor\Values;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;

/**
 * Class GraphQLTransportWsHandler
 *
 * ez-php/websocket handler speaking the graphql-transport-ws protocol
 * (https://github.com/enisdenjo/graphql-ws/blob/master/PROTOCOL.md), as used
 * by the `graphql-ws` client library:
 *
 *   - `connection_init` → optional `$authorize` check → `connection_ack`
 *   - `subscribe` with a subscription operation → validated, routed to a
 *     broadcast channel via SubscriptionRegistry and registered with the
 *     SubscriptionManager; results arrive as `next` messages per event
 *   - `subscribe` with a query/mutation → executed once: `next` + `complete`
 *   - `complete` → subscription removed; `ping` → `pong`
 *
 * Protocol violations close the socket with the protocol's 44xx codes.
 *
 *   $server = new Server('0.0.0.0', 8080, subprotocols: [Protocol::SUBPROTOCOL]);
 *   $server->run(new GraphQLTransportWsHandler($schema, $executor, $registry, $manager));
 *
 * @package EzPhp\GraphQLSubscriptions
 */
final class GraphQLTransportWsHandler implements HandlerInterface
{
    /**
     * @var array<string, true> connection ids that sent connection_init
     */
    private array $initialised = [];

    /**
     * @var array<string, mixed> connection id => resolver context, for acknowledged connections
     */
    private array $contexts = [];

    /**
     * @param Schema                                                    $schema    The schema the executor runs against (subscription field lookup).
     * @param GraphQLExecutor                                           $executor  Validates and executes operations.
     * @param SubscriptionRegistry                                      $registry  Subscription field → broadcast channel.
     * @param SubscriptionManager                                       $manager   Active subscriptions; fed by SubscriptionBroadcastDriver.
     * @param (Closure(ConnectionInterface, array<string, mixed>): bool)|null  $authorize Receives the connection and the connection_init payload;
     *                                                                             false closes with 4403. Null accepts everyone.
     * @param (Closure(ConnectionInterface, array<string, mixed>): mixed)|null $context   Builds the resolver context (third resolver argument)
     *                                                                             once per connection. Null passes no context.
     */
    public function __construct(
        private readonly Schema $schema,
        private readonly GraphQLExecutor $executor,
        private readonly SubscriptionRegistry $registry,
        private readonly SubscriptionManager $manager,
        private readonly ?Closure $authorize = null,
        private readonly ?Closure $context = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        if ($conn->subprotocol() !== Protocol::SUBPROTOCOL) {
            $conn->closeWith(Protocol::CLOSE_SUBPROTOCOL_NOT_ACCEPTABLE, 'Subprotocol not acceptable');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function onMessage(ConnectionInterface $conn, Frame $frame): void
    {
        $message = json_decode($frame->payload, true);
        $type = is_array($message) && is_string($message['type'] ?? null) ? MessageType::tryFrom($message['type']) : null;

        if (!is_array($message) || $type === null) {
            $conn->closeWith(Protocol::CLOSE_INVALID_MESSAGE, 'Invalid message received');

            return;
        }

        /** @var array<string, mixed> $message */
        match ($type) {
            MessageType::ConnectionInit => $this->init($conn, $message),
            MessageType::Ping => $conn->send(Protocol::encode(MessageType::Pong)),
            MessageType::Pong => null,
            MessageType::Subscribe => $this->subscribe($conn, $message),
            MessageType::Complete => $this->complete($conn, $message),
            default => $conn->closeWith(Protocol::CLOSE_INVALID_MESSAGE, 'Invalid message received'),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function onClose(ConnectionInterface $conn): void
    {
        $this->manager->removeAll($conn);
        unset($this->initialised[$conn->id()], $this->contexts[$conn->id()]);
    }

    /**
     * {@inheritDoc}
     */
    public function onError(ConnectionInterface $conn, \Throwable $e): void
    {
        // Transport errors are the server's concern; subscriptions are dropped in onClose().
    }

    /**
     * @param ConnectionInterface  $conn
     * @param array<string, mixed> $message
     *
     * @return void
     */
    private function init(ConnectionInterface $conn, array $message): void
    {
        if (isset($this->initialised[$conn->id()])) {
            $conn->closeWith(Protocol::CLOSE_TOO_MANY_INIT_REQUESTS, 'Too many initialisation requests');

            return;
        }

        $this->initialised[$conn->id()] = true;

        $payload = $message['payload'] ?? [];

        if (!is_array($payload)) {
            $conn->closeWith(Protocol::CLOSE_INVALID_MESSAGE, 'Invalid message received');

            return;
        }

        /** @var array<string, mixed> $payload */
        if ($this->authorize !== null && ($this->authorize)($conn, $payload) !== true) {
            $conn->closeWith(Protocol::CLOSE_FORBIDDEN, 'Forbidden');

            return;
        }

        $this->contexts[$conn->id()] = $this->context !== null ? ($this->context)($conn, $payload) : null;

        $conn->send(Protocol::encode(MessageType::ConnectionAck));
    }

    /**
     * @param ConnectionInterface  $conn
     * @param array<string, mixed> $message
     *
     * @return void
     */
    private function subscribe(ConnectionInterface $conn, array $message): void
    {
        if (!array_key_exists($conn->id(), $this->contexts)) {
            $conn->closeWith(Protocol::CLOSE_UNAUTHORIZED, 'Unauthorized');

            return;
        }

        $id = $message['id'] ?? null;
        $payload = $message['payload'] ?? null;
        $query = is_array($payload) ? ($payload['query'] ?? null) : null;
        $variables = is_array($payload) ? ($payload['variables'] ?? []) : null;
        $operationName = is_array($payload) ? ($payload['operationName'] ?? null) : null;

        if (!is_string($id) || $id === '' || !is_string($query) || !is_array($variables) || ($operationName !== null && !is_string($operationName))) {
            $conn->closeWith(Protocol::CLOSE_INVALID_MESSAGE, 'Invalid message received');

            return;
        }

        if ($this->manager->has($conn, $id)) {
            $conn->closeWith(Protocol::CLOSE_SUBSCRIBER_EXISTS, sprintf('Subscriber for %s already exists', $id));

            return;
        }

        /** @var array<string, mixed> $variables */
        $errors = $this->executor->validate($query);

        if ($errors !== []) {
            $conn->send(Protocol::encode(MessageType::Error, $id, $errors));

            return;
        }

        $context = $this->contexts[$conn->id()];
        $operation = AST::getOperationAST(Parser::parse($query), $operationName);

        if ($operation === null) {
            $conn->send(Protocol::encode(MessageType::Error, $id, [['message' => 'Unknown or ambiguous operation.']]));

            return;
        }

        if ($operation->operation !== 'subscription') {
            $conn->send(Protocol::encode(MessageType::Next, $id, $this->executor->execute($query, $variables, $operationName, $context)));
            $conn->send(Protocol::encode(MessageType::Complete, $id));

            return;
        }

        try {
            $route = $this->route($operation, $variables);
        } catch (SubscriptionException $e) {
            $conn->send(Protocol::encode(MessageType::Error, $id, [['message' => $e->getMessage()]]));

            return;
        }

        $this->manager->add(new Subscription($conn, $id, $query, $variables, $operationName, $route, $context));
    }

    /**
     * Resolve the broadcast route of a validated subscription operation from
     * its single root field and that field's coerced arguments.
     *
     * @param OperationDefinitionNode $operation
     * @param array<string, mixed>    $variables
     *
     * @throws SubscriptionException When the root selection is not a routable field or variables do not coerce.
     *
     * @return ChannelRoute
     */
    private function route(OperationDefinitionNode $operation, array $variables): ChannelRoute
    {
        $fieldNode = $operation->selectionSet->selections[0] ?? null;
        $subscriptionType = $this->schema->getSubscriptionType();

        if (!$fieldNode instanceof FieldNode || $subscriptionType === null || !$subscriptionType->hasField($fieldNode->name->value)) {
            throw new SubscriptionException('The subscription root selection must be a Subscription field.');
        }

        [$variableErrors, $coerced] = Values::getVariableValues($this->schema, $operation->variableDefinitions, $variables);

        if ($variableErrors !== null || $coerced === null) {
            throw new SubscriptionException('Subscription variables are invalid.');
        }

        $field = $fieldNode->name->value;

        return $this->registry->resolve(
            $field,
            Values::getArgumentValues($subscriptionType->getField($field), $fieldNode, $coerced),
        );
    }

    /**
     * @param ConnectionInterface  $conn
     * @param array<string, mixed> $message
     *
     * @return void
     */
    private function complete(ConnectionInterface $conn, array $message): void
    {
        $id = $message['id'] ?? null;

        if (!is_string($id)) {
            $conn->closeWith(Protocol::CLOSE_INVALID_MESSAGE, 'Invalid message received');

            return;
        }

        $this->manager->remove($conn, $id);
    }
}
