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
     * @var array<string,array{value:mixed,expires:int}>
     */
    protected array $storage = [];
    /**
     * Number of set calls, used to assert write overhead.
     */
    public int $setCount = 0;

    public function get(string $key) : mixed
    {
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

    public function set(string $key, mixed $value, ?int $ttl = null) : bool
    {
        $this->setCount++;
        $this->storage[$this->renderKey($key)] = [
            'value' => $value,
            'expires' => \time() + $this->makeTtl($ttl),
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
}
