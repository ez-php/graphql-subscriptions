<?php

declare(strict_types=1);

namespace EzPhp\GraphQLSubscriptions;

/**
 * Class SubscriptionException
 *
 * Thrown for subscription configuration errors, e.g. a subscription field
 * without a registered broadcast channel.
 *
 * @package EzPhp\GraphQLSubscriptions
 */
final class SubscriptionException extends \RuntimeException
{
}
