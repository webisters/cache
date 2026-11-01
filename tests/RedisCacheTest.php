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

use Framework\Cache\ConnectionException;
use Framework\Cache\RedisCache;

class RedisCacheTest extends TestCase
{
    public function setUp() : void
    {
        $this->configs = [
            'host' => \getenv('REDIS_HOST'),
        ];
        $this->setCache();
    }

    protected function setCache() : void
    {
        $this->cache = new RedisCache(
            $this->configs,
            $this->prefix,
            $this->serializer,
            $this->getLogger()
        );
    }

    public function testSerializer() : void
    {
       $this->cache = new RedisCache(
           $this->configs,
           $this->prefix,
           $this->serializer->value,
           $this->getLogger()
       );
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage(
            '"foo" is not a valid backing value for enum Framework\Cache\Serializer'
        );
        $this->cache = new RedisCache(
            $this->configs,
            $this->prefix,
            'foo',
            $this->getLogger()
        );
    }

    public function testAuth() : void
    {
        $this->configs = [
            'host' => \getenv('REDIS_HOST'),
            'password' => 'foo',
        ];
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches(
            '/^Cache \(redis\): Authentication failed on .+:6379/'
        );
        $this->setCache();
    }

    public function testAuthFailureKeepsTheDriverException() : void
    {
        $this->configs = [
            'host' => \getenv('REDIS_HOST'),
            'password' => 'foo',
        ];
        try {
            $this->setCache();
            self::fail('The connection was expected to be refused');
        } catch (ConnectionException $exception) {
            // The driver message is what says why, so it must not be lost.
            self::assertInstanceOf(\RedisException::class, $exception->getPrevious());
        }
    }

    public function testConnectionFailureNamesTheServer() : void
    {
        $this->configs = [
            'host' => \getenv('REDIS_HOST'),
            'port' => 12345,
        ];
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches(
            '/^Cache \(redis\): Connection failed on .+:12345/'
        );
        $this->setCache();
    }

    public function testUnknownHostIsReported() : void
    {
        $this->configs = [
            'host' => 'no-such-redis-host.invalid',
            'timeout' => 1.0,
        ];
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches(
            '/^Cache \(redis\): Connection failed on no-such-redis-host\.invalid:6379/'
        );
        $this->setCache();
    }

    public function testSelect() : void
    {
        $this->configs = [
            'host' => \getenv('REDIS_HOST'),
            'database' => 0,
        ];
        $this->setCache();
        self::assertInstanceOf(RedisCache::class, $this->cache);
    }

    public function testCustomInstance() : void
    {
        $cache = new RedisCache(null);
        self::assertNull($cache->getRedis());
        $redis = new \Redis();
        $cache->setRedis($redis);
        self::assertSame($redis, $cache->getRedis());
        self::assertTrue($cache->isAutoClose());
    }

    public function testCustomInstanceConstructor() : void
    {
        $redis = new \Redis();
        $cache = new RedisCache($redis);
        self::assertSame($redis, $cache->getRedis());
        self::assertFalse($cache->isAutoClose());
    }

    /**
     * @param string|null $prefix
     * @param int|null $database
     *
     * @return RedisCache
     */
    private function makeCache(?string $prefix, ?int $database = null) : RedisCache
    {
        $configs = ['host' => \getenv('REDIS_HOST')];
        if ($database !== null) {
            $configs['database'] = $database;
        }
        return new RedisCache($configs, $prefix, $this->serializer, $this->getLogger());
    }

    public function testFlushLeavesOtherPrefixesAlone() : void
    {
        $mine = $this->makeCache('mine-');
        $theirs = $this->makeCache('theirs-');
        self::assertTrue($mine->set('key', 'mine', static::LIVE_TTL));
        self::assertTrue($theirs->set('key', 'theirs', static::LIVE_TTL));
        self::assertTrue($mine->flush());
        self::assertNull($mine->get('key'));
        self::assertSame('theirs', $theirs->get('key'));
        $theirs->flush();
    }

    /**
     * The defect in issue #48: flush() called FLUSHALL, which empties every
     * database on the server rather than the one this instance selected.
     */
    public function testFlushLeavesOtherDatabasesAlone() : void
    {
        $onOne = $this->makeCache('app-', 1);
        $onTwo = $this->makeCache('app-', 2);
        self::assertTrue($onOne->set('key', 'one', static::LIVE_TTL));
        self::assertTrue($onTwo->set('key', 'two', static::LIVE_TTL));
        self::assertTrue($onOne->flush());
        self::assertNull($onOne->get('key'));
        self::assertSame('two', $onTwo->get('key'));
        $onTwo->flush();
    }

    public function testFlushStorageClearsTheWholeDatabaseButNotTheServer() : void
    {
        $onOne = $this->makeCache('app-', 1);
        $otherPrefixOnOne = $this->makeCache('other-', 1);
        $onTwo = $this->makeCache('app-', 2);
        self::assertTrue($onOne->set('key', 'one', static::LIVE_TTL));
        self::assertTrue($otherPrefixOnOne->set('key', 'other', static::LIVE_TTL));
        self::assertTrue($onTwo->set('key', 'two', static::LIVE_TTL));
        self::assertTrue($onOne->flushStorage());
        // Everything in database 1 goes, whatever its prefix.
        self::assertNull($onOne->get('key'));
        self::assertNull($otherPrefixOnOne->get('key'));
        // Database 2 is untouched.
        self::assertSame('two', $onTwo->get('key'));
        $onTwo->flush();
    }

    public function testFlushWithoutAPrefixClearsTheSelectedDatabase() : void
    {
        $bare = $this->makeCache(null, 3);
        $prefixed = $this->makeCache('kept-', 4);
        self::assertTrue($bare->set('key', 'bare', static::LIVE_TTL));
        self::assertTrue($prefixed->set('key', 'kept', static::LIVE_TTL));
        self::assertTrue($bare->flush());
        self::assertNull($bare->get('key'));
        self::assertSame('kept', $prefixed->get('key'));
        $prefixed->flush();
    }

    public function testPrefixGlobMetacharactersAreEscaped() : void
    {
        // 'a*' must be swept as a literal prefix. If it reached SCAN unescaped
        // the pattern 'a**' would also match the 'ab' instance's keys.
        $glob = $this->makeCache('a*');
        $literal = $this->makeCache('ab');
        self::assertTrue($glob->set('key', 'glob', static::LIVE_TTL));
        self::assertTrue($literal->set('key', 'literal', static::LIVE_TTL));
        self::assertTrue($glob->flush());
        self::assertNull($glob->get('key'));
        self::assertSame('literal', $literal->get('key'));
        $literal->flush();
    }

    public function testFlushSweepsMoreKeysThanOneScanBatch() : void
    {
        $cache = $this->makeCache('bulk-');
        $items = [];
        for ($i = 0; $i < 1500; $i++) {
            $items['key' . $i] = $i;
        }
        $cache->setMulti($items, static::LIVE_TTL);
        self::assertSame(0, $cache->get('key0'));
        self::assertSame(1499, $cache->get('key1499'));
        self::assertTrue($cache->flush());
        self::assertNull($cache->get('key0'));
        self::assertNull($cache->get('key1499'));
    }
}
