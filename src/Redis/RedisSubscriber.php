<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions\Redis;

use EzPhp\GraphQLSubscriptions\SubscriptionException;

/**
 * Class RedisSubscriber
 *
 * A minimal Redis Pub/Sub consumer speaking RESP over a plain socket — no
 * ext-redis, because phpredis' subscribe() blocks the whole process, while an
 * event loop needs a socket it can stream_select() on. It only PSUBSCRIBEs and
 * reads pushed messages; publishing stays with ez-php/broadcast's RedisDriver.
 *
 * read() waits up to a timeout and returns every complete message received;
 * partial RESP frames stay buffered for the next call. Usable non-blocking
 * (timeout 0, from an event loop) or blocking (an SSE request loop).
 *
 * @package EzPhp\GraphQLSubscriptions\Redis
 */
final class RedisSubscriber
{
    /**
     * @var resource
     */
    private mixed $socket;

    private string $buffer = '';

    /**
     * @param string      $host
     * @param int         $port
     * @param string|null $password       Sent with AUTH when non-null.
     * @param float       $connectTimeout Seconds for the TCP connect and the AUTH reply.
     *
     * @throws SubscriptionException When the connection or AUTH fails.
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        ?string $password = null,
        float $connectTimeout = 2.0,
    ) {
        $errno = 0;
        $errstr = '';
        set_error_handler(static fn (): bool => true);

        try {
            $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $connectTimeout);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw new SubscriptionException("Cannot connect to Redis at {$host}:{$port}: {$errstr} ({$errno})");
        }

        $this->socket = $socket;

        if ($password !== null) {
            $this->command('AUTH', $password);
            $reply = $this->readLine($connectTimeout);

            if (!str_starts_with($reply, '+OK')) {
                $this->close();

                throw new SubscriptionException('Redis AUTH failed: ' . trim($reply));
            }
        }

        stream_set_blocking($this->socket, false);
    }

    /**
     * Subscribe to channel patterns (Redis glob syntax, e.g. `orders.*`; `*` = every channel).
     *
     * @param string ...$patterns
     *
     * @return void
     */
    public function subscribe(string ...$patterns): void
    {
        if ($patterns !== []) {
            $this->command('PSUBSCRIBE', ...$patterns);
        }
    }

    /**
     * The socket, for an event loop's read set.
     *
     * @return resource
     */
    public function stream(): mixed
    {
        return $this->socket;
    }

    /**
     * Wait up to $timeoutSeconds for data, then return every complete pushed message.
     *
     * @param float $timeoutSeconds 0 = do not wait.
     *
     * @throws SubscriptionException When Redis closed the connection or answered with an error.
     *
     * @return list<array{channel: string, message: string}>
     */
    public function read(float $timeoutSeconds = 0.0): array
    {
        if (!is_resource($this->socket)) {
            throw new SubscriptionException('The Redis subscriber connection is closed.');
        }

        $read = [$this->socket];
        $write = null;
        $except = null;
        $seconds = (int) floor($timeoutSeconds);

        $closed = false;

        if (stream_select($read, $write, $except, $seconds, (int) (($timeoutSeconds - $seconds) * 1_000_000)) > 0) {
            while (($chunk = fread($this->socket, 65536)) !== false && $chunk !== '') {
                $this->buffer .= $chunk;
            }

            $closed = feof($this->socket);
        }

        $messages = [];

        while (($frame = $this->parse()) !== null) {
            if (!is_array($frame)) {
                continue;
            }

            if (($frame[0] ?? null) === 'pmessage' && is_string($frame[2] ?? null) && is_string($frame[3] ?? null)) {
                $messages[] = ['channel' => $frame[2], 'message' => $frame[3]];
            } elseif (($frame[0] ?? null) === 'message' && is_string($frame[1] ?? null) && is_string($frame[2] ?? null)) {
                $messages[] = ['channel' => $frame[1], 'message' => $frame[2]];
            }
        }

        // At EOF no more bytes arrive: hand out what was complete, then fail on the next
        // call — a partial frame left in the buffer can never complete, and a closed socket
        // stays readable, so returning [] would let an event loop spin on it.
        if ($closed && $messages === []) {
            throw new SubscriptionException('Redis closed the subscriber connection.');
        }

        return $messages;
    }

    /**
     * Close the connection.
     *
     * @return void
     */
    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * Send one RESP command.
     *
     * @param string ...$parts
     *
     * @return void
     */
    private function command(string ...$parts): void
    {
        $payload = '*' . count($parts) . "\r\n";

        foreach ($parts as $part) {
            $payload .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        fwrite($this->socket, $payload);
    }

    /**
     * Blocking read of one reply line (only used for AUTH, before switching to non-blocking).
     *
     * @param float $timeoutSeconds
     *
     * @return string
     */
    private function readLine(float $timeoutSeconds): string
    {
        stream_set_timeout($this->socket, (int) ceil($timeoutSeconds));
        $line = fgets($this->socket);

        return is_string($line) ? $line : '';
    }

    /**
     * Take one complete RESP value off the buffer; null when it holds no complete value yet.
     *
     * @throws SubscriptionException On a RESP error reply.
     *
     * @return string|int|list<mixed>|false|null false stands for a RESP null.
     */
    private function parse(): string|int|array|false|null
    {
        $offset = 0;
        $value = $this->parseAt($offset);

        if ($value === null) {
            return null;
        }

        $this->buffer = substr($this->buffer, $offset);

        return $value;
    }

    /**
     * @param int $offset Read position; advanced past the value on success.
     *
     * @throws SubscriptionException On a RESP error reply.
     *
     * @return string|int|list<mixed>|false|null
     */
    private function parseAt(int &$offset): string|int|array|false|null
    {
        $end = strpos($this->buffer, "\r\n", $offset);

        if ($end === false) {
            return null;
        }

        $type = $this->buffer[$offset];
        $line = substr($this->buffer, $offset + 1, $end - $offset - 1);
        $next = $end + 2;

        switch ($type) {
            case '+':
                $offset = $next;

                return $line;
            case '-':
                throw new SubscriptionException('Redis error: ' . $line);
            case ':':
                $offset = $next;

                return (int) $line;
            case '$':
                $length = (int) $line;

                if ($length < 0) {
                    $offset = $next;

                    return false;
                }

                if (strlen($this->buffer) < $next + $length + 2) {
                    return null;
                }

                $offset = $next + $length + 2;

                return substr($this->buffer, $next, $length);
            case '*':
                $count = (int) $line;
                $items = [];
                $cursor = $next;

                for ($i = 0; $i < $count; $i++) {
                    $item = $this->parseAt($cursor);

                    if ($item === null) {
                        return null;
                    }

                    $items[] = $item;
                }

                $offset = $cursor;

                return $items;
            default:
                throw new SubscriptionException(sprintf('Unexpected RESP type byte "%s".', $type));
        }
    }
}
