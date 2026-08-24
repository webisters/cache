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
}
