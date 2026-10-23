<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\GraphQL\SchemaBuilder;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * Schema fixture shared by the subscription tests.
 *
 *   Query        { hello }
 *   Mutation     { shout(text) }
 *   Subscription { orderUpdated(id: ID!), orderShipped, whoAmI, broken }
 *
 * Subscription resolvers read the broadcast payload (root value).
 *
 * @package Tests\Support
 */
final class SubscriptionTestSchema
{
    public static function make(): Schema
    {
        return SchemaBuilder::create()
            ->query([
                'hello' => ['type' => Type::string(), 'resolve' => fn (): string => 'world'],
            ])
            ->mutation([
                'shout' => [
                    'type' => Type::string(),
                    'args' => ['text' => ['type' => Type::nonNull(Type::string())]],
                    'resolve' => fn (mixed $root, array $args): string => strtoupper(is_string($args['text']) ? $args['text'] : ''),
                ],
            ])
            ->subscription([
                'orderUpdated' => [
                    'type' => Type::string(),
                    'args' => ['id' => ['type' => Type::nonNull(Type::id())]],
                    'resolve' => fn (mixed $root): mixed => is_array($root) ? ($root['status'] ?? null) : null,
                ],
                'orderShipped' => [
                    'type' => Type::string(),
                    'resolve' => fn (mixed $root): mixed => is_array($root) ? ($root['id'] ?? null) : null,
                ],
                'whoAmI' => [
                    'type' => Type::string(),
                    'resolve' => fn (mixed $root, array $args, mixed $context): mixed => $context,
                ],
                'broken' => [
                    'type' => Type::string(),
                    'resolve' => function (): never {
                        throw new \RuntimeException('resolver failed');
                    },
                ],
            ])
            ->build();
    }
}
