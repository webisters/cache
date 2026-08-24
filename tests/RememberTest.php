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

use PHPUnit\Framework\TestCase;

final class RememberTest extends TestCase
{
    protected ArrayCacheMock $cache;

    protected function setUp() : void
    {
        $this->cache = new ArrayCacheMock(prefix: 'test-');
    }

    public function testComputesOnMiss() : void
    {
        self::assertNull($this->cache->get('foo'));
        $value = $this->cache->remember('foo', static function () {
            return 'computed';
        });
        self::assertSame('computed', $value);
        self::assertSame('computed', $this->cache->get('foo'));
    }

    public function testCallbackRunsOnlyOnce() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        self::assertSame('computed', $this->cache->remember('foo', $callback));
        self::assertSame('computed', $this->cache->remember('foo', $callback));
        self::assertSame('computed', $this->cache->remember('foo', $callback));
        self::assertSame(1, $calls);
    }

    public function testDoesNotCallTheCallbackOnHit() : void
    {
        $this->cache->set('foo', 'stored');
        $value = $this->cache->remember('foo', static function () : void {
            throw new \LogicException('The callback must not run on a hit');
        });
        self::assertSame('stored', $value);
    }

    public function testCallbackReceivesTheKey() : void
    {
        $received = null;
        $this->cache->remember('foo', static function (string $key) use (&$received) {
            $received = $key;
            return 'computed';
        });
        self::assertSame('foo', $received);
    }

    public function testRespectsTheGivenTtl() : void
    {
        $this->cache->remember('foo', static fn () => 'computed', 5);
        self::assertSame(5, $this->cache->getTtlOf('foo'));
    }

    public function testUsesTheDefaultTtlWhenNoneIsGiven() : void
    {
        $this->cache->setDefaultTtl(90);
        $this->cache->remember('foo', static fn () => 'computed');
        self::assertSame(90, $this->cache->getTtlOf('foo'));
    }

    public function testNullResultIsNotStored() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return null;
        };
        self::assertNull($this->cache->remember('foo', $callback));
        self::assertNull($this->cache->remember('foo', $callback));
        self::assertSame(2, $calls);
        self::assertSame([], $this->cache->getStoredKeys());
    }

    public function testFalseResultIsStored() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return false;
        };
        self::assertFalse($this->cache->remember('foo', $callback));
        self::assertFalse($this->cache->remember('foo', $callback));
        self::assertSame(1, $calls);
    }

    public function testStoresFalsyValues() : void
    {
        self::assertSame(0, $this->cache->remember('zero', static fn () => 0));
        self::assertSame('', $this->cache->remember('empty', static fn () => ''));
        self::assertSame([], $this->cache->remember('array', static fn () => []));
        self::assertSame(0, $this->cache->get('zero'));
        self::assertSame('', $this->cache->get('empty'));
        self::assertSame([], $this->cache->get('array'));
    }

    public function testExceptionInCallbackStoresNothing() : void
    {
        try {
            $this->cache->remember('foo', static function () : void {
                throw new \RuntimeException('boom');
            });
            self::fail('The exception was expected to bubble up');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }
        self::assertNull($this->cache->get('foo'));
    }

    public function testGetOrSetIsAnAliasOfRemember() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        self::assertSame('computed', $this->cache->getOrSet('foo', $callback));
        self::assertSame('computed', $this->cache->remember('foo', $callback));
        self::assertSame(1, $calls);
    }

    public function testTaggedRemember() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        $tagged = $this->cache->tags('posts');
        self::assertSame('computed', $tagged->remember('list', $callback));
        self::assertSame('computed', $tagged->remember('list', $callback));
        self::assertSame(1, $calls);
        self::assertNull($this->cache->get('list'));
    }

    public function testTaggedRememberRecomputesAfterInvalidation() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed-' . $calls;
        };
        $tagged = $this->cache->tags('posts');
        self::assertSame('computed-1', $tagged->remember('list', $callback));
        self::assertTrue($tagged->flush());
        self::assertSame('computed-2', $tagged->remember('list', $callback));
        self::assertSame(2, $calls);
    }

    public function testTaggedGetOrSetIsAnAliasOfRemember() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        $tagged = $this->cache->tags('posts');
        self::assertSame('computed', $tagged->getOrSet('list', $callback));
        self::assertSame('computed', $tagged->remember('list', $callback));
        self::assertSame(1, $calls);
    }

    public function testTaggedNullResultIsNotStored() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return null;
        };
        $tagged = $this->cache->tags('posts');
        self::assertNull($tagged->remember('list', $callback));
        self::assertNull($tagged->remember('list', $callback));
        self::assertSame(2, $calls);
    }
}
