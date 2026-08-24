<?php declare(strict_types=1);
/*
 * This file is part of Webisters Cache Library.
 *
 * (c) Hafiz Muhammad Moaz <thewebisters@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Framework\Cache;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Class SimpleCacheInvalidArgumentException.
 *
 * Thrown by SimpleCache for a key or an argument PSR-16 does not allow.
 *
 * Extends the SPL InvalidArgumentException so existing catches keep working,
 * and implements the PSR one, which in PSR-16 extends CacheException, so a
 * consumer catching either interface sees it.
 *
 * @package cache
 *
 * @since 4.2
 */
class SimpleCacheInvalidArgumentException extends InvalidArgumentException implements PsrInvalidArgumentException
{
}
