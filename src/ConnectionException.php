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

use RuntimeException;

/**
 * Class ConnectionException.
 *
 * Thrown when a cache driver cannot reach the server it was configured with.
 *
 * The message names the server and the step that failed, and the driver
 * exception that caused it, when there was one, is kept as the previous
 * exception.
 *
 * Extends RuntimeException, so code already catching that keeps working.
 *
 * @package cache
 *
 * @since 4.2
 */
class ConnectionException extends RuntimeException
{
}
