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

use APCuIterator;
use RuntimeException;

/**
 * Class ApcuCache.
 *
 * @package cache
 *
 * @since 4.3
 */
class ApcuCache extends Cache
{
    protected function initialize() : void
    {
        if (!apcu_enabled()) {
            throw new RuntimeException('APCu extension is not enabled');
        }
    }

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
        $key = apcu_fetch($this->renderKey($key), $success);
        return $success
            ? $this->unserialize($key)
            : null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null) : bool
    {
        if ($this->isExpiredTtl($ttl)) {
            // Already expired, so there is nothing worth storing, and any
            // item under that name is now stale.
            $this->delete($key);
            return true;
        }
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugSet(
                $key,
                $ttl,
                $start,
                $value,
                apcu_store(
                    $this->renderKey($key),
                    $this->serialize($value),
                    $this->makeTtl($ttl)
                )
            );
        }
        return apcu_store(
            $this->renderKey($key),
            $this->serialize($value),
            $this->makeTtl($ttl)
        );
    }

    public function add(string $key, mixed $value, ?int $ttl = null) : bool
    {
        if ($this->isExpiredTtl($ttl)) {
            // Already expired, so nothing is added. An item already under
            // that name is left alone, as add never overwrites.
            return false;
        }
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugSet(
                $key,
                $ttl,
                $start,
                $value,
                apcu_add(
                    $this->renderKey($key),
                    $this->serialize($value),
                    $this->makeTtl($ttl)
                )
            );
        }
        return apcu_add(
            $this->renderKey($key),
            $this->serialize($value),
            $this->makeTtl($ttl)
        );
    }

    public function delete(string $key) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugDelete(
                $key,
                $start,
                apcu_delete($this->renderKey($key))
            );
        }
        return apcu_delete($this->renderKey($key));
    }

    /**
     * Removes this cache's items from the storage.
     *
     * With a prefix set, only the entries carrying it are removed, found with
     * an APCuIterator over the shared memory cache. APCu sets no key length
     * limit, so this driver never digests a key and every entry it wrote
     * really does start with the prefix.
     *
     * Without a prefix the entries cannot be told apart from those of anything
     * else sharing the APCu cache, which on a web server is every application
     * in the same process pool, so the whole cache is cleared.
     *
     * @see ApcuCache::flushStorage()
     *
     * @return bool
     */
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
        if (!$this->hasPrefix()) {
            return apcu_clear_cache();
        }
        return apcu_delete(
            new APCuIterator('/^' . \preg_quote((string) $this->prefix, '/') . '/')
        ) !== false;
    }

    /**
     * Removes every entry from the APCu cache, whatever its prefix.
     *
     * APCu is shared by every process in the pool, so this takes the items of
     * other applications running beside this one as well.
     *
     * @since 4.4
     * @see ApcuCache::flush()
     *
     * @return bool
     */
    public function flushStorage() : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugFlush($start, apcu_clear_cache());
        }
        return apcu_clear_cache();
    }
}
