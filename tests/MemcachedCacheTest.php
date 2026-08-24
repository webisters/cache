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
use Framework\Cache\MemcachedCache;

class MemcachedCacheTest extends TestCase
{
    public function testUnreachablePoolNamesTheServers() : void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches(
            '/^Cache \(memcached\): Could not connect to any server in the pool: .+:12345/'
        );
        new MemcachedCache(
            [
                'servers' => [
                    [
                        'host' => \getenv('MEMCACHED_HOST'),
                        'port' => 12345,
                    ],
                ],
            ],
            $this->prefix,
            $this->serializer,
            $this->getLogger()
        );
    }

    public function testEmptyPoolIsReported() : void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage(
            'Cache (memcached): No server was added to the pool'
        );
        // Passing an empty servers config would not empty the pool, since
        // array_replace_recursive leaves the default server in place, so the
        // configs are replaced outright.
        new class() extends MemcachedCache {
            protected array $configs = [
                'servers' => [],
                'options' => [],
            ];
        };
    }

    public function setUp() : void
    {
        $this->configs = [
            'servers' => [
                [
                    'host' => \getenv('MEMCACHED_HOST'),
                ],
            ],
        ];
        $this->cache = new MemcachedCache(
            $this->configs,
            $this->prefix,
            $this->serializer,
            $this->getLogger()
        );
    }

    public function testSerializer() : void
    {
        $this->cache = new MemcachedCache(
            $this->configs,
            $this->prefix,
            $this->serializer->value,
            $this->getLogger()
        );
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage(
            '"foo" is not a valid backing value for enum Framework\Cache\Serializer'
        );
        $this->cache = new MemcachedCache(
            $this->configs,
            $this->prefix,
            'foo',
            $this->getLogger()
        );
    }

    public function testCustomInstance() : void
    {
        $cache = new MemcachedCache(null);
        self::assertNull($cache->getMemcached());
        $memcached = new \Memcached();
        $cache->setMemcached($memcached);
        self::assertSame($memcached, $cache->getMemcached());
        self::assertTrue($cache->isAutoClose());
    }

    public function testCustomInstanceConstructor() : void
    {
        $memcached = new \Memcached();
        $cache = new MemcachedCache($memcached);
        self::assertSame($memcached, $cache->getMemcached());
        self::assertFalse($cache->isAutoClose());
    }
}
