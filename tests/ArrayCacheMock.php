<?php declare(strict_types=1);
/*
 * This file is part of Webisters Cache Library.
 *
 * (c) Hafiz Muhammad Moaz <thewebisters@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Tests\Cache;

use Framework\Cache\Cache;

/**
 * Class ArrayCacheMock.
 *
 * An in-memory Cache used to exercise storage-agnostic behavior without
 * requiring a running cache server.
 *
 * @package cache
 */
class ArrayCacheMock extends Cache
{
    /**
     * @var array<string,array{value:mixed,expires:int,ttl:int}>
     */
    protected array $storage = [];
    /**
     * Number of set calls, used to assert write overhead.
     */
    public int $setCount = 0;
    /**
     * Number of get calls per item name.
     *
     * @var array<string,int>
     */
    protected array $getCounts = [];
    /**
     * Callbacks to run on the nth get of an item name, used to simulate
     * another worker acting concurrently.
     *
     * @var array<string,array{0:int,1:callable}>
     */
    protected array $getHooks = [];

    public function get(string $key) : mixed
    {
        $this->getCounts[$key] = ($this->getCounts[$key] ?? 0) + 1;
        if (isset($this->getHooks[$key])
            && $this->getHooks[$key][0] === $this->getCounts[$key]
        ) {
            $hook = $this->getHooks[$key][1];
            $hook($this);
        }
        $key = $this->renderKey($key);
        if (!isset($this->storage[$key])) {
            return null;
        }
        if ($this->storage[$key]['expires'] <= \time()) {
            unset($this->storage[$key]);
            return null;
        }
        return $this->storage[$key]['value'];
    }

    /**
     * Run a callback right before the nth get of an item name resolves.
     *
     * @param string $key The item name to watch
     * @param int $nth Which get call fires the callback, counting from 1
     * @param callable $callback Receives this instance
     */
    public function onGet(string $key, int $nth, callable $callback) : void
    {
        $this->getHooks[$key] = [$nth, $callback];
    }

    public function set(string $key, mixed $value, ?int $ttl = null) : bool
    {
        $this->setCount++;
        $this->storage[$this->renderKey($key)] = [
            'value' => $value,
            'expires' => \time() + $this->makeTtl($ttl),
            'ttl' => $this->makeTtl($ttl),
        ];
        return true;
    }

    public function delete(string $key) : bool
    {
        unset($this->storage[$this->renderKey($key)]);
        return true;
    }

    public function flush() : bool
    {
        $this->storage = [];
        return true;
    }

    /**
     * @return array<int,string>
     */
    public function getStoredKeys() : array
    {
        return \array_keys($this->storage);
    }

    /**
     * Get the TTL an item was stored with, in seconds.
     *
     * @param string $key
     *
     * @return int|null The TTL, or null when the item is not stored
     */
    public function getTtlOf(string $key) : ?int
    {
        return $this->storage[$this->renderKey($key)]['ttl'] ?? null;
    }
}
