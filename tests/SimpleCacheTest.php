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

use Framework\Cache\ArrayCache;
use Framework\Cache\SimpleCache;
use Framework\Cache\SimpleCacheInvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

final class SimpleCacheTest extends TestCase
{
    protected ArrayCache $backing;
    protected SimpleCache $cache;

    protected function setUp() : void
    {
        $this->backing = new ArrayCache();
        $this->cache = new SimpleCache($this->backing);
    }

    public function testImplementsThePsrInterface() : void
    {
        self::assertInstanceOf(CacheInterface::class, $this->cache);
        self::assertSame($this->backing, $this->cache->getCache());
    }

    public function testSetAndGet() : void
    {
        self::assertTrue($this->cache->set('foo', 'bar'));
        self::assertSame('bar', $this->cache->get('foo'));
    }

    public function testGetReturnsTheDefaultOnMiss() : void
    {
        self::assertNull($this->cache->get('foo'));
        self::assertSame('fallback', $this->cache->get('foo', 'fallback'));
        self::assertFalse($this->cache->get('foo', false));
    }

    public function testStoredNullIsNotAMiss() : void
    {
        self::assertTrue($this->cache->set('foo', null));
        self::assertTrue($this->cache->has('foo'));
        // The default must not win here: null was stored on purpose.
        self::assertNull($this->cache->get('foo', 'fallback'));
    }

    public function testStoresFalsyValues() : void
    {
        foreach ([false, 0, '', [], 0.0] as $value) {
            self::assertTrue($this->cache->set('foo', $value));
            self::assertSame($value, $this->cache->get('foo', 'fallback'));
        }
    }

    public function testDelete() : void
    {
        $this->cache->set('foo', 'bar');
        self::assertTrue($this->cache->delete('foo'));
        self::assertNull($this->cache->get('foo'));
        // Deleting a key that was never there is not a failure.
        self::assertTrue($this->cache->delete('foo'));
    }

    public function testClear() : void
    {
        $this->cache->set('foo', 'bar');
        $this->cache->set('baz', 'qux');
        self::assertTrue($this->cache->clear());
        self::assertNull($this->cache->get('foo'));
        self::assertNull($this->cache->get('baz'));
    }

    public function testHas() : void
    {
        self::assertFalse($this->cache->has('foo'));
        $this->cache->set('foo', 'bar');
        self::assertTrue($this->cache->has('foo'));
        $this->cache->delete('foo');
        self::assertFalse($this->cache->has('foo'));
    }

    public function testMultiple() : void
    {
        self::assertTrue($this->cache->setMultiple(['foo' => 'x', 'bar' => 'y']));
        self::assertSame(
            ['foo' => 'x', 'bar' => 'y'],
            $this->cache->getMultiple(['foo', 'bar'])
        );
        self::assertSame(
            ['foo' => 'x', 'nope' => 'fallback'],
            $this->cache->getMultiple(['foo', 'nope'], 'fallback')
        );
        self::assertTrue($this->cache->deleteMultiple(['foo', 'bar']));
        self::assertSame(
            ['foo' => null, 'bar' => null],
            $this->cache->getMultiple(['foo', 'bar'])
        );
    }

    public function testMultipleAcceptsAnyIterable() : void
    {
        $values = (static function () {
            yield 'foo' => 'x';
            yield 'bar' => 'y';
        })();
        self::assertTrue($this->cache->setMultiple($values));
        $keys = (static function () {
            yield 'foo';
            yield 'bar';
        })();
        self::assertSame(['foo' => 'x', 'bar' => 'y'], $this->cache->getMultiple($keys));
    }

    public function testIntegerKeysAreAcceptedInSetMultiple() : void
    {
        self::assertTrue($this->cache->setMultiple([1 => 'one', 2 => 'two']));
        self::assertSame(['1' => 'one', '2' => 'two'], $this->cache->getMultiple(['1', '2']));
    }

    public function testTtlInSeconds() : void
    {
        self::assertTrue($this->cache->set('foo', 'bar', 1));
        self::assertSame('bar', $this->cache->get('foo'));
        \sleep(2);
        self::assertNull($this->cache->get('foo'));
    }

    public function testTtlAsDateInterval() : void
    {
        self::assertTrue($this->cache->set('foo', 'bar', new \DateInterval('PT1S')));
        self::assertSame('bar', $this->cache->get('foo'));
        \sleep(2);
        self::assertNull($this->cache->get('foo'));
    }

    public function testExpiredTtlDeletesTheItem() : void
    {
        $this->cache->set('foo', 'kept');
        self::assertTrue($this->cache->set('foo', 'bar', 0));
        self::assertFalse($this->cache->has('foo'));
        $this->cache->set('foo', 'kept');
        self::assertTrue($this->cache->set('foo', 'bar', -5));
        self::assertFalse($this->cache->has('foo'));
    }

    public function testNullTtlUsesTheCacheDefault() : void
    {
        $this->backing->setDefaultTtl(90);
        self::assertTrue($this->cache->set('foo', 'bar'));
        self::assertSame('bar', $this->cache->get('foo'));
    }

    public function testEmptyKeyIsRejected() : void
    {
        $this->expectException(SimpleCacheInvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must not be empty');
        $this->cache->get('');
    }

    /**
     * @dataProvider reservedCharacterProvider
     *
     * @param string $key
     */
    public function testReservedCharactersAreRejected(string $key) : void
    {
        $this->expectException(SimpleCacheInvalidArgumentException::class);
        $this->expectExceptionMessage('reserved characters');
        $this->cache->get($key);
    }

    public function testLegalKeyCharactersAreAccepted() : void
    {
        $key = 'AZaz09_.' . \str_repeat('k', 56);
        self::assertSame(64, \strlen($key));
        self::assertTrue($this->cache->set($key, 'bar'));
        self::assertSame('bar', $this->cache->get($key));
    }

    public function testEveryMethodValidatesItsKey() : void
    {
        $calls = [
            fn () => $this->cache->get('bad:key'),
            fn () => $this->cache->set('bad:key', 'x'),
            fn () => $this->cache->delete('bad:key'),
            fn () => $this->cache->has('bad:key'),
            fn () => $this->cache->getMultiple(['bad:key']),
            fn () => $this->cache->setMultiple(['bad:key' => 'x']),
            fn () => $this->cache->deleteMultiple(['bad:key']),
        ];
        foreach ($calls as $index => $call) {
            try {
                $call();
                self::fail('Call ' . $index . ' was expected to reject the key');
            } catch (PsrInvalidArgumentException $exception) {
                self::assertStringContainsString('reserved characters', $exception->getMessage());
            }
        }
    }

    public function testNonStringKeysAreRejected() : void
    {
        $this->expectException(SimpleCacheInvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must be a string, float given');
        // @phpstan-ignore-next-line
        $this->cache->getMultiple([1.5, 2]);
    }

    public function testABadKeyLaterInTheListStopsTheWholeCall() : void
    {
        $this->cache->set('foo', 'kept');
        try {
            $this->cache->deleteMultiple(['foo', 'bad:key']);
            self::fail('The bad key was expected to be rejected');
        } catch (SimpleCacheInvalidArgumentException) {
            // Nothing may be deleted when part of the list is invalid.
            self::assertSame('kept', $this->cache->get('foo'));
        }
    }

    public function testItemsAreReachableThroughBothApis() : void
    {
        $this->cache->set('foo', 'bar');
        self::assertSame('bar', $this->backing->get('foo'));
        $this->backing->set('baz', 'qux', 60);
        self::assertSame('qux', $this->cache->get('baz'));
    }

    /**
     * @return array<int,array<int,string>>
     */
    public static function reservedCharacterProvider() : array
    {
        return [
            ['foo{bar'],
            ['foo}bar'],
            ['foo(bar'],
            ['foo)bar'],
            ['foo/bar'],
            ['foo\bar'],
            ['foo@bar'],
            ['foo:bar'],
        ];
    }
}
