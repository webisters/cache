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

use Framework\Cache\Debug\CacheCollector;
use Framework\Cache\NullCache;
use PHPUnit\Framework\TestCase;

final class NullCacheTest extends TestCase
{
    protected NullCache $cache;

    protected function setUp() : void
    {
        $this->cache = new NullCache();
    }

    public function testWritesReportSuccessButStoreNothing() : void
    {
        self::assertTrue($this->cache->set('foo', 'bar', 60));
        self::assertNull($this->cache->get('foo'));
    }

    public function testEveryReadIsAMiss() : void
    {
        $this->cache->set('foo', 'bar');
        self::assertNull($this->cache->get('foo'));
        self::assertSame(
            ['foo' => null, 'bar' => null],
            $this->cache->getMulti(['foo', 'bar'])
        );
    }

    public function testMultiWrites() : void
    {
        self::assertSame(
            ['foo' => true, 'bar' => true],
            $this->cache->setMulti(['foo' => 'x', 'bar' => 'y'])
        );
        self::assertSame(
            ['foo' => true, 'bar' => true],
            $this->cache->deleteMulti(['foo', 'bar'])
        );
    }

    public function testDeleteAndFlush() : void
    {
        self::assertTrue($this->cache->delete('foo'));
        self::assertTrue($this->cache->flush());
    }

    public function testAddAlwaysSucceeds() : void
    {
        self::assertTrue($this->cache->add('foo', 'bar'));
        self::assertTrue($this->cache->add('foo', 'baz'));
    }

    public function testLocksAreAlwaysGranted() : void
    {
        self::assertTrue($this->cache->lock('report'));
        self::assertTrue($this->cache->lock('report'));
        self::assertTrue($this->cache->unlock('report'));
    }

    public function testIncrementNeverAccumulates() : void
    {
        self::assertSame(1, $this->cache->increment('i'));
        self::assertSame(1, $this->cache->increment('i'));
        self::assertSame(3, $this->cache->increment('i', 3));
        self::assertSame(-1, $this->cache->decrement('i'));
        self::assertSame(-1, $this->cache->decrement('i'));
    }

    public function testRememberRecomputesEveryTime() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        self::assertSame('computed', $this->cache->remember('foo', $callback));
        self::assertSame('computed', $this->cache->remember('foo', $callback));
        self::assertSame('computed', $this->cache->getOrSet('foo', $callback));
        self::assertSame(3, $calls);
    }

    public function testRememberProtectedRecomputesEveryTime() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        self::assertSame('computed', $this->cache->rememberProtected('foo', $callback, 60));
        self::assertSame('computed', $this->cache->rememberProtected('foo', $callback, 60));
        self::assertSame(2, $calls);
    }

    public function testTagsAreAcceptedAndInert() : void
    {
        $tagged = $this->cache->tags('posts');
        self::assertTrue($tagged->set('list', 'value'));
        self::assertNull($tagged->get('list'));
        self::assertTrue($tagged->flush());
        self::assertTrue($this->cache->flushTags(['posts', 'users']));
    }

    public function testDebugCollectorReportsTheHandler() : void
    {
        $collector = new class() extends CacheCollector {
            public function getHandler() : string
            {
                return parent::getHandler();
            }
        };
        $this->cache->setDebugCollector($collector);
        self::assertSame('null', $collector->getHandler());
        $this->cache->get('foo');
        $this->cache->set('foo', 'bar');
        $this->cache->delete('foo');
        $this->cache->flush();
        $contents = $collector->getContents();
        self::assertStringContainsString('Ran 4 commands', $contents);
        self::assertStringContainsString('GET', $contents);
        self::assertStringContainsString('SET', $contents);
        self::assertStringContainsString('DELETE', $contents);
        self::assertStringContainsString('FLUSH', $contents);
    }
}
