<?php

declare(strict_types=1);

namespace Tests\Redis;

use EzPhp\GraphQLSubscriptions\Redis\RedisSubscriber;
use EzPhp\GraphQLSubscriptions\SubscriptionException;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;
use RedisException;
use Tests\Support\SubscriptionFakeRedisServer;
use Tests\TestCase;

/**
 * RedisSubscriber against a loopback fake (RESP framing, split frames, errors)
 * and, when one answers at REDIS_HOST/REDIS_PORT, a real Redis.
 */
#[CoversClass(RedisSubscriber::class)]
final class SubscriptionRedisSubscriberTest extends TestCase
{
    private SubscriptionFakeRedisServer $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new SubscriptionFakeRedisServer();
    }

    protected function tearDown(): void
    {
        $this->fake->close();
        parent::tearDown();
    }

    public function test_psubscribe_command_and_pushed_messages(): void
    {
        $subscriber = $this->fake->connect();
        $subscriber->subscribe('orders.*', 'users');

        $this->assertSame("*3\r\n\$10\r\nPSUBSCRIBE\r\n\$8\r\norders.*\r\n\$5\r\nusers\r\n", $this->fake->received());

        // Subscription confirmations are skipped; pmessage and message pushes are returned.
        $this->fake->write("*3\r\n\$10\r\npsubscribe\r\n\$8\r\norders.*\r\n:1\r\n");
        $this->fake->write(SubscriptionFakeRedisServer::pmessage('orders.*', 'orders.42', '{"event":"shipped","payload":{}}'));
        $this->fake->write("*3\r\n\$7\r\nmessage\r\n\$5\r\nusers\r\n\$2\r\nhi\r\n");

        $this->assertSame([
            ['channel' => 'orders.42', 'message' => '{"event":"shipped","payload":{}}'],
            ['channel' => 'users', 'message' => 'hi'],
        ], $subscriber->read(1.0));
        $this->assertSame([], $subscriber->read(0.0));
        $this->assertSame('stream', get_resource_type($subscriber->stream()));
    }

    public function test_frames_split_across_reads_are_reassembled(): void
    {
        $subscriber = $this->fake->connect();
        $frame = SubscriptionFakeRedisServer::pmessage('*', 'a', 'payload-text');

        $this->fake->write(substr($frame, 0, 17));
        $this->assertSame([], $subscriber->read(0.5));

        $this->fake->write(substr($frame, 17));
        $this->assertSame([['channel' => 'a', 'message' => 'payload-text']], $subscriber->read(0.5));
    }

    public function test_error_replies_and_closed_connections_throw(): void
    {
        $subscriber = $this->fake->connect();
        $this->fake->write("-ERR unknown command\r\n");

        try {
            $subscriber->read(0.5);
            $this->fail('Expected SubscriptionException.');
        } catch (SubscriptionException $e) {
            $this->assertSame('Redis error: ERR unknown command', $e->getMessage());
        }

        $this->fake->closeClient();
        $this->expectException(SubscriptionException::class);
        $subscriber->read(0.5);
    }

    public function test_unreachable_server_throws(): void
    {
        $port = $this->fake->port();
        $this->fake->close();
        $this->fake = new SubscriptionFakeRedisServer();

        $this->expectException(SubscriptionException::class);
        new RedisSubscriber('127.0.0.1', $port, null, 0.5);
    }

    public function test_auth_is_sent_and_a_rejection_throws(): void
    {
        $this->assertSame('ok', $this->authAgainstChild("+OK\r\n", 'secret'));
        $this->assertStringContainsString('Redis AUTH failed: -WRONGPASS', $this->authAgainstChild("-WRONGPASS invalid\r\n", 'bad'));
    }

    public function test_against_a_real_redis(): void
    {
        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is needed to publish.');
        }

        try {
            $subscriber = new RedisSubscriber($host, $port, null, 1.0);
            $publisher = new Redis();
            $publisher->connect($host, $port, 1.0);
        } catch (SubscriptionException|RedisException) {
            self::markTestSkipped("Redis is not available at {$host}:{$port}.");
        }

        $subscriber->subscribe('ez-gql-test.*');
        $subscriber->read(0.5); // the psubscribe confirmation
        $publisher->publish('ez-gql-test.1', 'hello');

        $this->assertSame([['channel' => 'ez-gql-test.1', 'message' => 'hello']], $subscriber->read(2.0));
        $subscriber->close();
    }

    /**
     * Run a one-shot fake Redis in a child process that answers AUTH with $reply.
     */
    private function authAgainstChild(string $reply, string $password): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($server);

        $code = sprintf(
            '$s = stream_socket_server("tcp://127.0.0.1:%d"); echo "ready\n"; $c = stream_socket_accept($s, 5); '
            . 'fread($c, 1024); fwrite($c, %s); usleep(200000);',
            $port,
            var_export($reply, true),
        );
        $child = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);
        fgets($pipes[1]); // wait until it listens

        try {
            (new RedisSubscriber('127.0.0.1', $port, $password, 2.0))->close();

            return 'ok';
        } catch (SubscriptionException $e) {
            return $e->getMessage();
        } finally {
            fclose($pipes[1]);
            proc_close($child);
        }
    }
}
