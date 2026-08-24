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
 * Class ArrayCache.
 *
 * Keeps items in a PHP array, so the cache lives exactly as long as the
 * request that created it and nothing is shared with any other process.
 *
 * Useful in tests, where it removes the need for a running cache server, and
 * as a request-scoped layer that stops the same value from being fetched twice
 * while a single request is being handled.
 *
 * Values go through the configured serializer just like the persistent
 * drivers do, so code developed against this driver behaves the same once it
 * is pointed at Redis, Memcached, APCu, the files driver or the database.
 *
 * @package cache
 *
 * @since 4.2
 */
class ArrayCache extends Cache
{
    /**
     * The items, keyed by rendered key name.
     *
     * @var array<string,array{value:string,ttl:int}>
     */
    protected array $storage = [];

    public function get(string $key) : mixed
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugGet(
                $key,
                $start,
                $this->getValue($key)
            );
        }
        return $this->getValue($key);
    }

    protected function getValue(string $key) : mixed
    {
        $key = $this->renderKey($key);
        if (!isset($this->storage[$key])) {
            return null;
        }
        if ($this->storage[$key]['ttl'] <= \time()) {
            unset($this->storage[$key]);
            return null;
        }
        return $this->unserialize($this->storage[$key]['value']);
    }

    public function set(string $key, mixed $value, ?int $ttl = null) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugSet(
                $key,
                $ttl,
                $start,
                $value,
                $this->setValue($key, $value, $ttl)
            );
        }
        return $this->setValue($key, $value, $ttl);
    }

    protected function setValue(string $key, mixed $value, ?int $ttl = null) : bool
    {
        $this->storage[$this->renderKey($key)] = [
            'value' => $this->serialize($value),
            'ttl' => \time() + $this->makeTtl($ttl),
        ];
        return true;
    }

    #[Override]
    public function add(string $key, mixed $value, ?int $ttl = null) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugSet(
                $key,
                $ttl,
                $start,
                $value,
                $this->addValue($key, $value, $ttl)
            );
        }
        return $this->addValue($key, $value, $ttl);
    }

    protected function addValue(string $key, mixed $value, ?int $ttl = null) : bool
    {
        $renderedKey = $this->renderKey($key);
        if (isset($this->storage[$renderedKey])
            && $this->storage[$renderedKey]['ttl'] > \time()
        ) {
            return false;
        }
        return $this->setValue($key, $value, $ttl);
    }

    public function delete(string $key) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugDelete(
                $key,
                $start,
                $this->deleteValue($key)
            );
        }
        return $this->deleteValue($key);
    }

    protected function deleteValue(string $key) : bool
    {
        $key = $this->renderKey($key);
        if (!isset($this->storage[$key])) {
            return false;
        }
        unset($this->storage[$key]);
        return true;
    }

    public function flush() : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugFlush(
                $start,
                $this->flushValues()
            );
        }
        return $this->flushValues();
    }

    protected function flushValues() : bool
    {
        $this->storage = [];
        return true;
    }

    /**
     * Garbage collector.
     *
     * Drops every expired item. Expired items are already skipped on read, so
     * this only matters to give their memory back on a long running process.
     *
     * @return bool TRUE, the collection cannot fail
     */
    public function gc() : bool
    {
        $this->purge();
        return true;
    }

    /**
     * Delete every expired item and report how many went.
     *
     * Expired items are already invisible to reads, so this is only about
     * giving their memory back on a long running process.
     *
     * @return int Number of items removed
     */
    public function purge() : int
    {
        $now = \time();
        $purged = 0;
        foreach ($this->storage as $key => $item) {
            if ($item['ttl'] <= $now) {
                unset($this->storage[$key]);
                $purged++;
            }
        }
        return $purged;
    }

    /**
     * Number of items held, expired ones included.
     *
     * @return int
     */
    public function count() : int
    {
        return \count($this->storage);
    }
}
