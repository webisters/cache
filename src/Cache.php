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

use Framework\Cache\Debug\CacheCollector;
use Framework\Log\Logger;
use Framework\Log\LogLevel;
use InvalidArgumentException;
use JetBrains\PhpStorm\Pure;
use RuntimeException;
use SensitiveParameter;

/**
 * Class Cache.
 *
 * @todo Add way to use internal serializer in handlers
 *
 * @package cache
 */
abstract class Cache
{
    /**
     * Marks a key as this library's own bookkeeping rather than an item.
     *
     * Locks, recomputation metadata and tag versions all live in the same
     * storage as the items, so they need somewhere an ordinary key will not
     * reach. Names such as `lock.foo` were not enough: an application is quite
     * entitled to store that, and then the two are the same item.
     *
     * The `@` and `:` here are among the characters PSR-16 reserves and
     * forbids in a key, so nothing coming through the SimpleCache adapter can
     * produce one of these, and no ordinary key is likely to either. Treat any
     * key starting with this as belonging to the library.
     *
     * @since 4.2
     */
    public const RESERVED_PREFIX = '@w:';
    /**
     * Longest key the storage will hold, in bytes, or zero for no limit.
     *
     * Counted in bytes rather than characters, since that is what the storages
     * measure. A driver whose backend refuses longer keys, or truncates them,
     * sets this and renderKey stands in a digest past it.
     *
     * @since 4.2
     */
    protected const MAX_KEY_LENGTH = 0;
    /**
     * Driver specific configurations.
     *
     * @var array<string,mixed>
     */
    protected array $configs = [];
    /**
     * Keys prefix.
     *
     * @var string|null
     */
    protected ?string $prefix = null;
    /**
     * Data serializer.
     *
     * @var Serializer
     */
    protected Serializer $serializer;
    /**
     * The Logger instance if is set.
     *
     * @var Logger|null
     */
    protected ?Logger $logger;
    /**
     * The default Time To Live value.
     *
     * Used when set methods has the $ttl param as null.
     *
     * @var int
     */
    protected int $defaultTtl = 60;
    /**
     * The Time To Live of the locks acquired by the lock method, in seconds.
     *
     * Caps how long a worker that died mid-computation can hold others back.
     *
     * @var int
     */
    protected int $lockTtl = 30;
    /**
     * How long to wait for the lock holder to publish a value, in seconds.
     *
     * @var float
     */
    protected float $lockWait = 5.0;
    /**
     * How long to sleep between the reads made while waiting for the lock
     * holder to publish a value, in microseconds.
     *
     * @var int
     */
    protected int $lockSleep = 50000;
    protected CacheCollector $debugCollector;
    protected bool $autoClose = true;

    /**
     * Cache constructor.
     *
     * @param mixed $configs Driver specific configurations. Set
     * null to not initialize and set a custom object.
     * @param string|null $prefix Keys prefix
     * @param Serializer|string $serializer Data serializer
     * @param Logger|null $logger Logger instance
     */
    public function __construct(
        #[SensitiveParameter]
        mixed $configs = [],
        ?string $prefix = null,
        Serializer | string $serializer = Serializer::PHP,
        ?Logger $logger = null
    ) {
        $this->prefix = $prefix;
        $this->setSerializer($serializer);
        $this->logger = $logger;
        if (\is_array($configs)) {
            if ($configs) {
                $this->setConfigs($configs);
            }
            $this->initialize();
        }
    }

    public function __destruct()
    {
        if ($this->isAutoClose()) {
            $this->close();
        }
    }

    /**
     * @since 4.1
     *
     * @return bool
     */
    public function isAutoClose() : bool
    {
        return $this->autoClose;
    }

    /**
     * @since 4.1
     *
     * @param bool $autoClose True to enable auto close, false to disable
     *
     * @return static
     */
    public function setAutoClose(bool $autoClose) : static
    {
        $this->autoClose = $autoClose;
        return $this;
    }

    /**
     * @since 4.1
     *
     * @param array<string,mixed> $configs
     *
     * @return static
     */
    protected function setConfigs(array $configs) : static
    {
        $this->configs = \array_replace_recursive($this->configs, $configs);
        return $this;
    }

    /**
     * @since 4.1
     *
     * @param Serializer|string $serializer
     *
     * @return static
     */
    protected function setSerializer(Serializer | string $serializer) : static
    {
        if (\is_string($serializer)) {
            $serializer = Serializer::from($serializer);
        }
        $this->assertSerializerAvailable($serializer);
        $this->serializer = $serializer;
        return $this;
    }

    /**
     * Refuse a serializer this installation cannot use.
     *
     * Without this the choice looks fine until the first write, which fails
     * with an undefined function far from the configuration that caused it.
     *
     * Drivers that hand serialization to the storage rather than doing it here
     * override this, since what they need is not the same thing.
     *
     * @since 4.2
     *
     * @param Serializer $serializer
     *
     * @throws RuntimeException if the serializer needs an extension that is
     * not loaded
     */
    protected function assertSerializerAvailable(Serializer $serializer) : void
    {
        if ($serializer->isAvailable()) {
            return;
        }
        throw new RuntimeException(
            'Serializer ' . $serializer->value . ' needs the '
            . $serializer->getExtension() . ' extension, which is not loaded. '
            . 'Available: ' . \implode(
                ', ',
                \array_column(Serializer::available(), 'value')
            )
        );
    }

    public function getSerializer() : Serializer
    {
        return $this->serializer;
    }

    /**
     * Initialize Cache handlers and configurations.
     */
    protected function initialize() : void
    {
    }

    protected function log(
        string $message,
        LogLevel $level = LogLevel::ERROR
    ) : void {
        if (isset($this->logger)) {
            $this->logger->log($level, $message);
        }
    }

    /**
     * Get the default Time To Live value in seconds.
     *
     * @return int
     */
    #[Pure]
    public function getDefaultTtl() : int
    {
        return $this->defaultTtl;
    }

    /**
     * Set the default Time To Live value in seconds.
     *
     * @param int $seconds An integer greater than zero
     *
     * @return static
     */
    public function setDefaultTtl(int $seconds) : static
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException(
                'Default TTL must be greater than 0. ' . $seconds . ' given'
            );
        }
        $this->defaultTtl = $seconds;
        return $this;
    }

    /**
     * Make the Time To Live value.
     *
     * @param int|null $seconds TTL value or null to use the default
     *
     * @return int The input $seconds or the $defaultTtl as integer
     */
    #[Pure]
    protected function makeTtl(?int $seconds) : int
    {
        return $seconds ?? $this->getDefaultTtl();
    }

    /**
     * Tell whether a Time To Live has already run out.
     *
     * A TTL of zero or less describes an item that is expired the moment it is
     * written, so there is nothing to store. Drivers check this before writing
     * because the storages disagree about what a zero means on their own: APCu
     * and Memcached read it as never expire, Redis refuses it outright, and the
     * files, array and database drivers write an item that is already stale.
     * Deciding it here keeps one meaning across all of them.
     *
     * Null is not a value here, it means no TTL was given, so the instance
     * default applies.
     *
     * @since 4.2
     *
     * @param int|null $seconds The TTL as given to a write
     *
     * @return bool TRUE when nothing should be stored, otherwise FALSE
     */
    #[Pure]
    protected function isExpiredTtl(?int $seconds) : bool
    {
        return $seconds !== null && $seconds <= 0;
    }

    /**
     * Gets one item from the cache storage.
     *
     * @param string $key The item name
     *
     * @return mixed The item value or null if not found
     */
    abstract public function get(string $key) : mixed;

    /**
     * Gets multi items from the cache storage.
     *
     * @param array<int,string> $keys List of items names to get
     *
     * @return array<string,mixed> associative array with key names and respective values
     */
    public function getMulti(array $keys) : array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }
        return $values;
    }

    /**
     * Sets one item to the cache storage.
     *
     * @param string $key The item name
     * @param mixed $value The item value
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return bool TRUE if the item was set, FALSE if fail to set
     */
    abstract public function set(string $key, mixed $value, ?int $ttl = null) : bool;

    /**
     * Sets multi items to the cache storage.
     *
     * @param array<string,mixed> $data Associative array with key names and respective values
     * @param int|null $ttl The Time To Live for all the items or null to use the default
     *
     * @return array<string,bool> associative array with key names and respective set status
     */
    public function setMulti(array $data, ?int $ttl = null) : array
    {
        foreach ($data as $key => &$value) {
            $value = $this->set($key, $value, $ttl);
        }
        return $data;
    }

    /**
     * Deletes one item from the cache storage.
     *
     * @param string $key the item name
     *
     * @return bool TRUE if the item was deleted, FALSE if fail to delete
     */
    abstract public function delete(string $key) : bool;

    /**
     * Deletes multi items from the cache storage.
     *
     * @param array<int,string> $keys List of items names to be deleted
     *
     * @return array<string,bool> associative array with key names and respective delete status
     */
    public function deleteMulti(array $keys) : array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->delete($key);
        }
        return $values;
    }

    /**
     * Flush the cache storage.
     *
     * @return bool TRUE if all items are deleted, otherwise FALSE
     */
    abstract public function flush() : bool;

    /**
     * Sets one item to the cache storage only if it is not there yet.
     *
     * The check and the write are one atomic operation on every driver that
     * offers the primitive, which makes this usable as a mutual exclusion
     * building block. The Cache base implementation is a non-atomic get then
     * set fallback, kept for custom drivers that cannot do better.
     *
     * @since 4.2
     *
     * @param string $key The item name
     * @param mixed $value The item value
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return bool TRUE if the item was set, FALSE if it already existed or
     * failed to be set
     */
    public function add(string $key, mixed $value, ?int $ttl = null) : bool
    {
        if ($this->get($key) !== null) {
            return false;
        }
        return $this->set($key, $value, $ttl);
    }

    /**
     * Get the Time To Live of the locks in seconds.
     *
     * @since 4.2
     *
     * @return int
     */
    public function getLockTtl() : int
    {
        return $this->lockTtl;
    }

    /**
     * Set the Time To Live of the locks in seconds.
     *
     * This caps how long a worker that died while computing a value can hold
     * the other workers back.
     *
     * @since 4.2
     *
     * @param int $seconds An integer greater than zero
     *
     * @throws InvalidArgumentException if $seconds is lower than 1
     *
     * @return static
     */
    public function setLockTtl(int $seconds) : static
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException(
                'Lock TTL must be greater than 0. ' . $seconds . ' given'
            );
        }
        $this->lockTtl = $seconds;
        return $this;
    }

    /**
     * Get how long a worker waits for the lock holder to publish a value, in
     * seconds.
     *
     * @since 4.2
     *
     * @return float
     */
    public function getLockWait() : float
    {
        return $this->lockWait;
    }

    /**
     * Set how long a worker waits for the lock holder to publish a value, in
     * seconds.
     *
     * When the wait runs out the worker computes the value itself instead of
     * stalling any longer.
     *
     * @since 4.2
     *
     * @param float $seconds A number greater than zero
     *
     * @throws InvalidArgumentException if $seconds is not greater than zero
     *
     * @return static
     */
    public function setLockWait(float $seconds) : static
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException(
                'Lock wait must be greater than 0. ' . $seconds . ' given'
            );
        }
        $this->lockWait = $seconds;
        return $this;
    }

    /**
     * Get how long a waiting worker sleeps between reads, in microseconds.
     *
     * @since 4.2
     *
     * @return int
     */
    public function getLockSleep() : int
    {
        return $this->lockSleep;
    }

    /**
     * Set how long a waiting worker sleeps between reads, in microseconds.
     *
     * @since 4.2
     *
     * @param int $microseconds An integer greater than zero
     *
     * @throws InvalidArgumentException if $microseconds is lower than 1
     *
     * @return static
     */
    public function setLockSleep(int $microseconds) : static
    {
        if ($microseconds < 1) {
            throw new InvalidArgumentException(
                'Lock sleep must be greater than 0. ' . $microseconds . ' given'
            );
        }
        $this->lockSleep = $microseconds;
        return $this;
    }

    /**
     * Try to acquire an exclusive lock over an item name.
     *
     * The lock is advisory: it only blocks the workers that ask for it. It
     * expires on its own, so a worker that dies while holding it cannot block
     * the others forever.
     *
     * ```php
     * if ($cache->lock('report')) {
     *     try {
     *         // only one worker at a time gets here
     *     } finally {
     *         $cache->unlock('report');
     *     }
     * }
     * ```
     *
     * @since 4.2
     *
     * @param string $key The item name to lock
     * @param int|null $ttl The lock Time To Live or null to use the default
     *
     * @return bool TRUE if the lock was acquired, FALSE if it is held elsewhere
     */
    public function lock(string $key, ?int $ttl = null) : bool
    {
        return $this->add(
            $this->renderLockKey($key),
            1,
            $ttl ?? $this->getLockTtl()
        );
    }

    /**
     * Release a lock acquired with the lock method.
     *
     * @since 4.2
     *
     * @param string $key The item name to unlock
     *
     * @return bool TRUE if the lock was released, otherwise FALSE
     */
    public function unlock(string $key) : bool
    {
        return $this->delete($this->renderLockKey($key));
    }

    /**
     * Make the storage key of the lock over an item name.
     *
     * @since 4.2
     *
     * @param string $key The item name
     *
     * @return string
     */
    protected function renderLockKey(string $key) : string
    {
        return static::RESERVED_PREFIX . 'lock:' . $key;
    }

    /**
     * Make the storage key of the recomputation metadata of an item.
     *
     * @since 4.2
     *
     * @param string $key The item name
     *
     * @return string
     */
    protected function renderStampedeKey(string $key) : string
    {
        return static::RESERVED_PREFIX . 'stampede:' . $key;
    }

    /**
     * Gets one item from the cache storage, computing and storing it when it
     * is not there yet.
     *
     * ```php
     * $posts = $cache->remember('posts', function () {
     *     return $this->database->select()->from('posts')->run()->fetchArrayAll();
     * }, 300);
     * ```
     *
     * The callback runs only on a miss. Its return value is stored and then
     * given back, so the item is computed once and read from the storage
     * afterwards.
     *
     * When the callback returns null nothing is stored, since a null item is
     * indistinguishable from a missing one, and the callback runs again on the
     * next call.
     *
     * @since 4.2
     *
     * @param string $key The item name
     * @param callable $callback Called on a miss to compute the item value.
     * Receives the item name as its only argument
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return mixed The cached value, or the value returned by the callback
     */
    public function remember(string $key, callable $callback, ?int $ttl = null) : mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback($key);
        if ($value !== null) {
            $this->set($key, $value, $ttl);
        }
        return $value;
    }

    /**
     * Gets one item from the cache storage, computing and storing it when it
     * is not there yet.
     *
     * Alias of the remember method.
     *
     * @since 4.2
     *
     * @param string $key The item name
     * @param callable $callback Called on a miss to compute the item value.
     * Receives the item name as its only argument
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return mixed The cached value, or the value returned by the callback
     */
    public function getOrSet(string $key, callable $callback, ?int $ttl = null) : mixed
    {
        return $this->remember($key, $callback, $ttl);
    }

    /**
     * Gets one item from the cache storage, computing and storing it when it
     * is not there yet, guarded against cache stampede.
     *
     * Same contract as the remember method, but when an expensive item expires
     * under load it is recomputed once instead of once per concurrent request.
     * Two mechanisms cooperate:
     *
     * - **Early recompute.** Each write records how long the computation took.
     *   As the expiry approaches, a request is picked at random to refresh the
     *   item ahead of time, with a probability that grows the closer expiry
     *   gets and the more expensive the item was. Everyone else keeps reading
     *   the value that is still valid, so nobody waits.
     * - **Locking.** When the item is really gone, the first worker to take
     *   the lock computes it while the others wait for the result. A worker
     *   that waits longer than the lock wait computes the value itself rather
     *   than stalling, and the lock expires on its own, so a worker that dies
     *   mid-computation cannot block the rest.
     *
     * ```php
     * $report = $cache->rememberProtected('report', function () {
     *     return $this->buildExpensiveReport();
     * }, 300);
     * ```
     *
     * Items are stored exactly as the remember method stores them, so a plain
     * get reads them back and a plain set overwrites them. Only the bookkeeping
     * lives in companion keys.
     *
     * @since 4.2
     *
     * @param string $key The item name
     * @param callable $callback Called to compute the item value. Receives the
     * item name as its only argument
     * @param int|null $ttl The Time To Live for the item or null to use the default
     * @param float $beta How eagerly to recompute ahead of the expiry. Zero
     * disables early recompute and leaves only the locking, 1.0 is a sane
     * default, higher values recompute earlier
     *
     * @throws InvalidArgumentException if $beta is negative
     *
     * @return mixed The cached value, or the value returned by the callback
     */
    public function rememberProtected(
        string $key,
        callable $callback,
        ?int $ttl = null,
        float $beta = 1.0
    ) : mixed {
        if ($beta < 0) {
            throw new InvalidArgumentException(
                'Beta must not be negative. ' . $beta . ' given'
            );
        }
        $ttl = $this->makeTtl($ttl);
        $value = $this->get($key);
        $isMiss = $value === null;
        if (!$isMiss && !$this->shouldRecomputeEarly($key, $beta)) {
            return $value;
        }
        if ($this->lock($key)) {
            try {
                if ($isMiss) {
                    // Another worker may have stored it between the read above
                    // and the lock being taken.
                    $fresh = $this->get($key);
                    if ($fresh !== null) {
                        return $fresh;
                    }
                }
                return $this->computeAndStore($key, $callback, $ttl);
            } finally {
                $this->unlock($key);
            }
        }
        if (!$isMiss) {
            // Someone else is refreshing it early. The value is still valid,
            // so serve it instead of waiting.
            return $value;
        }
        $value = $this->waitForValue($key);
        if ($value !== null) {
            return $value;
        }
        // The lock holder never published a value. Compute it rather than
        // making the caller wait any longer.
        return $this->computeAndStore($key, $callback, $ttl);
    }

    /**
     * Compute an item value, store it, and record what it cost to compute.
     *
     * @since 4.2
     *
     * @param string $key The item name
     * @param callable $callback Called to compute the item value
     * @param int $ttl The Time To Live for the item
     *
     * @return mixed The value returned by the callback
     */
    protected function computeAndStore(string $key, callable $callback, int $ttl) : mixed
    {
        $start = \microtime(true);
        $value = $callback($key);
        if ($value === null) {
            $this->delete($this->renderStampedeKey($key));
            return null;
        }
        $delta = \microtime(true) - $start;
        $this->set($key, $value, $ttl);
        $this->set(
            $this->renderStampedeKey($key),
            $delta . '|' . (\time() + $ttl),
            $ttl
        );
        return $value;
    }

    /**
     * Decide whether an item that is still valid should be recomputed now.
     *
     * Implements probabilistic early expiration: the deadline of an item is
     * moved backwards by a random amount proportional to what the item cost to
     * compute, so an expensive item is refreshed earlier, and only one of the
     * concurrent readers is likely to be picked.
     *
     * @since 4.2
     *
     * @param string $key The item name
     * @param float $beta How eagerly to recompute. Zero never recomputes early
     *
     * @return bool TRUE to recompute the item now, otherwise FALSE
     */
    protected function shouldRecomputeEarly(string $key, float $beta) : bool
    {
        if ($beta <= 0.0) {
            return false;
        }
        $metadata = $this->get($this->renderStampedeKey($key));
        if (!\is_string($metadata) || !\str_contains($metadata, '|')) {
            return false;
        }
        [$delta, $expires] = \explode('|', $metadata, 2);
        if (!\is_numeric($delta) || !\is_numeric($expires)) {
            return false;
        }
        $delta = (float) $delta;
        if ($delta <= 0.0) {
            return false;
        }
        $random = \mt_rand(1, \mt_getrandmax()) / \mt_getrandmax();
        return \microtime(true) - ($delta * $beta * \log($random)) >= (float) $expires;
    }

    /**
     * Wait for the worker holding the lock over an item to publish its value.
     *
     * @since 4.2
     *
     * @param string $key The item name
     *
     * @return mixed The value that showed up, or null if the wait ran out
     */
    protected function waitForValue(string $key) : mixed
    {
        $deadline = \microtime(true) + $this->getLockWait();
        while (\microtime(true) < $deadline) {
            \usleep($this->getLockSleep());
            $value = $this->get($key);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Get a cache view bound to a set of tags.
     *
     * Items written through the returned instance are grouped under the given
     * tags and can be invalidated together with its flush method:
     *
     * ```php
     * $cache->tags('posts')->set('list', $posts);
     * $cache->tags('posts')->get('list');
     * $cache->tags('posts')->flush(); // drops every item tagged 'posts'
     * ```
     *
     * Tagged items live in their own key namespace, so they never collide
     * with items set through the untagged methods.
     *
     * @since 4.2
     *
     * @param array<int,string>|string $tags One tag name or a list of them
     *
     * @throws InvalidArgumentException if $tags is empty or holds an empty tag
     *
     * @return TaggedCache
     */
    public function tags(array | string $tags) : TaggedCache
    {
        return new TaggedCache($this, (array) $tags);
    }

    /**
     * Invalidates every item stored under any of the given tags.
     *
     * @since 4.2
     *
     * @param array<int,string>|string $tags One tag name or a list of them
     *
     * @throws InvalidArgumentException if $tags is empty or holds an empty tag
     *
     * @return bool TRUE if all tags were invalidated, otherwise FALSE
     */
    public function flushTags(array | string $tags) : bool
    {
        $status = true;
        foreach ((array) $tags as $tag) {
            if (!$this->tags([$tag])->flush()) {
                $status = false;
            }
        }
        return $status;
    }

    /**
     * Increments the value of one item.
     *
     * @param string $key The item name
     * @param int $offset The value to increment
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return int The current item value
     */
    public function increment(string $key, int $offset = 1, ?int $ttl = null) : int
    {
        return $this->updateCounter($key, (int) \abs($offset), $ttl);
    }

    /**
     * Decrements the value of one item.
     *
     * @param string $key The item name
     * @param int $offset The value to decrement
     * @param int|null $ttl The Time To Live for the item or null to use the default
     *
     * @return int The current item value
     */
    public function decrement(string $key, int $offset = 1, ?int $ttl = null) : int
    {
        return $this->updateCounter($key, -(int) \abs($offset), $ttl);
    }

    /**
     * Move a counter by an amount, without losing a concurrent move.
     *
     * Reading, adding and writing back is three steps, and two workers doing
     * them at once both read the same number and both write the same result,
     * so one of the two moves disappears. Measured on the files driver before
     * this existed: five workers adding one 200 times each finished at 221 of
     * the 1000 they had counted.
     *
     * The write is therefore made under the lock the item already has, which
     * is atomic on every driver because it is built on add. A worker that
     * cannot take the lock within the lock wait moves the counter anyway,
     * since a count that may be short is better than an unbounded stall, and
     * says so in the log.
     *
     * @since 4.2
     *
     * @param string $key The counter name
     * @param int $amount How far to move it, negative to go down
     * @param int|null $ttl The Time To Live for the counter or null to use the default
     *
     * @return int The value the counter now holds
     */
    protected function updateCounter(string $key, int $amount, ?int $ttl) : int
    {
        $locked = $this->lockCounter($key);
        if (!$locked) {
            $this->log(
                'Cache: Counter ' . $key . ' was moved without its lock, which was'
                . ' held elsewhere for longer than the lock wait'
            );
        }
        try {
            $value = (int) $this->get($key) + $amount;
            $this->set($key, $value, $ttl);
            return $value;
        } finally {
            if ($locked) {
                $this->unlock($key);
            }
        }
    }

    /**
     * Take the lock over a counter, waiting for it if another worker has it.
     *
     * Backs off from a very short first wait up to the lock sleep, so an
     * uncontended counter is barely slowed while a busy one does not spin.
     *
     * @since 4.2
     *
     * @param string $key The counter name
     *
     * @return bool TRUE if the lock was taken, FALSE if the wait ran out
     */
    protected function lockCounter(string $key) : bool
    {
        $deadline = \microtime(true) + $this->getLockWait();
        $sleep = 1000;
        while (true) {
            if ($this->lock($key)) {
                return true;
            }
            if (\microtime(true) >= $deadline) {
                return false;
            }
            \usleep($sleep);
            $sleep = \min($sleep * 2, $this->getLockSleep());
        }
    }

    /**
     * Close the cache storage.
     *
     * @since 4.1
     *
     * @return bool TRUE on success, otherwise FALSE
     */
    public function close() : bool
    {
        return true;
    }

    /**
     * Make the storage key of an item, standing in a digest when the storage
     * cannot hold the key itself.
     *
     * A driver says what it can hold through MAX_KEY_LENGTH and, where there
     * is more to it than length, isStorableKey. Everything else keeps the key
     * as it is, so a stored name still reads like the one that was asked for.
     *
     * @param string $key The item name
     *
     * @return string
     */
    protected function renderKey(string $key) : string
    {
        $key = $this->prefix . $key;
        return $this->isStorableKey($key) ? $key : $this->digestKey($key);
    }

    /**
     * Tell whether the storage can hold a key as it stands.
     *
     * Length is the usual limit, so it is handled here. A driver with more to
     * say about what a key may look like overrides this.
     *
     * @since 4.2
     *
     * @param string $key The key, prefix included
     *
     * @return bool
     */
    protected function isStorableKey(string $key) : bool
    {
        return static::MAX_KEY_LENGTH < 1
            || \strlen($key) <= static::MAX_KEY_LENGTH;
    }

    /**
     * Stand in a digest for a key the storage cannot hold.
     *
     * Deterministic, so reads, writes and deletions all agree on it, and
     * carrying the reserved prefix so it is recognisable as this library's
     * doing when looking at the storage. The alternative, letting the storage
     * refuse or truncate the key, costs the item.
     *
     * @since 4.2
     *
     * @param string $key The key, prefix included
     *
     * @return string
     */
    protected function digestKey(string $key) : string
    {
        return static::RESERVED_PREFIX . 'sha256:' . \hash('sha256', $key);
    }

    /**
     * @param mixed $value
     *
     * @throws \JsonException
     *
     * @return string
     */
    protected function serialize(mixed $value) : string
    {
        if ($this->serializer === Serializer::IGBINARY) {
            return $this->assertSerialized(\igbinary_serialize($value));
        }
        if ($this->serializer === Serializer::JSON
            || $this->serializer === Serializer::JSON_ARRAY
        ) {
            return \json_encode($value, \JSON_THROW_ON_ERROR);
        }
        if ($this->serializer === Serializer::MSGPACK) {
            return $this->assertSerialized(\msgpack_serialize($value));
        }
        return \serialize($value);
    }

    /**
     * Make sure a serializer produced something storable.
     *
     * igbinary and msgpack report a failure by handing back null instead of
     * throwing, which without this would only surface later as a return type
     * error from serialize, naming neither the value nor the serializer.
     *
     * @since 4.2
     *
     * @param string|null $serialized
     *
     * @throws RuntimeException if the value could not be serialized
     *
     * @return string
     */
    protected function assertSerialized(?string $serialized) : string
    {
        if ($serialized === null) {
            throw new RuntimeException(
                'Value could not be serialized with ' . $this->serializer->value
            );
        }
        return $serialized;
    }

    /**
     * @param string $value
     *
     * @return mixed
     */
    protected function unserialize(string $value) : mixed
    {
        if ($this->serializer === Serializer::IGBINARY) {
            return @\igbinary_unserialize($value);
        }
        if ($this->serializer === Serializer::JSON) {
            return \json_decode($value);
        }
        if ($this->serializer === Serializer::JSON_ARRAY) {
            return \json_decode($value, true);
        }
        if ($this->serializer === Serializer::MSGPACK) {
            return \msgpack_unserialize($value);
        }
        return \unserialize($value, ['allowed_classes' => true]);
    }

    public function setDebugCollector(CacheCollector $debugCollector) : static
    {
        $this->debugCollector = $debugCollector;
        $this->debugCollector->setInfo([
            'class' => static::class,
            'configs' => $this->configs,
            'prefix' => $this->prefix,
            'serializer' => $this->serializer->value,
        ]);
        return $this;
    }

    protected function addDebugGet(string $key, float $start, mixed $value) : mixed
    {
        $end = \microtime(true);
        $this->debugCollector->addData([
            'start' => $start,
            'end' => $end,
            'command' => 'GET',
            'status' => $value === null ? 'FAIL' : 'OK',
            'key' => $key,
            'value' => \get_debug_type($value),
        ]);
        return $value;
    }

    protected function addDebugSet(string $key, ?int $ttl, float $start, mixed $value, bool $status) : bool
    {
        $end = \microtime(true);
        $this->debugCollector->addData([
            'start' => $start,
            'end' => $end,
            'command' => 'SET',
            'status' => $status ? 'OK' : 'FAIL',
            'key' => $key,
            'value' => \get_debug_type($value),
            'ttl' => $this->makeTtl($ttl),
        ]);
        return $status;
    }

    protected function addDebugDelete(string $key, float $start, bool $status) : bool
    {
        $end = \microtime(true);
        $this->debugCollector->addData([
            'start' => $start,
            'end' => $end,
            'command' => 'DELETE',
            'status' => $status ? 'OK' : 'FAIL',
            'key' => $key,
        ]);
        return $status;
    }

    protected function addDebugFlush(float $start, bool $status) : bool
    {
        $end = \microtime(true);
        $this->debugCollector->addData([
            'start' => $start,
            'end' => $end,
            'command' => 'FLUSH',
            'status' => $status ? 'OK' : 'FAIL',
        ]);
        return $status;
    }
}
