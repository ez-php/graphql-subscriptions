<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\WebSocket\ConnectionInterface;

/**
 * Recording ConnectionInterface: keeps sent text messages (JSON-decoded) and
 * the close code/reason, without any socket.
 *
 * @package Tests\Support
 */
final class SubscriptionFakeConnection implements ConnectionInterface
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public ?int $closeCode = null;

    public ?string $closeReason = null;

    private bool $connected = true;

    public function __construct(
        private readonly string $id = 'conn-1',
        private readonly ?string $subprotocol = 'graphql-transport-ws',
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function send(string $data): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        $this->messages[] = $decoded;
    }

    public function sendBinary(string $data): void
    {
    }

    public function close(?string $reason = null): void
    {
        $this->closeWith(1000, $reason);
    }

    public function closeWith(int $code, ?string $reason = null): void
    {
        $this->closeCode = $code;
        $this->closeReason = $reason;
        $this->connected = false;
    }

    public function subprotocol(): ?string
    {
        return $this->subprotocol;
    }

    /**
     * @return array<string, string>
     */
    public function requestHeaders(): array
    {
        return [];
    }

    public function header(string $name): ?string
    {
        return null;
    }

    /**
     * Simulate the peer going away without a close handshake.
     */
    public function disconnect(): void
    {
        $this->connected = false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastMessage(): ?array
    {
        return $this->messages[array_key_last($this->messages) ?? -1] ?? null;
    }
}
