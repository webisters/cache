<?php
/*
 * This file is part of Webisters Cache Library.
 *
 * (c) Hafiz Muhammad Moaz <thewebisters@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Tests\Cache;

use Framework\Cache\ArrayCache;

class ArrayCacheTest extends TestCase
{
    public function setUp() : void
    {
        $this->setCache();
    }

    protected function setCache() : void
    {
        $this->cache = new ArrayCache(
            $this->configs,
            $this->prefix,
            $this->serializer,
            $this->getLogger()
        );
    }

    public function testSerializer() : void
    {
        $this->cache = new ArrayCache(
            $this->configs,
            $this->prefix,
            $this->serializer->value,
            $this->getLogger()
        );
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage(
            '"foo" is not a valid backing value for enum Framework\Cache\Serializer'
        );
        $this->cache = new ArrayCache(
            $this->configs,
            $this->prefix,
            'foo',
            $this->getLogger()
        );
    }

    public function testInstancesDoNotShareStorage() : void
    {
        $other = new ArrayCache($this->configs, $this->prefix, $this->serializer);
        self::assertTrue($this->cache->set('foo', 'bar', 60));
        self::assertSame('bar', $this->cache->get('foo'));
        self::assertNull($other->get('foo'));
    }

    public function testDeleteOfAMissingItem() : void
    {
        self::assertFalse($this->cache->delete('nope'));
        self::assertTrue($this->cache->set('foo', 'bar', 60));
        self::assertTrue($this->cache->delete('foo'));
    }

    public function testCountAndGarbageCollector() : void
    {
        self::assertSame(0, $this->cache->count()); // @phpstan-ignore-line
        self::assertTrue($this->cache->set('gone', 'x', 1));
        self::assertTrue($this->cache->set('kept', 'y', 60));
        self::assertSame(2, $this->cache->count()); // @phpstan-ignore-line
        \sleep(2);
        // Expired items are already invisible to reads.
        self::assertNull($this->cache->get('gone'));
        self::assertTrue($this->cache->gc()); // @phpstan-ignore-line
        self::assertSame(1, $this->cache->count()); // @phpstan-ignore-line
        self::assertSame('y', $this->cache->get('kept'));
    }
}
