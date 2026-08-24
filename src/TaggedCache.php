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

/**
 * Class TaggedCache.
 *
 * Groups cache items under one or more tags so they can be invalidated
 * together, without the storage having to support tags natively.
 *
 * Every tag holds a version token. The tokens of the current tag set are
 * hashed into a namespace that is prepended to each item key. Invalidating a
 * tag simply writes a new token for it: the namespace changes, so all items
 * previously written under the old namespace become unreachable and are
 * dropped by the storage when their TTL expires.
 *
 * @package cache
 */
class TaggedCache
{
    /**
     * The decorated Cache instance.
     *
     * @var Cache
     */
    protected Cache $cache;
    /**
     * The tag names, sorted and deduplicated.
     *
     * @var array<int,string>
     */
    protected array $tags;
    /**
     * The Time To Live of the tag version items, in seconds.
     *
     * Kept at Memcached's 30 days threshold, above which a TTL is read as a
     * Unix timestamp instead of an offset. The TTL is renewed on every write,
     * so tags stay alive while they are in use.
     *
     * @var int
     */
    protected int $tagTtl = 2592000;

    /**
     * TaggedCache constructor.
     *
     * @param Cache $cache The Cache instance to decorate
     * @param array<int,string> $tags List of tag names
     *
     * @throws InvalidArgumentException if $tags is empty or holds an empty tag
     */
    public function __construct(Cache $cache, array $tags)
    {
        $this->cache = $cache;
        $this->setTags($tags);
    }

    /**
     * @param array<int,string> $tags
     *
     * @throws InvalidArgumentException if $tags is empty or holds an empty tag
     *
     * @return static
     */
    protected function setTags(array $tags) : static
    {
        foreach ($tags as $tag) {
            if ($tag === '') {
                throw new InvalidArgumentException('Tag name must not be empty');
            }
        }
        $tags = \array_values(\array_unique($tags));
        if (!$tags) {
            throw new InvalidArgumentException(
                'Tagged cache requires at least one tag'
            );
        }
        \sort($tags);
        $this->tags = $tags;
        return $this;
    }

    /**
     * Get the tag names this instance is bound to.
     *
     * @return array<int,string>
     */
    public function getTags() : array
    {
        return $this->tags;
    }

    /**
     * Get the Time To Live of the tag version items in seconds.
     *
     * @return int
     */
    public function getTagTtl() : int
    {
        return $this->tagTtl;
    }

    /**
     * Set the Time To Live of the tag version items in seconds.
     *
     * @param int $seconds An integer greater than zero
     *
     * @throws InvalidArgumentException if $seconds is lower than 1
     *
     * @return static
     */
    public function setTagTtl(int $seconds) : static
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException(
                'Tag TTL must be greater than 0. ' . $seconds . ' given'
            );
        }
        $this->tagTtl = $seconds;
        return $this;
    }

    /**
     * Gets one tagged item from the cache storage.
     *
     * @param string $key The item name
     *
     * @return mixed The item value or null if not found or invalidated
     */
    public function get(string $key) : mixed
    {
        return $this->cache->get($this->renderKey($key, false));
    }

    /**
     * Gets multi tagged items from the cache storage.
     *
     * @param array<int,string> $keys List of items names to get
     *
     * @return array<string,mixed> associative array with key names and respective values
     */
    public function getMulti(array $keys) : array
    {
        $namespace = $this->getNamespace(false);
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->cache->get($namespace . $key);
        }
        return $values;
    }

    /**
     * Sets one tagged item to the cache storage.
     *
     * @param string $key The item name
     * @param mixed $value The item value
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return bool TRUE if the item was set, FALSE if fail to set
     */
    public function set(string $key, mixed $value, ?int $ttl = null) : bool
    {
        return $this->cache->set($this->renderKey($key, true), $value, $ttl);
    }

    /**
     * Sets multi tagged items to the cache storage.
     *
     * @param array<string,mixed> $data Associative array with key names and respective values
     * @param int|null $ttl The Time To Live for all the items or null to use the default
     *
     * @return array<string,bool> associative array with key names and respective set status
     */
    public function setMulti(array $data, ?int $ttl = null) : array
    {
        $namespace = $this->getNamespace(true);
        $status = [];
        foreach ($data as $key => $value) {
            $status[$key] = $this->cache->set($namespace . $key, $value, $ttl);
        }
        return $status;
    }

    /**
     * Deletes one tagged item from the cache storage.
     *
     * @param string $key the item name
     *
     * @return bool TRUE if the item was deleted, FALSE if fail to delete
     */
    public function delete(string $key) : bool
    {
        return $this->cache->delete($this->renderKey($key, false));
    }

    /**
     * Deletes multi tagged items from the cache storage.
     *
     * @param array<int,string> $keys List of items names to be deleted
     *
     * @return array<string,bool> associative array with key names and respective delete status
     */
    public function deleteMulti(array $keys) : array
    {
        $namespace = $this->getNamespace(false);
        $status = [];
        foreach ($keys as $key) {
            $status[$key] = $this->cache->delete($namespace . $key);
        }
        return $status;
    }

    /**
     * Increments the value of one tagged item.
     *
     * @param string $key The item name
     * @param int $offset The value to increment
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return int The current item value
     */
    public function increment(string $key, int $offset = 1, ?int $ttl = null) : int
    {
        return $this->cache->increment($this->renderKey($key, true), $offset, $ttl);
    }

    /**
     * Decrements the value of one tagged item.
     *
     * @param string $key The item name
     * @param int $offset The value to decrement
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return int The current item value
     */
    public function decrement(string $key, int $offset = 1, ?int $ttl = null) : int
    {
        return $this->cache->decrement($this->renderKey($key, true), $offset, $ttl);
    }

    /**
     * Gets one tagged item from the cache storage, computing and storing it
     * when it is not there yet.
     *
     * ```php
     * $posts = $cache->tags('posts')->remember('list', function () {
     *     return $this->database->select()->from('posts')->run()->fetchArrayAll();
     * }, 300);
     * ```
     *
     * The callback runs only on a miss, which includes the case where the tags
     * were invalidated since the item was written.
     *
     * When the callback returns null nothing is stored, since a null item is
     * indistinguishable from a missing one, and the callback runs again on the
     * next call.
     *
     * @param string $key The item name
     * @param callable $callback Called on a miss to compute the item value.
     * Receives the item name as its only argument
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return mixed The cached value, or the value returned by the callback
     */
    public function remember(string $key, callable $callback, ?int $ttl = null) : mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback($key);
        if ($value !== null) {
            $this->set($key, $value, $ttl);
        }
        return $value;
    }

    /**
     * Gets one tagged item from the cache storage, computing and storing it
     * when it is not there yet.
     *
     * Alias of the remember method.
     *
     * @param string $key The item name
     * @param callable $callback Called on a miss to compute the item value.
     * Receives the item name as its only argument
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return mixed The cached value, or the value returned by the callback
     */
    public function getOrSet(string $key, callable $callback, ?int $ttl = null) : mixed
    {
        return $this->remember($key, $callback, $ttl);
    }

    /**
     * Invalidates every item stored under this instance tags.
     *
     * Items tagged with any of the tags become unreachable at once. Items that
     * carry none of them are left untouched.
     *
     * @return bool TRUE if all tags were invalidated, otherwise FALSE
     */
    public function flush() : bool
    {
        $status = true;
        foreach ($this->tags as $tag) {
            $set = $this->cache->set(
                $this->renderTagKey($tag),
                $this->makeVersion(),
                $this->getTagTtl()
            );
            if (!$set) {
                $status = false;
            }
        }
        return $status;
    }

    /**
     * Make the storage key of a tagged item.
     *
     * @param string $key The item name
     * @param bool $create True to create the missing tag versions, false to
     * resolve them read-only
     *
     * @return string
     */
    protected function renderKey(string $key, bool $create) : string
    {
        return $this->getNamespace($create) . $key;
    }

    /**
     * Make the storage key holding the version of a tag.
     *
     * @param string $tag The tag name
     *
     * @return string
     */
    protected function renderTagKey(string $tag) : string
    {
        return 'tag.version.' . $tag;
    }

    /**
     * Make the key namespace of the current tag set.
     *
     * @param bool $create True to create the missing tag versions, false to
     * resolve them read-only
     *
     * @return string
     */
    protected function getNamespace(bool $create) : string
    {
        $versions = [];
        foreach ($this->tags as $tag) {
            $versions[] = $tag . '=' . $this->getTagVersion($tag, $create);
        }
        $hash = \hash('sha256', \implode('|', $versions));
        return 'tagged.' . \substr($hash, 0, 32) . '.';
    }

    /**
     * Get the current version token of a tag.
     *
     * @param string $tag The tag name
     * @param bool $create True to create the version when it is missing and to
     * renew its TTL when it is present, false to only read it
     *
     * @return string The version token, or an empty string when the tag has no
     * version yet and $create is false
     */
    protected function getTagVersion(string $tag, bool $create) : string
    {
        $tagKey = $this->renderTagKey($tag);
        $version = $this->cache->get($tagKey);
        if (!\is_string($version) || $version === '') {
            if (!$create) {
                return '';
            }
            $version = $this->makeVersion();
        }
        if ($create) {
            $this->cache->set($tagKey, $version, $this->getTagTtl());
        }
        return $version;
    }

    /**
     * Make a new tag version token.
     *
     * @return string
     */
    protected function makeVersion() : string
    {
        return \bin2hex(\random_bytes(8));
    }
}
