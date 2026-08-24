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

use Framework\Cache\ApcuCache;
use Framework\Cache\ArrayCache;
use Framework\Cache\Cache;
use Framework\Cache\DatabaseCache;
use Framework\Cache\Debug\CacheCollector;
use Framework\Cache\FilesCache;
use Framework\Cache\MemcachedCache;
use Framework\Cache\NullCache;
use Framework\Cache\RedisCache;
use Framework\Cache\Serializer;
use Framework\Log\Logger;
use Framework\Log\Loggers\MultiFileLogger;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * TTL for an item a test needs to still be there when it reads it back.
     *
     * Long enough that crossing a second boundary between the write and the
     * read cannot expire it. A one second TTL here used to fail the suite at
     * random on a slow runner.
     */
    protected const LIVE_TTL = 60;
    /**
     * TTL for an item a test means to watch expire.
     *
     * Paired with EXPIRY_WAIT, which outlasts it by a whole second, so both
     * halves have a margin: the item is still there right after the write, and
     * certainly gone after the wait.
     */
    protected const SHORT_TTL = 2;
    /**
     * How long to wait for an item written with SHORT_TTL to expire.
     */
    protected const EXPIRY_WAIT = 3;
    protected Cache $cache;
    /**
     * @var array<string,mixed>
     */
    protected array $configs = [];
    protected string $prefix = 'test';
    protected Serializer $serializer = Serializer::PHP;

    public function tearDown() : void
    {
        $this->cache->flush();
        // Drop the last reference to the driver so its destructor runs now,
        // closing connections rather than leaving them to pile up across the
        // suite. A stand-in rather than unset, which PHP 8.4 property hooks
        // make unsafe on a property a subclass could redeclare.
        $this->cache = new NullCache();
    }

    protected function getLogger() : Logger
    {
        static $logger;
        if (!$logger) {
            $dir = \sys_get_temp_dir() . '/cache-logs';
            \exec('rm -rf ' . $dir);
            \exec('mkdir -p ' . $dir);
            $logger = new MultiFileLogger($dir);
        }
        return $logger;
    }

    public function testGetSerializer() : void
    {
        self::assertSame($this->serializer, $this->cache->getSerializer());
    }

    public function testSetAndGet() : void
    {
        self::assertNull($this->cache->get('foo'));
        self::assertTrue($this->cache->set('foo', 'bar', static::SHORT_TTL));
        self::assertSame('bar', $this->cache->get('foo'));
        \sleep(static::EXPIRY_WAIT);
        self::assertNull($this->cache->get('foo'));
    }

    public function testSetAndGetNullAndFalseValues() : void
    {
        $this->assertNull($this->cache->get('null-value'));
        $this->assertNull($this->cache->get('false-value'));
        $this->cache->set('null-value', null);
        $this->cache->set('false-value', false);
        $this->assertNull($this->cache->get('null-value'));
        $this->assertFalse($this->cache->get('false-value'));
    }

    public function testSetMultiAndGetMulti() : void
    {
        self::assertSame(
            ['foo' => null, 'bar' => null],
            $this->cache->getMulti(['foo', 'bar'])
        );
        self::assertSame(
            ['foo' => true, 'bar' => true],
            $this->cache->setMulti(['foo' => 'x', 'bar' => 'y'], static::SHORT_TTL)
        );
        self::assertSame(
            ['bar' => 'y', 'foo' => 'x', 'baz' => null],
            $this->cache->getMulti(['bar', 'foo', 'baz'])
        );
        \sleep(static::EXPIRY_WAIT);
        self::assertSame(
            ['foo' => null, 'bar' => null],
            $this->cache->getMulti(['foo', 'bar'])
        );
    }

    public function testDelete() : void
    {
        self::assertNull($this->cache->get('foo'));
        self::assertTrue($this->cache->set('foo', 'bar', static::LIVE_TTL));
        self::assertSame('bar', $this->cache->get('foo'));
        self::assertTrue($this->cache->delete('foo'));
        self::assertNull($this->cache->get('foo'));
    }

    public function testDeleteMulti() : void
    {
        self::assertSame(
            ['foo' => null, 'bar' => null],
            $this->cache->getMulti(['foo', 'bar'])
        );
        self::assertSame(
            ['foo' => true, 'bar' => true],
            $this->cache->setMulti(['foo' => 'x', 'bar' => 'y'], static::LIVE_TTL)
        );
        self::assertSame(
            ['bar' => 'y', 'foo' => 'x'],
            $this->cache->getMulti(['bar', 'foo'])
        );
        self::assertSame(
            ['foo' => true, 'bar' => true],
            $this->cache->deleteMulti(['foo', 'bar'])
        );
        self::assertSame(
            ['foo' => null, 'bar' => null],
            $this->cache->getMulti(['foo', 'bar'])
        );
    }

    public function testFlush() : void
    {
        self::assertSame(
            ['foo' => true, 'bar' => true],
            $this->cache->setMulti(['foo' => 'x', 'bar' => 'y'], static::LIVE_TTL)
        );
        self::assertSame(
            ['bar' => 'y', 'foo' => 'x'],
            $this->cache->getMulti(['bar', 'foo'])
        );
        self::assertTrue($this->cache->flush());
        self::assertSame(
            ['bar' => null, 'foo' => null],
            $this->cache->getMulti(['bar', 'foo'])
        );
    }

    public function testNullTtlUsesTheInstanceDefault() : void
    {
        $this->cache->setDefaultTtl(30);
        self::assertSame(30, $this->cache->getDefaultTtl());
        self::assertTrue($this->cache->set('foo', 'bar'));
        self::assertSame('bar', $this->cache->get('foo'));
    }

    public function testZeroTtlStoresNothing() : void
    {
        self::assertTrue($this->cache->set('foo', 'bar', 0));
        self::assertNull($this->cache->get('foo'));
    }

    public function testNegativeTtlStoresNothing() : void
    {
        self::assertTrue($this->cache->set('foo', 'bar', -5));
        self::assertNull($this->cache->get('foo'));
    }

    public function testExpiredTtlDropsWhatWasThere() : void
    {
        self::assertTrue($this->cache->set('foo', 'bar', 60));
        self::assertSame('bar', $this->cache->get('foo'));
        // The new item is already expired, so the old one must not survive.
        self::assertTrue($this->cache->set('foo', 'baz', 0));
        self::assertNull($this->cache->get('foo'));
    }

    public function testExpiredTtlAddsNothing() : void
    {
        self::assertFalse($this->cache->add('foo', 'bar', 0));
        self::assertNull($this->cache->get('foo'));
        self::assertFalse($this->cache->add('foo', 'bar', -5));
        self::assertNull($this->cache->get('foo'));
    }

    public function testExpiredTtlAddLeavesAnExistingItemAlone() : void
    {
        self::assertTrue($this->cache->set('foo', 'kept', 60));
        // add never overwrites, so an expired TTL must not be a way to delete.
        self::assertFalse($this->cache->add('foo', 'bar', 0));
        self::assertSame('kept', $this->cache->get('foo'));
    }

    public function testAdd() : void
    {
        self::assertTrue($this->cache->add('foo', 'first', 60));
        self::assertFalse($this->cache->add('foo', 'second', 60));
        self::assertSame('first', $this->cache->get('foo'));
        self::assertTrue($this->cache->delete('foo'));
        self::assertTrue($this->cache->add('foo', 'third', 60));
        self::assertSame('third', $this->cache->get('foo'));
    }

    public function testAddAfterExpiration() : void
    {
        self::assertTrue($this->cache->add('foo', 'first', static::SHORT_TTL));
        self::assertFalse($this->cache->add('foo', 'second', static::SHORT_TTL));
        \sleep(static::EXPIRY_WAIT);
        self::assertTrue($this->cache->add('foo', 'third', static::LIVE_TTL));
        self::assertSame('third', $this->cache->get('foo'));
    }

    public function testLockAndUnlock() : void
    {
        self::assertTrue($this->cache->lock('report'));
        self::assertFalse($this->cache->lock('report'));
        self::assertTrue($this->cache->unlock('report'));
        self::assertTrue($this->cache->lock('report'));
    }

    public function testLockDoesNotTouchTheItem() : void
    {
        self::assertTrue($this->cache->set('report', 'value', 60));
        self::assertTrue($this->cache->lock('report'));
        self::assertSame('value', $this->cache->get('report'));
    }

    public function testRememberProtected() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        self::assertNull($this->cache->get('foo'));
        self::assertSame('computed', $this->cache->rememberProtected('foo', $callback, 60));
        self::assertSame('computed', $this->cache->rememberProtected('foo', $callback, 60));
        self::assertSame(1, $calls);
        self::assertSame('computed', $this->cache->get('foo'));
        // The lock was released once the computation was done.
        self::assertTrue($this->cache->lock('foo'));
    }

    public function testRememberProtectedDoesNotStoreNull() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return null;
        };
        self::assertNull($this->cache->rememberProtected('foo', $callback, 60));
        self::assertNull($this->cache->rememberProtected('foo', $callback, 60));
        self::assertSame(2, $calls);
    }

    public function testTaggedRememberProtected() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        self::assertSame(
            'computed',
            $this->cache->tags('posts')->rememberProtected('list', $callback, 60)
        );
        self::assertSame(
            'computed',
            $this->cache->tags('posts')->rememberProtected('list', $callback, 60)
        );
        self::assertSame(1, $calls);
        self::assertTrue($this->cache->tags('posts')->flush());
        self::assertSame(
            'computed',
            $this->cache->tags('posts')->rememberProtected('list', $callback, 60)
        );
        self::assertSame(2, $calls);
    }

    public function testRemember() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        self::assertNull($this->cache->get('foo'));
        self::assertSame('computed', $this->cache->remember('foo', $callback, 60));
        self::assertSame('computed', $this->cache->remember('foo', $callback, 60));
        self::assertSame('computed', $this->cache->get('foo'));
        self::assertSame(1, $calls);
    }

    public function testRememberDoesNotStoreNull() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return null;
        };
        self::assertNull($this->cache->remember('foo', $callback, 60));
        self::assertNull($this->cache->remember('foo', $callback, 60));
        self::assertSame(2, $calls);
    }

    public function testGetOrSet() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        self::assertSame('computed', $this->cache->getOrSet('foo', $callback, 60));
        self::assertSame('computed', $this->cache->remember('foo', $callback, 60));
        self::assertSame(1, $calls);
    }

    public function testTaggedRemember() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        self::assertSame('computed', $this->cache->tags('posts')->remember('list', $callback, 60));
        self::assertSame('computed', $this->cache->tags('posts')->remember('list', $callback, 60));
        self::assertSame(1, $calls);
        self::assertTrue($this->cache->tags('posts')->flush());
        self::assertSame('computed', $this->cache->tags('posts')->remember('list', $callback, 60));
        self::assertSame(2, $calls);
    }

    public function testTagsSetAndGet() : void
    {
        self::assertNull($this->cache->tags('posts')->get('list'));
        self::assertTrue($this->cache->tags('posts')->set('list', 'value', 60));
        self::assertSame('value', $this->cache->tags('posts')->get('list'));
        self::assertTrue($this->cache->tags('posts')->flush());
        self::assertNull($this->cache->tags('posts')->get('list'));
    }

    public function testTagsDoNotCollideWithUntaggedItems() : void
    {
        self::assertTrue($this->cache->set('list', 'plain', 60));
        self::assertTrue($this->cache->tags('posts')->set('list', 'tagged', 60));
        self::assertSame('plain', $this->cache->get('list'));
        self::assertSame('tagged', $this->cache->tags('posts')->get('list'));
        self::assertTrue($this->cache->tags('posts')->flush());
        self::assertSame('plain', $this->cache->get('list'));
        self::assertNull($this->cache->tags('posts')->get('list'));
    }

    public function testFlushTagsInvalidatesOnlyTheGivenTags() : void
    {
        self::assertTrue($this->cache->tags('posts')->set('a', 'x', 60));
        self::assertTrue($this->cache->tags('users')->set('b', 'y', 60));
        self::assertTrue($this->cache->flushTags('posts'));
        self::assertNull($this->cache->tags('posts')->get('a'));
        self::assertSame('y', $this->cache->tags('users')->get('b'));
    }

    public function testFlushOneTagInvalidatesItemsSharingIt() : void
    {
        self::assertTrue($this->cache->tags(['posts', 'users'])->set('feed', 'z', 60));
        self::assertSame('z', $this->cache->tags(['users', 'posts'])->get('feed'));
        self::assertTrue($this->cache->tags('users')->flush());
        self::assertNull($this->cache->tags(['posts', 'users'])->get('feed'));
    }

    public function testIncrement() : void
    {
        self::assertSame(1, $this->cache->increment('i'));
        self::assertSame(2, $this->cache->increment('i'));
        self::assertSame(5, $this->cache->increment('i', 3));
        self::assertSame(6, $this->cache->increment('i', 1, static::SHORT_TTL));
        \sleep(static::EXPIRY_WAIT);
        self::assertSame(1, $this->cache->increment('i'));
        self::assertSame(11, $this->cache->increment('i', 10));
    }

    public function testDecrement() : void
    {
        self::assertSame(-1, $this->cache->decrement('i'));
        self::assertSame(-2, $this->cache->decrement('i'));
        self::assertSame(-5, $this->cache->decrement('i', 3));
        self::assertSame(-6, $this->cache->decrement('i', 1, static::SHORT_TTL));
        \sleep(static::EXPIRY_WAIT);
        self::assertSame(-1, $this->cache->decrement('i'));
        self::assertSame(-11, $this->cache->decrement('i', 10));
    }

    public function testCounterDoesNotLoseAConcurrentMove() : void
    {
        // The lock a counter takes is the item's own, so a worker holding it
        // stands in for the concurrent one that used to make an increment
        // vanish. The move still has to land, and land on top of what is
        // already there rather than replacing it.
        self::assertSame(5, $this->cache->increment('hits', 5, static::LIVE_TTL));
        self::assertTrue($this->cache->lock('hits'));
        $this->cache->setLockWait(0.05);
        self::assertSame(6, $this->cache->increment('hits', 1, static::LIVE_TTL));
        self::assertSame(6, $this->cache->get('hits'));
        self::assertTrue($this->cache->unlock('hits'));
    }

    public function testCounterReleasesItsLock() : void
    {
        self::assertSame(1, $this->cache->increment('hits', 1, static::LIVE_TTL));
        // Free for the next mover, otherwise every later one would wait it out.
        self::assertTrue($this->cache->lock('hits'));
        self::assertTrue($this->cache->unlock('hits'));
        self::assertSame(2, $this->cache->increment('hits', 1, static::LIVE_TTL));
        self::assertTrue($this->cache->lock('hits'));
    }

    public function testCounterDoesNotDisturbTheItemsLockedName() : void
    {
        self::assertTrue($this->cache->set('hits', 40, static::LIVE_TTL));
        self::assertSame(42, $this->cache->increment('hits', 2, static::LIVE_TTL));
        // The lock lives under a name of its own, never over the counter.
        self::assertSame(42, $this->cache->get('hits'));
    }

    public function testIncrementAndDecrement() : void
    {
        self::assertSame(1, $this->cache->increment('id'));
        self::assertSame(2, $this->cache->increment('id'));
        self::assertSame(3, $this->cache->increment('id'));
        self::assertSame(2, $this->cache->decrement('id'));
        self::assertSame(0, $this->cache->decrement('id', 2));
    }

    protected function setCollector() : CacheCollector
    {
        $collector = new CacheCollector();
        $this->cache->setDebugCollector($collector);
        return $collector;
    }

    public function testDebugCacheNotSet() : void
    {
        $collector = new CacheCollector();
        self::assertStringContainsString(
            'This collector has not been added to a Cache instance',
            $collector->getContents()
        );
    }

    public function testDebugActivities() : void
    {
        $collector = $this->setCollector();
        self::assertEmpty($collector->getActivities());
        $this->cache->get('foo');
        self::assertSame(
            [
                'collector',
                'class',
                'description',
                'start',
                'end',
            ],
            \array_keys($collector->getActivities()[0]) // @phpstan-ignore-line
        );
    }

    public function testDebugDefault() : void
    {
        $collector = $this->setCollector();
        self::assertStringContainsString(
            $this->serializer->value,
            $collector->getContents()
        );
        self::assertStringContainsString(
            'No command was run',
            $collector->getContents()
        );
    }

    public function testDebugRunCommands() : void
    {
        $collector = $this->setCollector();
        $this->cache->get('foo');
        $contents = $collector->getContents();
        self::assertStringContainsString('Ran 1 command', $contents);
        self::assertStringContainsString('GET', $contents);
        $this->cache->set('xxx', 'foo', static::LIVE_TTL);
        $contents = $collector->getContents();
        self::assertStringContainsString('Ran 2 commands', $contents);
        self::assertStringContainsString('SET', $contents);
        $this->cache->delete('xxx');
        $contents = $collector->getContents();
        self::assertStringContainsString('Ran 3 commands', $contents);
        self::assertStringContainsString('DELETE', $contents);
        $this->cache->flush();
        $contents = $collector->getContents();
        self::assertStringContainsString('Ran 4 commands', $contents);
        self::assertStringContainsString('FLUSH', $contents);
    }

    public function testDebugHandler() : void
    {
        $collector = new class() extends CacheCollector {
            public function getHandler() : string
            {
                return parent::getHandler();
            }
        };
        $this->cache->setDebugCollector($collector);
        // @phpstan-ignore-next-line
        $handler = match ($this->cache::class) {
            ApcuCache::class => 'apcu',
            ArrayCache::class => 'array',
            DatabaseCache::class => 'database',
            FilesCache::class => 'files',
            MemcachedCache::class => 'memcached',
            RedisCache::class => 'redis',
        };
        self::assertSame($handler, $collector->getHandler());
        $cache = new class() extends FilesCache {
            protected Serializer $serializer = Serializer::PHP;

            public function __construct()
            {
            }

            public function __destruct()
            {
            }
        };
        $cache->setDebugCollector($collector);
        self::assertStringContainsString('@anonymous', $collector->getHandler());
    }
}
