<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\GraphQLSubscriptions\ChannelRoute;
use EzPhp\GraphQLSubscriptions\SubscriptionException;
use EzPhp\GraphQLSubscriptions\SubscriptionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class SubscriptionRegistryTest
 *
 * @package Tests
 */
#[CoversClass(SubscriptionRegistry::class)]
#[CoversClass(ChannelRoute::class)]
#[CoversClass(SubscriptionException::class)]
final class SubscriptionRegistryTest extends TestCase
{
    public function testFixedChannelWithEventFilter(): void
    {
        $route = (new SubscriptionRegistry())->channel('orderShipped', 'orders', 'shipped')->resolve('orderShipped', []);

        $this->assertSame('orders', $route->channel);
        $this->assertSame('shipped', $route->event);
        $this->assertTrue($route->matches('orders', 'shipped'));
        $this->assertFalse($route->matches('orders', 'created'));
        $this->assertFalse($route->matches('invoices', 'shipped'));
    }

    public function testClosureChannelReceivesArguments(): void
    {
        $registry = (new SubscriptionRegistry())
            ->channel('orderUpdated', fn (array $args): string => 'orders.' . (is_string($args['id']) ? $args['id'] : ''));

        $route = $registry->resolve('orderUpdated', ['id' => '42']);

        $this->assertSame('orders.42', $route->channel);
        $this->assertNull($route->event);
        $this->assertTrue($route->matches('orders.42', 'anything'));
    }

    public function testHasReportsRegisteredFields(): void
    {
        $registry = (new SubscriptionRegistry())->channel('orderShipped', 'orders');

        $this->assertTrue($registry->has('orderShipped'));
        $this->assertFalse($registry->has('orderUpdated'));
    }

    public function testResolveThrowsForUnregisteredField(): void
    {
        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('"orderUpdated" has no broadcast channel');

        (new SubscriptionRegistry())->resolve('orderUpdated', []);
    }
}
