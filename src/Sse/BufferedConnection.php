<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions\Sse;

use EzPhp\GraphQLSubscriptions\Protocol;
use EzPhp\WebSocket\ConnectionInterface;

/**
 * Class BufferedConnection
 *
 * An in-memory ConnectionInterface that lets the SSE endpoint drive
 * GraphQLTransportWsHandler without a socket: protocol messages the handler
 * sends are collected (JSON-decoded) for the endpoint to turn into SSE events,
 * and a close is recorded instead of performed.
 *
 * @internal
 *
 * @package EzPhp\GraphQLSubscriptions\Sse
 */
final class BufferedConnection implements ConnectionInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $outbox = [];

    private ?int $closeCode = null;

    private ?string $closeReason = null;

    /**
     * @param string                $id
     * @param array<string, string> $headers Request headers, lowercase names.
     */
    public function __construct(
        private readonly string $id,
        private readonly array $headers = [],
    ) {
    }

    /**
     * Take every message sent since the last call.
     *
     * @return list<array<string, mixed>>
     */
    public function drain(): array
    {
        $messages = $this->outbox;
        $this->outbox = [];

        return $messages;
    }

    /**
     * The close code the handler chose, or null while open.
     *
     * @return int|null
     */
    public function closeCode(): ?int
    {
        return $this->closeCode;
    }

    /**
     * The close reason the handler gave, or null.
     *
     * @return string|null
     */
    public function closeReason(): ?string
    {
        return $this->closeReason;
    }

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * {@inheritDoc}
     */
    public function isConnected(): bool
    {
        return $this->closeCode === null;
    }

    /**
     * {@inheritDoc}
     */
    public function send(string $data): void
    {
        $decoded = json_decode($data, true);

        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            $this->outbox[] = $decoded;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function sendBinary(string $data): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function close(?string $reason = null): void
    {
        $this->closeWith(1000, $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function closeWith(int $code, ?string $reason = null): void
    {
        $this->closeCode ??= $code;
        $this->closeReason ??= $reason;
    }

    /**
     * {@inheritDoc}
     */
    public function subprotocol(): string
    {
        return Protocol::SUBPROTOCOL;
    }

    /**
     * {@inheritDoc}
     */
    public function requestHeaders(): array
    {
        return $this->headers;
    }

    /**
     * {@inheritDoc}
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
