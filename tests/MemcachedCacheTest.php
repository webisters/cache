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

use Framework\Cache\Cache;
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

    /**
     * A cache that counts the single-key methods its multi methods fall back
     * on. Asserting on the driver rather than on the connection keeps this
     * independent of the ext-memcached signatures, and it is the property that
     * matters: a multi method must not reach the single-key path at all.
     *
     * @param string $prefix
     *
     * @return MemcachedCache
     */
    private function countingCache(string $prefix) : MemcachedCache
    {
        return new class(['servers' => [['host' => \getenv('MEMCACHED_HOST'), 'port' => 11211]]], $prefix, $this->serializer, $this->getLogger()) extends MemcachedCache {
            public int $singleGets = 0;
            public int $singleSets = 0;
            public int $singleDeletes = 0;

            protected function getValue(string $key) : mixed
            {
                $this->singleGets++;
                return parent::getValue($key);
            }

            public function set(string $key, mixed $value, ?int $ttl = null) : bool
            {
                $this->singleSets++;
                return parent::set($key, $value, $ttl);
            }

            public function delete(string $key) : bool
            {
                $this->singleDeletes++;
                return parent::delete($key);
            }
        };
    }

    public function testMultiMethodsDoNotFallBackToTheSingleKeyPath() : void
    {
        $cache = $this->countingCache('rt-');
        $data = [];
        for ($i = 0; $i < 20; $i++) {
            $data['key' . $i] = $i;
        }
        $status = $cache->setMulti($data, static::LIVE_TTL);
        self::assertSame(\array_fill_keys(\array_keys($data), true), $status);
        // @phpstan-ignore-next-line
        self::assertSame(0, $cache->singleSets, 'setMulti looped over set()');

        self::assertSame($data, $cache->getMulti(\array_keys($data)));
        // @phpstan-ignore-next-line
        self::assertSame(0, $cache->singleGets, 'getMulti looped over get()');

        self::assertSame(
            \array_fill_keys(\array_keys($data), true),
            $cache->deleteMulti(\array_keys($data))
        );
        // @phpstan-ignore-next-line
        self::assertSame(0, $cache->singleDeletes, 'deleteMulti looped over delete()');
        self::assertNull($cache->get('key0'));
        $cache->flush();
    }

    public function testGetMultiReportsMissingKeysAsNull() : void
    {
        $cache = $this->countingCache('rt2-');
        self::assertTrue($cache->set('here', 'value', static::LIVE_TTL));
        self::assertSame(
            ['here' => 'value', 'gone' => null, 'never' => null],
            $cache->getMulti(['here', 'gone', 'never'])
        );
        $cache->flush();
    }

    public function testDeleteMultiReportsAMissingKeyAsNotDeleted() : void
    {
        $cache = $this->countingCache('rt3-');
        self::assertTrue($cache->set('here', 'value', static::LIVE_TTL));
        self::assertSame(
            ['here' => true, 'never' => false],
            $cache->deleteMulti(['here', 'never'])
        );
        $cache->flush();
    }

    protected function makeSiblingCache(string $prefix) : ?Cache
    {
        return new MemcachedCache(
            ['servers' => [['host' => \getenv('MEMCACHED_HOST'), 'port' => 11211]]],
            $prefix,
            $this->serializer,
            $this->getLogger()
        );
    }

    /**
     * Memcached offers no way to list or select keys, so flush() cannot be
     * scoped to the prefix. See the docblock on MemcachedCache::flush().
     *
     * @return bool
     */
    protected function flushIsPrefixScoped() : bool
    {
        return false;
    }
}
