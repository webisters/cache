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

use Framework\Cache\DatabaseCache;
use Framework\Database\Database;

class DatabaseCacheTest extends TestCase
{
    public function setUp() : void
    {
        $this->configs = [
            'host' => \getenv('DB_HOST'),
            'port' => (int) \getenv('DB_PORT'),
            'username' => \getenv('DB_USERNAME'),
            'password' => \getenv('DB_PASSWORD'),
            'schema' => \getenv('DB_SCHEMA'),
            'table' => 'CacheTests',
        ];
        $this->setCache();
        $this->cache->createTable(); // @phpstan-ignore-line
    }

    protected function setCache() : void
    {
        $this->cache = new DatabaseCache(
            $this->configs,
            $this->prefix,
            $this->serializer,
            $this->getLogger()
        );
    }

    public function testSerializer() : void
    {
        $this->cache = new DatabaseCache(
            $this->configs,
            $this->prefix,
            $this->serializer->value,
            $this->getLogger()
        );
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage(
            '"foo" is not a valid backing value for enum Framework\Cache\Serializer'
        );
        $this->cache = new DatabaseCache(
            $this->configs,
            $this->prefix,
            'foo',
            $this->getLogger()
        );
    }

    public function testCustomInstance() : void
    {
        $cache = new DatabaseCache(null);
        self::assertNull($cache->getDatabase());
        $database = new Database($this->configs);
        $cache->setDatabase($database);
        self::assertSame($database, $cache->getDatabase());
        self::assertTrue($cache->isAutoClose());
    }

    public function testCustomInstanceConstructor() : void
    {
        $database = new Database($this->configs);
        $cache = new DatabaseCache($database);
        self::assertSame($database, $cache->getDatabase());
        self::assertFalse($cache->isAutoClose());
    }

    public function testCreateTableIsIdempotent() : void
    {
        // @phpstan-ignore-next-line
        self::assertTrue($this->cache->createTable());
        self::assertTrue($this->cache->createTable()); // @phpstan-ignore-line
        self::assertTrue($this->cache->set('foo', 'bar', 60));
        self::assertSame('bar', $this->cache->get('foo'));
    }

    public function testGarbageCollectorRemovesExpiredRows() : void
    {
        self::assertTrue($this->cache->set('gone', 'x', 1));
        self::assertTrue($this->cache->set('kept', 'y', 60));
        \sleep(2);
        // Expired rows are skipped on read and left for the collector, so a
        // read never turns into a write.
        self::assertNull($this->cache->get('gone'));
        self::assertSame(2, $this->countRows());
        self::assertTrue($this->cache->gc()); // @phpstan-ignore-line
        self::assertSame(1, $this->countRows());
        self::assertSame('y', $this->cache->get('kept'));
    }

    public function testExpiredRowIsOverwrittenBySet() : void
    {
        self::assertTrue($this->cache->set('foo', 'first', 1));
        \sleep(2);
        self::assertNull($this->cache->get('foo'));
        self::assertTrue($this->cache->set('foo', 'second', 60));
        self::assertSame('second', $this->cache->get('foo'));
    }

    public function testCustomColumnNames() : void
    {
        $this->configs['table'] = 'CacheTestsCustomColumns';
        $this->configs['columns'] = [
            'key' => 'k',
            'value' => 'v',
            'ttl' => 'expires_at',
        ];
        $this->setCache();
        self::assertTrue($this->cache->createTable()); // @phpstan-ignore-line
        self::assertTrue($this->cache->set('foo', 'bar', 60));
        self::assertSame('bar', $this->cache->get('foo'));
        self::assertTrue($this->cache->add('other', 'value', 60));
        self::assertFalse($this->cache->add('other', 'again', 60));
        self::assertSame(
            ['foo' => 'bar', 'other' => 'value'],
            $this->cache->getMulti(['foo', 'other'])
        );
        self::assertTrue($this->cache->delete('foo'));
        self::assertNull($this->cache->get('foo'));
    }

    public function testGetMultiRunsOneStatementForAllKeys() : void
    {
        $this->cache->setMulti(['a' => 'x', 'b' => 'y'], 60);
        $before = $this->countStatements();
        self::assertSame(
            ['a' => 'x', 'b' => 'y', 'c' => null],
            $this->cache->getMulti(['a', 'b', 'c'])
        );
        self::assertSame(1, $this->countStatements() - $before);
    }

    public function testGetMultiWithoutKeys() : void
    {
        self::assertSame([], $this->cache->getMulti([]));
    }

    protected function countRows() : int
    {
        $database = $this->cache->getDatabase(); // @phpstan-ignore-line
        return (int) $database->query(
            'SELECT COUNT(*) AS `total` FROM ' . $database->protectIdentifier(
                $this->configs['table']
            )
        )->fetchArray()['total'];
    }

    /**
     * Number of prepared statements executed on this connection so far.
     */
    protected function countStatements() : int
    {
        $database = $this->cache->getDatabase(); // @phpstan-ignore-line
        return (int) $database->query(
            "SHOW SESSION STATUS LIKE 'Com_stmt_execute'"
        )->fetchArray()['Value'];
    }
}
