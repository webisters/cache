<?php declare(strict_types=1);
/*
 * This file is part of Webisters Cache Library.
 *
 * (c) Hafiz Muhammad Moaz <thewebisters@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Framework\Cache;

use Framework\Database\Database;
use Framework\Database\Definition\Table\TableDefinition;
use Framework\Log\Logger;
use mysqli_sql_exception;
use Override;
use SensitiveParameter;

/**
 * Class DatabaseCache.
 *
 * A cache that survives restarts, deploys and evictions, backed by a table in
 * a MariaDB or MySQL server. Slower than the in-memory drivers, so it suits
 * items that are costly to rebuild rather than hot lookups.
 *
 * ```sql
 * CREATE TABLE `Cache` (
 *     `key` varchar(255) NOT NULL,
 *     `value` mediumblob NOT NULL,
 *     `ttl` bigint unsigned NOT NULL,
 *     PRIMARY KEY (`key`),
 *     KEY `ttl` (`ttl`)
 * );
 * ```
 *
 * The createTable method builds exactly that, so the schema does not have to
 * be written by hand.
 *
 * The `ttl` column holds the Unix timestamp the item expires at, which keeps
 * expiry free of the time zone of the server and of the connection.
 *
 * Expired rows are left in place and skipped on read. The gc method removes
 * them, and it also runs on destruct with the probability set by the `gc`
 * config, the same way the files driver collects its garbage.
 *
 * @package cache
 *
 * @since 4.2
 */
class DatabaseCache extends Cache
{
    protected ?Database $database;
    /**
     * Database Cache handler configurations.
     *
     * Everything Database accepts, plus the keys below.
     *
     * @var array<string,mixed>
     */
    protected array $configs = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => null,
        'password' => null,
        'schema' => null,
        'table' => 'Cache',
        'columns' => [
            'key' => 'key',
            'value' => 'value',
            'ttl' => 'ttl',
        ],
        'gc' => 1,
    ];

    /**
     * DatabaseCache constructor.
     *
     * @param Database|array<string,mixed>|null $configs Driver specific
     * configurations. Set null to not initialize or a custom Database object.
     * @param string|null $prefix Keys prefix
     * @param Serializer|string $serializer Data serializer
     * @param Logger|null $logger Logger instance
     */
    public function __construct(
        #[SensitiveParameter]
        Database | array | null $configs = [],
        ?string $prefix = null,
        Serializer | string $serializer = Serializer::PHP,
        ?Logger $logger = null
    ) {
        parent::__construct($configs, $prefix, $serializer, $logger);
        if ($configs instanceof Database) {
            $this->setDatabase($configs);
            $this->setAutoClose(false);
        }
    }

    public function __destruct()
    {
        if (\rand(1, 100) <= $this->configs['gc']) {
            $this->gc();
        }
        parent::__destruct();
    }

    protected function initialize() : void
    {
        $this->database = new Database($this->configs);
    }

    /**
     * Set custom Database instance.
     *
     * @param Database $database
     *
     * @return static
     */
    public function setDatabase(Database $database) : static
    {
        $this->database = $database;
        return $this;
    }

    /**
     * Get Database instance or null.
     *
     * @return Database|null
     */
    public function getDatabase() : ?Database
    {
        return $this->database ?? null;
    }

    /**
     * Get the table name based on custom/default configs.
     *
     * @return string The table name
     */
    protected function getTable() : string
    {
        return $this->configs['table'];
    }

    /**
     * Get a column name based on custom/default configs.
     *
     * @param string $key The columns config key
     *
     * @return string The column name
     */
    protected function getColumn(string $key) : string
    {
        return $this->configs['columns'][$key];
    }

    /**
     * Quote an identifier so reserved words such as `key` are safe to use.
     *
     * @param string $identifier
     *
     * @return string
     */
    protected function protect(string $identifier) : string
    {
        return $this->database->protectIdentifier($identifier);
    }

    /**
     * Create the table this cache reads and writes.
     *
     * Does nothing when the table is already there.
     *
     * ```php
     * $cache = new DatabaseCache($configs);
     * $cache->createTable();
     * ```
     *
     * @return bool TRUE on success, otherwise FALSE
     */
    public function createTable() : bool
    {
        $keyColumn = $this->getColumn('key');
        $valueColumn = $this->getColumn('value');
        $ttlColumn = $this->getColumn('ttl');
        try {
            $this->database->createTable($this->getTable())
                ->ifNotExists()
                ->definition(
                    static function (TableDefinition $definition) use (
                        $keyColumn,
                        $valueColumn,
                        $ttlColumn
                    ) : void {
                        $definition->column($keyColumn)->varchar(255)->primaryKey();
                        $definition->column($valueColumn)->mediumblob()->notNull();
                        $definition->column($ttlColumn)->bigint()->unsigned()->notNull();
                        $definition->index($ttlColumn)->key($ttlColumn);
                    }
                )->run();
            return true;
        } catch (mysqli_sql_exception $exception) {
            $this->log(
                'Cache (database): Table could not be created. ' . $exception->getMessage()
            );
            return false;
        }
    }

    public function get(string $key) : mixed
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugGet(
                $key,
                $start,
                $this->getValue($key)
            );
        }
        return $this->getValue($key);
    }

    protected function getValue(string $key) : mixed
    {
        $values = $this->getValues([$key]);
        return $values[$key] ?? null;
    }

    /**
     * Reads several items with one round trip.
     *
     * @param array<int,string> $keys List of items names to get
     *
     * @return array<string,mixed> associative array with key names and respective values
     */
    #[Override]
    public function getMulti(array $keys) : array
    {
        $values = \array_fill_keys($keys, null);
        if (!$keys) {
            return $values;
        }
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            $found = $this->getValues($keys);
            foreach ($keys as $key) {
                $this->addDebugGet($key, $start, $found[$key] ?? null);
            }
            return \array_replace($values, $found);
        }
        return \array_replace($values, $this->getValues($keys));
    }

    /**
     * Read the still valid items among the given names.
     *
     * @param array<int,string> $keys List of items names to get
     *
     * @return array<string,mixed> Only the names that were found, with their values
     */
    protected function getValues(array $keys) : array
    {
        $keys = \array_values(\array_unique($keys));
        $rendered = [];
        foreach ($keys as $key) {
            $rendered[$this->renderKey($key)] = $key;
        }
        $placeholders = \implode(', ', \array_fill(0, \count($rendered), '?'));
        $statement = 'SELECT ' . $this->protect($this->getColumn('key'))
            . ', ' . $this->protect($this->getColumn('value'))
            . ' FROM ' . $this->protect($this->getTable())
            . ' WHERE ' . $this->protect($this->getColumn('key')) . ' IN (' . $placeholders . ')'
            . ' AND ' . $this->protect($this->getColumn('ttl')) . ' > ?';
        try {
            $rows = $this->database->prepare($statement)
                ->query(...[...\array_keys($rendered), \time()])
                ->fetchArrayAll();
        } catch (mysqli_sql_exception $exception) {
            $this->log('Cache (database): ' . $exception->getMessage());
            return [];
        }
        $values = [];
        foreach ($rows as $row) {
            $key = $rendered[$row[$this->getColumn('key')]] ?? null;
            if ($key === null) {
                continue;
            }
            $values[$key] = $this->unserialize($row[$this->getColumn('value')]);
        }
        return $values;
    }

    public function set(string $key, mixed $value, ?int $ttl = null) : bool
    {
        if ($this->isExpiredTtl($ttl)) {
            // Already expired, so there is nothing worth storing, and any
            // item under that name is now stale.
            $this->delete($key);
            return true;
        }
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugSet(
                $key,
                $ttl,
                $start,
                $value,
                $this->setValue($key, $value, $ttl)
            );
        }
        return $this->setValue($key, $value, $ttl);
    }

    protected function setValue(string $key, mixed $value, ?int $ttl = null) : bool
    {
        $keyColumn = $this->protect($this->getColumn('key'));
        $valueColumn = $this->protect($this->getColumn('value'));
        $ttlColumn = $this->protect($this->getColumn('ttl'));
        // The assignments repeat the bound values instead of using VALUES(),
        // which MySQL 8 deprecated and MariaDB spells differently.
        $statement = 'INSERT INTO ' . $this->protect($this->getTable())
            . ' (' . $keyColumn . ', ' . $valueColumn . ', ' . $ttlColumn . ')'
            . ' VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE ' . $valueColumn . ' = ?, ' . $ttlColumn . ' = ?';
        $serialized = $this->serialize($value);
        $expires = \time() + $this->makeTtl($ttl);
        try {
            $this->database->prepare($statement)->exec(
                $this->renderKey($key),
                $serialized,
                $expires,
                $serialized,
                $expires
            );
            return true;
        } catch (mysqli_sql_exception $exception) {
            $this->log('Cache (database): ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * Writes an item only if it is not there, or is there but already expired.
     *
     * One statement does the check and the write, so concurrent callers cannot
     * both win.
     *
     * @param string $key The item name
     * @param mixed $value The item value
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return bool TRUE if the item was set, FALSE if it already existed or
     * failed to be set
     */
    #[Override]
    public function add(string $key, mixed $value, ?int $ttl = null) : bool
    {
        if ($this->isExpiredTtl($ttl)) {
            // Already expired, so nothing is added. An item already under
            // that name is left alone, as add never overwrites.
            return false;
        }
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugSet(
                $key,
                $ttl,
                $start,
                $value,
                $this->addValue($key, $value, $ttl)
            );
        }
        return $this->addValue($key, $value, $ttl);
    }

    protected function addValue(string $key, mixed $value, ?int $ttl = null) : bool
    {
        $keyColumn = $this->protect($this->getColumn('key'));
        $valueColumn = $this->protect($this->getColumn('value'));
        $ttlColumn = $this->protect($this->getColumn('ttl'));
        // An expired row is not an existing item, so it is overwritten. A row
        // that is still valid keeps its own value, leaving no rows affected,
        // which is what tells the caller it lost.
        $statement = 'INSERT INTO ' . $this->protect($this->getTable())
            . ' (' . $keyColumn . ', ' . $valueColumn . ', ' . $ttlColumn . ')'
            . ' VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' ' . $valueColumn . ' = IF(' . $ttlColumn . ' <= ?, ?, ' . $valueColumn . '),'
            . ' ' . $ttlColumn . ' = IF(' . $ttlColumn . ' <= ?, ?, ' . $ttlColumn . ')';
        $serialized = $this->serialize($value);
        $now = \time();
        $expires = $now + $this->makeTtl($ttl);
        try {
            $affected = $this->database->prepare($statement)->exec(
                $this->renderKey($key),
                $serialized,
                $expires,
                $now,
                $serialized,
                $now,
                $expires
            );
            return $affected > 0;
        } catch (mysqli_sql_exception $exception) {
            $this->log('Cache (database): ' . $exception->getMessage());
            return false;
        }
    }

    public function delete(string $key) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugDelete(
                $key,
                $start,
                $this->deleteValue($key)
            );
        }
        return $this->deleteValue($key);
    }

    protected function deleteValue(string $key) : bool
    {
        $statement = 'DELETE FROM ' . $this->protect($this->getTable())
            . ' WHERE ' . $this->protect($this->getColumn('key')) . ' = ?';
        try {
            return $this->database->prepare($statement)
                ->exec($this->renderKey($key)) > 0;
        } catch (mysqli_sql_exception $exception) {
            $this->log('Cache (database): ' . $exception->getMessage());
            return false;
        }
    }

    public function flush() : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugFlush(
                $start,
                $this->flushValues()
            );
        }
        return $this->flushValues();
    }

    protected function flushValues() : bool
    {
        try {
            $this->database->exec(
                'DELETE FROM ' . $this->protect($this->getTable())
            );
            return true;
        } catch (mysqli_sql_exception $exception) {
            $this->log('Cache (database): ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * Garbage collector.
     *
     * Deletes all expired items.
     *
     * @return bool TRUE if the expired items were deleted, FALSE if a fail occurs
     */
    public function gc() : bool
    {
        return $this->deleteExpired() !== null;
    }

    /**
     * Delete every expired row and report how many went.
     *
     * Expired rows are skipped on read but stay in the table until something
     * removes them, so a cache with a lot of short lived keys keeps the table
     * growing. This is the maintenance entry point for a scheduled job, its
     * count being something a cron job can log or alert on:
     *
     * ```php
     * $removed = $cache->purge();
     * ```
     *
     * Set the `gc` config to 0 to leave collection entirely to that job,
     * rather than paying for it on a share of the requests.
     *
     * @since 4.2
     *
     * @return int Number of rows removed, or zero when the deletion failed,
     * in which case the reason is logged
     */
    public function purge() : int
    {
        return $this->deleteExpired() ?? 0;
    }

    /**
     * @return int|null Number of rows removed, or null when the deletion failed
     */
    protected function deleteExpired() : ?int
    {
        if (!isset($this->database)) {
            return null;
        }
        $statement = 'DELETE FROM ' . $this->protect($this->getTable())
            . ' WHERE ' . $this->protect($this->getColumn('ttl')) . ' <= ?';
        try {
            return (int) $this->database->prepare($statement)->exec(\time());
        } catch (mysqli_sql_exception $exception) {
            $this->log('Cache (database): ' . $exception->getMessage());
            return null;
        }
    }

    #[Override]
    public function close() : bool
    {
        return isset($this->database)
            ? $this->database->close()
            : true;
    }
}
