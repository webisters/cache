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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StampedeTest extends TestCase
{
    protected ArrayCacheMock $cache;

    protected function setUp() : void
    {
        $this->cache = new ArrayCacheMock(prefix: 'test-');
    }

    public function testAddSetsWhenAbsent() : void
    {
        self::assertTrue($this->cache->add('foo', 'bar'));
        self::assertSame('bar', $this->cache->get('foo'));
    }

    public function testAddDoesNotOverwrite() : void
    {
        self::assertTrue($this->cache->add('foo', 'first'));
        self::assertFalse($this->cache->add('foo', 'second'));
        self::assertSame('first', $this->cache->get('foo'));
    }

    public function testAddSucceedsAgainAfterDelete() : void
    {
        self::assertTrue($this->cache->add('foo', 'first'));
        self::assertTrue($this->cache->delete('foo'));
        self::assertTrue($this->cache->add('foo', 'second'));
        self::assertSame('second', $this->cache->get('foo'));
    }

    public function testAddRespectsTheGivenTtl() : void
    {
        self::assertTrue($this->cache->add('foo', 'bar', 15));
        self::assertSame(15, $this->cache->getTtlOf('foo'));
    }

    public function testLockIsExclusive() : void
    {
        self::assertTrue($this->cache->lock('report'));
        self::assertFalse($this->cache->lock('report'));
        self::assertTrue($this->cache->unlock('report'));
        self::assertTrue($this->cache->lock('report'));
    }

    public function testLocksAreIndependentPerKey() : void
    {
        self::assertTrue($this->cache->lock('a'));
        self::assertTrue($this->cache->lock('b'));
        self::assertFalse($this->cache->lock('a'));
        self::assertFalse($this->cache->lock('b'));
    }

    public function testLockDoesNotTouchTheItemItself() : void
    {
        $this->cache->set('report', 'value');
        self::assertTrue($this->cache->lock('report'));
        self::assertSame('value', $this->cache->get('report'));
    }

    public function testLockingAnItemDoesNotBlockPlainReadsAndWrites() : void
    {
        self::assertTrue($this->cache->lock('report'));
        self::assertTrue($this->cache->set('report', 'value'));
        self::assertSame('value', $this->cache->get('report'));
        self::assertTrue($this->cache->delete('report'));
    }

    public function testLockTtlDefaultsToTheInstanceSetting() : void
    {
        self::assertSame(30, $this->cache->getLockTtl());
        $this->cache->setLockTtl(10);
        self::assertSame(10, $this->cache->getLockTtl());
        self::assertTrue($this->cache->lock('report'));
        self::assertSame(10, $this->cache->getTtlOf(Cache::RESERVED_PREFIX . 'lock:report'));
    }

    public function testLockTtlCanBeGivenPerCall() : void
    {
        self::assertTrue($this->cache->lock('report', 7));
        self::assertSame(7, $this->cache->getTtlOf(Cache::RESERVED_PREFIX . 'lock:report'));
    }

    public function testInvalidLockSettingsAreRejected() : void
    {
        try {
            $this->cache->setLockTtl(0);
            self::fail('Expected the lock TTL to be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Lock TTL must be greater than 0. 0 given',
                $exception->getMessage()
            );
        }
        try {
            $this->cache->setLockWait(0);
            self::fail('Expected the lock wait to be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringStartsWith(
                'Lock wait must be greater than 0.',
                $exception->getMessage()
            );
        }
        try {
            $this->cache->setLockSleep(0);
            self::fail('Expected the lock sleep to be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Lock sleep must be greater than 0. 0 given',
                $exception->getMessage()
            );
        }
    }

    public function testLockSettingsAccessors() : void
    {
        self::assertSame(5.0, $this->cache->getLockWait());
        self::assertSame(50000, $this->cache->getLockSleep());
        $this->cache->setLockWait(0.5);
        $this->cache->setLockSleep(1000);
        self::assertSame(0.5, $this->cache->getLockWait());
        self::assertSame(1000, $this->cache->getLockSleep());
    }

    public function testRememberProtectedComputesOnMiss() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        self::assertSame('computed', $this->cache->rememberProtected('foo', $callback, 60));
        self::assertSame('computed', $this->cache->rememberProtected('foo', $callback, 60));
        self::assertSame(1, $calls);
    }

    public function testRememberProtectedStoresAPlainReadableItem() : void
    {
        $this->cache->rememberProtected('foo', static fn () => 'computed', 60);
        self::assertSame('computed', $this->cache->get('foo'));
    }

    public function testRememberProtectedReleasesTheLock() : void
    {
        $this->cache->rememberProtected('foo', static fn () => 'computed', 60);
        self::assertTrue($this->cache->lock('foo'));
    }

    public function testRememberProtectedReleasesTheLockWhenTheCallbackThrows() : void
    {
        try {
            $this->cache->rememberProtected('foo', static function () : void {
                throw new \RuntimeException('boom');
            }, 60);
            self::fail('The exception was expected to bubble up');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }
        self::assertTrue($this->cache->lock('foo'));
        self::assertNull($this->cache->get('foo'));
    }

    public function testRememberProtectedNullResultIsNotStored() : void
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

    public function testRememberProtectedServesTheHeldValueWhileAnotherWorkerRefreshes() : void
    {
        // A value is in place, already past its recorded deadline so an early
        // recompute is certainly wanted, and a refresh is under way elsewhere.
        $this->cache->set('foo', 'stored', 60);
        $this->cache->set(Cache::RESERVED_PREFIX . 'stampede:foo', '10|' . (\time() - 10), 60);
        self::assertTrue($this->cache->lock('foo'));
        $value = $this->cache->rememberProtected('foo', static function () : void {
            throw new \LogicException('Must not recompute while another worker holds the lock');
        }, 60);
        self::assertSame('stored', $value);
    }

    public function testRememberProtectedComputesItselfWhenTheLockHolderNeverPublishes() : void
    {
        // Hard miss with the lock held by a worker that publishes nothing.
        self::assertTrue($this->cache->lock('foo'));
        $this->cache->setLockWait(0.05);
        $this->cache->setLockSleep(1000);
        $value = $this->cache->rememberProtected('foo', static fn () => 'computed', 60);
        self::assertSame('computed', $value);
        self::assertSame('computed', $this->cache->get('foo'));
    }

    public function testRememberProtectedTakesTheValuePublishedWhileWaiting() : void
    {
        self::assertTrue($this->cache->lock('foo'));
        $this->cache->setLockWait(5.0);
        $this->cache->setLockSleep(1000);
        // Publish on the second read the waiting loop makes.
        $this->cache->onGet('foo', 2, static function (ArrayCacheMock $cache) : void {
            $cache->set('foo', 'published-elsewhere', 60);
        });
        $value = $this->cache->rememberProtected('foo', static function () : void {
            throw new \LogicException('Must not recompute once the value shows up');
        }, 60);
        self::assertSame('published-elsewhere', $value);
    }

    public function testBetaZeroNeverRecomputesEarly() : void
    {
        // Past the recorded deadline, which any positive beta would refresh.
        $this->cache->set('foo', 'stored', 60);
        $this->cache->set(Cache::RESERVED_PREFIX . 'stampede:foo', '10|' . (\time() - 10), 60);
        $value = $this->cache->rememberProtected('foo', static function () : void {
            throw new \LogicException('Must not recompute with beta at zero');
        }, 60, 0.0);
        self::assertSame('stored', $value);
    }

    public function testRecomputesEarlyPastTheRecordedDeadline() : void
    {
        $this->cache->set('foo', 'stored', 60);
        $this->cache->set(Cache::RESERVED_PREFIX . 'stampede:foo', '10|' . (\time() - 10), 60);
        $value = $this->cache->rememberProtected('foo', static fn () => 'refreshed', 60);
        self::assertSame('refreshed', $value);
        self::assertSame('refreshed', $this->cache->get('foo'));
    }

    public function testAnItemThatCostNothingIsNeverRecomputedEarly() : void
    {
        $this->cache->set('foo', 'stored', 60);
        $this->cache->set(Cache::RESERVED_PREFIX . 'stampede:foo', '0|' . (\time() - 10), 60);
        $value = $this->cache->rememberProtected('foo', static function () : void {
            throw new \LogicException('Must not recompute a free item early');
        }, 60);
        self::assertSame('stored', $value);
    }

    public function testMissingMetadataNeverRecomputesEarly() : void
    {
        $this->cache->set('foo', 'stored', 60);
        $value = $this->cache->rememberProtected('foo', static function () : void {
            throw new \LogicException('Must not recompute without metadata');
        }, 60, 100000.0);
        self::assertSame('stored', $value);
    }

    public function testMalformedMetadataNeverRecomputesEarly() : void
    {
        foreach (['nonsense', 'a|b', '', '5'] as $index => $metadata) {
            $key = 'foo' . $index;
            $this->cache->set($key, 'stored', 60);
            $this->cache->set(Cache::RESERVED_PREFIX . 'stampede:' . $key, $metadata, 60);
            $value = $this->cache->rememberProtected($key, static function () : void {
                throw new \LogicException('Must not recompute on malformed metadata');
            }, 60, 100000.0);
            self::assertSame('stored', $value, 'metadata: ' . $metadata);
        }
    }

    public function testNegativeBetaIsRejected() : void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Beta must not be negative. -1 given');
        $this->cache->rememberProtected('foo', static fn () => 'x', 60, -1.0);
    }

    public function testMetadataIsDroppedWhenTheValueBecomesNull() : void
    {
        $this->cache->rememberProtected('foo', static fn () => 'computed', 60);
        self::assertIsString($this->cache->get(Cache::RESERVED_PREFIX . 'stampede:foo'));
        $this->cache->delete('foo');
        self::assertNull($this->cache->rememberProtected('foo', static fn () => null, 60));
        self::assertNull($this->cache->get(Cache::RESERVED_PREFIX . 'stampede:foo'));
    }

    public function testTaggedRememberProtected() : void
    {
        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return 'computed-' . $calls;
        };
        $tagged = $this->cache->tags('posts');
        self::assertSame('computed-1', $tagged->rememberProtected('list', $callback, 60));
        self::assertSame('computed-1', $tagged->rememberProtected('list', $callback, 60));
        self::assertSame(1, $calls);
        self::assertNull($this->cache->get('list'));
        self::assertTrue($tagged->flush());
        self::assertSame('computed-2', $tagged->rememberProtected('list', $callback, 60));
    }

    public function testTaggedRememberProtectedPassesTheKeyToTheCallback() : void
    {
        $received = null;
        $this->cache->tags('posts')->rememberProtected(
            'list',
            static function (string $key) use (&$received) {
                $received = $key;
                return 'computed';
            },
            60
        );
        self::assertSame('list', $received);
    }
}
