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
use Framework\Log\LogLevel;
use Memcached;
use OutOfBoundsException;
use Override;
use SensitiveParameter;

/**
 * Class MemcachedCache.
 *
 * @package cache
 */
class MemcachedCache extends Cache
{
    /**
     * Longest key Memcached will hold, in bytes.
     *
     * @since 4.2
     */
    protected const MAX_KEY_LENGTH = 250;
    protected Memcached $memcached;
    /**
     * Memcached Cache handler configurations.
     *
     * @var array<string,mixed>
     */
    protected array $configs = [
        'servers' => [
            [
                'host' => '127.0.0.1',
                'port' => 11211,
                'weight' => 0,
            ],
        ],
        'options' => [
            Memcached::OPT_BINARY_PROTOCOL => true,
        ],
    ];

    /**
     * MemcachedCache constructor.
     *
     * @param Memcached|array<string,mixed>|null $configs Driver specific
     * configurations. Set null to not initialize or a custom Memcached object.
     * @param string|null $prefix Keys prefix
     * @param Serializer|string $serializer Data serializer
     * @param Logger|null $logger Logger instance
     */
    public function __construct(
        #[SensitiveParameter]
        Memcached | array | null $configs = [],
        ?string $prefix = null,
        Serializer | string $serializer = Serializer::PHP,
        ?Logger $logger = null
    ) {
        parent::__construct($configs, $prefix, $serializer, $logger);
        if ($configs instanceof Memcached) {
            $this->setMemcached($configs);
            $this->setAutoClose(false);
        }
    }

    protected function initialize() : void
    {
        $this->validateConfigs();
        $this->connect();
    }

    /**
     * Accept any serializer, because this driver does not do the work itself.
     *
     * Values are handed to Memcached whole and it serializes them, using the
     * build it was compiled with rather than the extensions loaded here. So the
     * check the Cache base makes asks the wrong question: igbinary can be
     * missing from PHP while Memcached still has it, and the reverse. An
     * unusable choice is reported by connect, which logs what setOptions says.
     *
     * @since 4.2
     *
     * @param Serializer $serializer
     */
    #[Override]
    protected function assertSerializerAvailable(Serializer $serializer) : void
    {
    }

    /**
     * Memcached refuses a space or a control character in a key as well as an
     * over-long one, so both send the key to a digest.
     *
     * The space matters in practice: a key built from a name, a title or
     * anything a person typed will have one, and it works on every other
     * driver, so failing here would be the abstraction leaking.
     *
     * @since 4.2
     *
     * @param string $key The key, prefix included
     *
     * @return bool
     */
    #[Override]
    protected function isStorableKey(string $key) : bool
    {
        return parent::isStorableKey($key)
            && !\preg_match('/[\x00-\x20\x7F]/', $key);
    }

    protected function validateConfigs() : void
    {
        foreach ($this->configs['servers'] as $index => $config) {
            if (empty($config['host'])) {
                throw new OutOfBoundsException(
                    "Memcached host config empty on server '{$index}'"
                );
            }
        }
    }

    /**
     * Set custom Memcached instance.
     *
     * @since 3.2
     *
     * @param Memcached $memcached
     *
     * @return static
     */
    public function setMemcached(Memcached $memcached) : static
    {
        $this->memcached = $memcached;
        return $this;
    }

    /**
     * Get Memcached instance or null.
     *
     * @since 3.2
     *
     * @return Memcached|null
     */
    public function getMemcached() : ?Memcached
    {
        return $this->memcached ?? null;
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
        $key = $this->memcached->get($this->renderKey($key));
        return $key === false && $this->memcached->getResultCode() === Memcached::RES_NOTFOUND
            ? null
            : $key;
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
                $this->memcached->set($this->renderKey($key), $value, $this->makeTtl($ttl))
            );
        }
        return $this->memcached->set($this->renderKey($key), $value, $this->makeTtl($ttl));
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
                $this->memcached->add($this->renderKey($key), $value, $this->makeTtl($ttl))
            );
        }
        return $this->memcached->add($this->renderKey($key), $value, $this->makeTtl($ttl));
    }

    public function delete(string $key) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugDelete(
                $key,
                $start,
                $this->memcached->delete($this->renderKey($key))
            );
        }
        return $this->memcached->delete($this->renderKey($key));
    }

    public function flush() : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugFlush(
                $start,
                $this->memcached->flush()
            );
        }
        return $this->memcached->flush();
    }

    #[Override]
    public function close() : bool
    {
        return $this->memcached->quit();
    }

    protected function connect() : void
    {
        $this->configs['options'][Memcached::OPT_SERIALIZER] = match ($this->serializer) {
            Serializer::IGBINARY => Memcached::SERIALIZER_IGBINARY,
            Serializer::JSON => Memcached::SERIALIZER_JSON,
            Serializer::JSON_ARRAY => Memcached::SERIALIZER_JSON_ARRAY,
            Serializer::MSGPACK => Memcached::SERIALIZER_MSGPACK,
            default => Memcached::SERIALIZER_PHP,
        };
        $this->memcached = new Memcached();
        $pool = [];
        foreach ($this->configs['servers'] as $server) {
            $host = $server['host'] . ':' . ($server['port'] ?? 11211);
            if (\in_array($host, $pool, true)) {
                $this->log(
                    'Cache (memcached): Server pool already has ' . $host,
                    LogLevel::DEBUG
                );
                continue;
            }
            $result = $this->memcached->addServer(
                $server['host'],
                $server['port'] ?? 11211,
                $server['weight'] ?? 0
            );
            if ($result === false) {
                $this->log("Cache (memcached): Could not add {$host} to server pool");
            }
            $pool[] = $host;
        }
        $result = $this->memcached->setOptions($this->configs['options']);
        if ($result === false) {
            $this->log('Cache (memcached): ' . $this->memcached->getLastErrorMessage());
        }
        $this->assertPoolIsReachable($pool);
    }

    /**
     * Make sure at least one server in the pool answers.
     *
     * getStats returns an entry per server, holding false for the ones that
     * could not be reached. The array itself is not empty in that case, so it
     * has to be looked into rather than just tested for truth, or a pool where
     * every server is down would pass for connected.
     *
     * @since 4.2
     *
     * @param array<int,string> $pool The servers that were added
     *
     * @throws ConnectionException if no server in the pool answers
     */
    protected function assertPoolIsReachable(array $pool) : void
    {
        if (!$pool) {
            throw new ConnectionException(
                'Cache (memcached): No server was added to the pool'
            );
        }
        $stats = $this->memcached->getStats();
        if (\is_array($stats)) {
            foreach ($stats as $stat) {
                if ($stat !== false) {
                    return;
                }
            }
        }
        $message = $this->memcached->getLastErrorMessage();
        throw new ConnectionException(
            'Cache (memcached): Could not connect to any server in the pool: '
            . \implode(', ', $pool)
            . ($message === '' ? '' : '. ' . $message)
        );
    }
}
