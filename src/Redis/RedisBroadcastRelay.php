<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions\Redis;

use EzPhp\GraphQLSubscriptions\SubscriptionManager;
use EzPhp\WebSocket\LoopHooks;

/**
 * Class RedisBroadcastRelay
 *
 * Cross-process delivery: feeds events published by ez-php/broadcast's
 * RedisDriver in another process (the HTTP application, a queue worker) into
 * the WebSocket process's SubscriptionManager. Each Redis message is the
 * driver's JSON `{"event": …, "payload": {…}}` on the broadcast channel.
 *
 *   $relay = new RedisBroadcastRelay(new RedisSubscriber('redis'), $manager);
 *   $relay->attach($server->hooks());   // Server or TlsServer
 *
 * Messages that are not that JSON shape are skipped, so other publishers on the
 * same Redis cannot break the relay.
 *
 * @package EzPhp\GraphQLSubscriptions\Redis
 */
final readonly class RedisBroadcastRelay
{
    /**
     * @param RedisSubscriber     $subscriber
     * @param SubscriptionManager $manager
     * @param list<string>        $patterns Channel patterns to relay (Redis glob syntax); default every channel.
     */
    public function __construct(
        private RedisSubscriber $subscriber,
        private SubscriptionManager $manager,
        private array $patterns = ['*'],
    ) {
    }

    /**
     * Subscribe and register the socket with an event loop.
     *
     * @param LoopHooks $hooks
     *
     * @return void
     */
    public function attach(LoopHooks $hooks): void
    {
        $this->subscriber->subscribe(...$this->patterns);
        $hooks->addReadStream($this->subscriber->stream(), function (): void {
            $this->pump();
        });
    }

    /**
     * Relay every message received so far (non-blocking); returns how many were published.
     *
     * @param float $timeoutSeconds How long to wait for data (0 from an event loop).
     *
     * @return int
     */
    public function pump(float $timeoutSeconds = 0.0): int
    {
        $relayed = 0;

        foreach ($this->subscriber->read($timeoutSeconds) as $message) {
            $event = self::decode($message['message']);

            if ($event === null) {
                continue;
            }

            $this->manager->publish($message['channel'], $event[0], $event[1]);
            $relayed++;
        }

        return $relayed;
    }

    /**
     * Decode ez-php/broadcast's RedisDriver message.
     *
     * @param string $message
     *
     * @return array{string, array<string, mixed>}|null
     */
    public static function decode(string $message): ?array
    {
        $decoded = json_decode($message, true);

        if (!is_array($decoded) || !is_string($decoded['event'] ?? null) || !is_array($decoded['payload'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = $decoded['payload'];

        return [$decoded['event'], $payload];
    }
}
