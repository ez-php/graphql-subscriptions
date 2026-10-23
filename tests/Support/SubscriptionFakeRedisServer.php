<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\GraphQLSubscriptions\Redis\RedisSubscriber;
use RuntimeException;

/**
 * A loopback TCP server that plays Redis for RedisSubscriber tests in the same
 * process: accepts one client, records what it sends and writes canned RESP
 * bytes back. (AUTH, whose reply the constructor waits for, needs a separate
 * process — see SubscriptionRedisSubscriberTest.)
 */
final class SubscriptionFakeRedisServer
{
    /** @var resource */
    private mixed $server;

    /** @var resource|null */
    private mixed $client = null;

    public function __construct()
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');

        if (!is_resource($server)) {
            throw new RuntimeException('Cannot open a loopback server.');
        }

        $this->server = $server;
    }

    public function port(): int
    {
        $name = (string) stream_socket_get_name($this->server, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * A subscriber connected to this server, with the server side accepted.
     */
    public function connect(): RedisSubscriber
    {
        $subscriber = new RedisSubscriber('127.0.0.1', $this->port());
        $client = stream_socket_accept($this->server, 2);

        if (!is_resource($client)) {
            throw new RuntimeException('The subscriber did not connect.');
        }

        stream_set_blocking($client, false);
        $this->client = $client;

        return $subscriber;
    }

    public function write(string $bytes): void
    {
        if (is_resource($this->client)) {
            fwrite($this->client, $bytes);
        }
    }

    /**
     * Everything the subscriber sent so far.
     */
    public function received(): string
    {
        usleep(20_000);

        return is_resource($this->client) ? (string) stream_get_contents($this->client) : '';
    }

    /**
     * RESP encoding of a pmessage push for $channel carrying $payload.
     */
    public static function pmessage(string $pattern, string $channel, string $payload): string
    {
        $out = "*4\r\n";

        foreach (['pmessage', $pattern, $channel, $payload] as $part) {
            $out .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        return $out;
    }

    public function closeClient(): void
    {
        if (is_resource($this->client)) {
            fclose($this->client);
        }
    }

    public function close(): void
    {
        $this->closeClient();
        fclose($this->server);
    }
}
