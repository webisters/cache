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

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

/**
 * Class SimpleCache.
 *
 * A PSR-16 view of any Cache instance, so a third-party library that asks for
 * a Psr\SimpleCache\CacheInterface can be handed this cache.
 *
 * ```php
 * $psr16 = new SimpleCache(new RedisCache($configs));
 * $client = new SomeLibrary($psr16);
 * ```
 *
 * Items are stored where the wrapped cache stores them, with no namespace of
 * their own, so the same item is reachable through both APIs.
 *
 * @package cache
 *
 * @since 4.2
 */
class SimpleCache implements CacheInterface
{
    /**
     * Characters PSR-16 reserves for future use, which a key must not hold.
     */
    protected const RESERVED_CHARACTERS = '{}()/\@:';
    /**
     * Stand-in written in place of a null item.
     *
     * The wrapped cache reports a missing item as null, so a stored null would
     * be indistinguishable from a miss and PSR-16 wants them told apart. A
     * string is used because it survives every serializer, and the null bytes
     * keep it out of reach of anything a caller would store on purpose.
     */
    protected const NULL_STAND_IN = "\0webisters-cache-null\0";

    /**
     * The wrapped Cache instance.
     */
    protected Cache $cache;

    /**
     * SimpleCache constructor.
     *
     * @param Cache $cache The Cache instance to expose as PSR-16
     */
    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Get the wrapped Cache instance.
     *
     * @return Cache
     */
    public function getCache() : Cache
    {
        return $this->cache;
    }

    public function get(string $key, mixed $default = null) : mixed
    {
        $this->validateKey($key);
        $value = $this->cache->get($key);
        if ($value === null) {
            return $default;
        }
        return $value === static::NULL_STAND_IN ? null : $value;
    }

    public function set(string $key, mixed $value, DateInterval | int | null $ttl = null) : bool
    {
        $this->validateKey($key);
        $seconds = $this->makeSeconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            // PSR-16 counts an item given a TTL that has already run out as
            // expired on arrival, so it must not be readable afterwards.
            $this->cache->delete($key);
            return true;
        }
        return $this->cache->set(
            $key,
            $value ?? static::NULL_STAND_IN,
            $seconds
        );
    }

    public function delete(string $key) : bool
    {
        $this->validateKey($key);
        $this->cache->delete($key);
        // PSR-16 asks for true unless the deletion failed, and a key that was
        // never there is not a failure.
        return true;
    }

    public function clear() : bool
    {
        return $this->cache->flush();
    }

    /**
     * @param iterable<mixed,string> $keys
     * @param mixed $default
     *
     * @return array<string,mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null) : iterable
    {
        $keys = $this->validateKeys($keys);
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }
        return $values;
    }

    /**
     * @param iterable<int|string,mixed> $values
     * @param DateInterval|int|null $ttl
     *
     * @return bool
     */
    public function setMultiple(iterable $values, DateInterval | int | null $ttl = null) : bool
    {
        // Pairs rather than an associative array: PHP turns a numeric string
        // array key back into an integer, which would undo the cast below.
        $items = [];
        foreach ($values as $key => $value) {
            if (\is_int($key)) {
                $key = (string) $key;
            }
            if (!\is_string($key)) {
                throw new SimpleCacheInvalidArgumentException(
                    'Cache key must be a string, ' . \get_debug_type($key) . ' given'
                );
            }
            $this->validateKey($key);
            $items[] = [$key, $value];
        }
        $status = true;
        foreach ($items as [$key, $value]) {
            if (!$this->set($key, $value, $ttl)) {
                $status = false;
            }
        }
        return $status;
    }

    /**
     * @param iterable<mixed,string> $keys
     *
     * @return bool
     */
    public function deleteMultiple(iterable $keys) : bool
    {
        $keys = $this->validateKeys($keys);
        $status = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $status = false;
            }
        }
        return $status;
    }

    public function has(string $key) : bool
    {
        $this->validateKey($key);
        return $this->cache->get($key) !== null;
    }

    /**
     * Reject a key PSR-16 does not allow.
     *
     * @param string $key
     *
     * @throws SimpleCacheInvalidArgumentException if the key is empty or holds
     * a reserved character
     */
    protected function validateKey(string $key) : void
    {
        if ($key === '') {
            throw new SimpleCacheInvalidArgumentException(
                'Cache key must not be empty'
            );
        }
        if (\strpbrk($key, static::RESERVED_CHARACTERS) !== false) {
            throw new SimpleCacheInvalidArgumentException(
                'Cache key must not hold any of the reserved characters '
                . static::RESERVED_CHARACTERS . ': ' . $key
            );
        }
    }

    /**
     * Collect and check a list of keys.
     *
     * The whole list is checked before anything is read or deleted, so a bad
     * key later in the list cannot leave the work half done.
     *
     * @param iterable<mixed,string> $keys
     *
     * @throws SimpleCacheInvalidArgumentException if a key is not a valid string
     *
     * @return array<int,string>
     */
    protected function validateKeys(iterable $keys) : array
    {
        $validated = [];
        foreach ($keys as $key) {
            if (!\is_string($key)) {
                throw new SimpleCacheInvalidArgumentException(
                    'Cache key must be a string, ' . \get_debug_type($key) . ' given'
                );
            }
            $this->validateKey($key);
            $validated[] = $key;
        }
        return $validated;
    }

    /**
     * Turn a PSR-16 Time To Live into seconds.
     *
     * @param DateInterval|int|null $ttl
     *
     * @return int|null The seconds, or null to leave the Cache default in place
     */
    protected function makeSeconds(DateInterval | int | null $ttl) : ?int
    {
        if ($ttl === null || \is_int($ttl)) {
            return $ttl;
        }
        $now = new DateTimeImmutable();
        return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
    }
}
