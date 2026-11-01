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

use Framework\Log\Logger;
use Override;
use Redis;
use RedisException;
use SensitiveParameter;

/**
 * Class RedisCache.
 *
 * @package cache
 */
class RedisCache extends Cache
{
    /**
     * How many keys SCAN is asked for per round trip while sweeping.
     *
     * @since 4.4
     *
     * @var int
     */
    protected const SCAN_COUNT = 1000;

    protected Redis $redis;
    /**
     * Redis Cache handler configurations.
     *
     * @var array<string,mixed>
     */
    protected array $configs = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'timeout' => 0.0,
        'password' => null,
        'database' => null,
    ];

    /**
     * RedisCache constructor.
     *
     * @param Redis|array<string,mixed>|null $configs Driver specific
     * configurations. Set null to not initialize or a custom Redis object.
     * @param string|null $prefix Keys prefix
     * @param Serializer|string $serializer Data serializer
     * @param Logger|null $logger Logger instance
     */
    public function __construct(
        #[SensitiveParameter]
        Redis | array | null $configs = [],
        ?string $prefix = null,
        Serializer | string $serializer = Serializer::PHP,
        ?Logger $logger = null
    ) {
        parent::__construct($configs, $prefix, $serializer, $logger);
        if ($configs instanceof Redis) {
            $this->setRedis($configs);
            $this->setAutoClose(false);
        }
    }

    protected function initialize() : void
    {
        $this->connect();
    }

    protected function connect() : void
    {
        $this->redis = new Redis();
        $this->connectionStep('Connection', fn () => $this->redis->connect(
            $this->configs['host'],
            $this->configs['port'],
            $this->configs['timeout']
        ));
        if (isset($this->configs['password'])) {
            $this->connectionStep(
                'Authentication',
                fn () => $this->redis->auth($this->configs['password'])
            );
        }
        if (isset($this->configs['database'])) {
            $this->connectionStep(
                'Selection of database ' . $this->configs['database'],
                fn () => $this->redis->select($this->configs['database'])
            );
        }
    }

    /**
     * Run one step of the connection, turning a failure into a message that
     * says which step failed and on which server.
     *
     * phpredis reports a problem either by throwing or by returning false,
     * depending on the build and the error, so both are handled. A thrown
     * RedisException is kept as the previous exception.
     *
     * @since 4.2
     *
     * @param string $step What is being attempted, for the message
     * @param callable $callback The step to run
     *
     * @throws ConnectionException if the step fails
     */
    protected function connectionStep(string $step, callable $callback) : void
    {
        $server = $this->configs['host'] . ':' . $this->configs['port'];
        try {
            $result = $callback();
        } catch (RedisException $exception) {
            throw new ConnectionException(
                "Cache (redis): {$step} failed on {$server}. " . $exception->getMessage(),
                0,
                $exception
            );
        }
        if ($result === false) {
            throw new ConnectionException(
                "Cache (redis): {$step} failed on {$server}"
            );
        }
    }

    /**
     * Set custom Redis instance.
     *
     * @since 3.2
     *
     * @param Redis $redis
     *
     * @return static
     */
    public function setRedis(Redis $redis) : static
    {
        $this->redis = $redis;
        return $this;
    }

    /**
     * Get Redis instance or null.
     *
     * @since 3.2
     *
     * @return Redis|null
     */
    public function getRedis() : ?Redis
    {
        return $this->redis ?? null;
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
        $value = $this->redis->get($this->renderKey($key));
        if ($value === false) {
            return null;
        }
        return $this->unserialize($value);
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
                $this->redis->set(
                    $this->renderKey($key),
                    $this->serialize($value),
                    ['ex' => $this->makeTtl($ttl)]
                )
            );
        }
        return $this->redis->set(
            $this->renderKey($key),
            $this->serialize($value),
            ['ex' => $this->makeTtl($ttl)]
        );
    }

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
        return (bool) $this->redis->set(
            $this->renderKey($key),
            $this->serialize($value),
            ['nx', 'ex' => $this->makeTtl($ttl)]
        );
    }

    public function delete(string $key) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugDelete(
                $key,
                $start,
                (bool) $this->redis->del($this->renderKey($key))
            );
        }
        return (bool) $this->redis->del($this->renderKey($key));
    }

    /**
     * Removes this cache's items from the storage.
     *
     * When a prefix is set, only the keys carrying it are removed, found with
     * SCAN and deleted in batches. Redis sets no key length limit, so this
     * driver never digests a key, which means every key this instance wrote
     * really does start with the prefix and the sweep is exact.
     *
     * Without a prefix this instance's keys cannot be told apart from anything
     * else in the database, so the selected database is flushed instead. It is
     * never the whole server: see RedisCache::flushStorage().
     *
     * @see RedisCache::flushStorage()
     *
     * @return bool
     */
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
        if ((string) $this->prefix === '') {
            return $this->redis->flushDb();
        }
        return $this->deleteByPattern($this->escapePattern((string) $this->prefix) . '*');
    }

    /**
     * Removes every key from the database this instance selected.
     *
     * This ignores the prefix, so it takes anything else sharing the same
     * database with it. It is still scoped to that one database and never
     * reaches the rest of the server, which is why FLUSHALL is not used
     * anywhere in this driver.
     *
     * @since 4.4
     * @see RedisCache::flush()
     *
     * @return bool
     */
    public function flushStorage() : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugFlush(
                $start,
                $this->redis->flushDb()
            );
        }
        return $this->redis->flushDb();
    }

    /**
     * Deletes every key matching a glob pattern, in batches.
     *
     * SCAN is used rather than KEYS so a large keyspace does not block the
     * server for the length of the sweep.
     *
     * @param string $pattern A Redis glob pattern
     *
     * @return bool
     */
    protected function deleteByPattern(string $pattern) : bool
    {
        $iterator = null;
        $status = true;
        do {
            $keys = $this->redis->scan($iterator, $pattern, static::SCAN_COUNT);
            if ($keys === false) {
                return false;
            }
            if ($keys !== [] && $this->redis->del($keys) === false) {
                $status = false;
            }
        } while ($iterator > 0);
        return $status;
    }

    /**
     * Escapes the glob metacharacters Redis pattern matching understands.
     *
     * A prefix is a literal string, so a prefix containing *, ?, [, ] or \\
     * must not be read as a pattern or the sweep would match the wrong keys.
     *
     * @param string $pattern
     *
     * @return string
     */
    protected function escapePattern(string $pattern) : string
    {
        return \addcslashes($pattern, '*?[]^\\');
    }

    #[Override]
    public function close() : bool
    {
        return $this->redis->close();
    }
}
