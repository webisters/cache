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

use Override;

/**
 * Class NullCache.
 *
 * Stores nothing and reports success. Every read is a miss.
 *
 * Lets caching be switched off, in development or on a machine with no cache
 * server, without any calling code having to change or guard against a null
 * cache instance.
 *
 * Because nothing is ever stored, a value passed to the remember methods is
 * recomputed on every call, and the locks are always granted, since there is
 * no shared state to coordinate.
 *
 * @package cache
 *
 * @since 4.2
 */
class NullCache extends Cache
{
    public function get(string $key) : mixed
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugGet($key, $start, null);
        }
        return null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugSet($key, $ttl, $start, $value, true);
        }
        return true;
    }

    #[Override]
    public function add(string $key, mixed $value, ?int $ttl = null) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugSet($key, $ttl, $start, $value, true);
        }
        return true;
    }

    public function delete(string $key) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugDelete($key, $start, true);
        }
        return true;
    }

    public function flush() : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugFlush($start, true);
        }
        return true;
    }
}
