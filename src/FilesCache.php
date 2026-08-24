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
use InvalidArgumentException;
use JetBrains\PhpStorm\Pure;
use Override;
use RuntimeException;
use SensitiveParameter;

/**
 * Class FilesCache.
 *
 * @package cache
 */
class FilesCache extends Cache
{
    /**
     * Name prefix of the temporary files items are written to before being
     * moved into place.
     *
     * @since 4.2
     */
    protected const TEMPORARY_PREFIX = 'tmp-';
    /**
     * How long a temporary file is left alone before the garbage collector
     * treats it as abandoned, in seconds.
     *
     * @since 4.2
     */
    protected const TEMPORARY_GRACE = 60;
    /**
     * Files Cache handler configurations.
     *
     * @var array<string,mixed>
     */
    protected array $configs = [
        'directory' => null,
        'files_permission' => 0644,
        'gc' => 1,
    ];
    /**
     * @var string|null
     */
    protected ?string $baseDirectory;
    /**
     * Files removed by the collection currently running.
     *
     * @since 4.2
     *
     * @var int
     */
    protected int $purged = 0;

    /**
     * FilesCache constructor.
     *
     * @param array<string,mixed>|null $configs Driver specific configurations
     * @param string|null $prefix Keys prefix
     * @param Serializer|string $serializer Data serializer
     * @param Logger|null $logger Logger instance
     */
    public function __construct(
        #[SensitiveParameter]
        ?array $configs = [],
        ?string $prefix = null,
        Serializer | string $serializer = Serializer::PHP,
        ?Logger $logger = null
    ) {
        parent::__construct($configs, $prefix, $serializer, $logger);
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
        $this->setBaseDirectory();
        $this->setGC($this->configs['gc']);
    }

    /**
     * Check the percentage of destructs that collect expired items.
     *
     * Zero turns the inline collection off, for setups running purge from a
     * scheduled job instead.
     *
     * @param int $gc A percentage from 0 to 100
     *
     * @throws InvalidArgumentException if $gc is outside that range
     */
    protected function setGC(int $gc) : void
    {
        if ($gc < 0 || $gc > 100) {
            throw new InvalidArgumentException(
                "Invalid cache GC: {$gc}"
            );
        }
    }

    protected function setBaseDirectory() : void
    {
        $path = $this->configs['directory'] ?? $this->makeDefaultDirectory();
        $real = \realpath($path);
        if ($real === false) {
            throw new RuntimeException("Invalid cache directory: {$path}");
        }
        $real = \rtrim($path, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
        if (isset($this->prefix[0])) {
            $real .= $this->prefix;
        }
        if (!\is_dir($real)) {
            throw new RuntimeException(
                "Invalid cache directory path: {$real}"
            );
        }
        if (!\is_writable($real)) {
            throw new RuntimeException(
                "Cache directory is not writable: {$real}"
            );
        }
        $this->baseDirectory = $real . \DIRECTORY_SEPARATOR;
    }

    /**
     * Make the directory used when no `directory` config is given.
     *
     * A directory of its own inside the system temp directory, never the temp
     * directory itself: flush and the garbage collector walk the cache
     * directory and delete what they find in it, so pointing the cache at a
     * directory shared with other processes would put their files at risk.
     *
     * The path carries the user id and the directory is created as 0700,
     * because on many systems the temp directory is world writable and cached
     * items are unserialized when read. Anyone able to drop a file in here
     * would be handing this process arbitrary objects to build. For the same
     * reason an existing path is only accepted when it is a real directory
     * owned by this user and closed to everybody else.
     *
     * The resolved path is recorded in the configs, which is what scopes the
     * directory check in openDir.
     *
     * @throws RuntimeException if the directory cannot be created or is not
     * private to this user
     *
     * @return string The directory path
     */
    protected function makeDefaultDirectory() : string
    {
        $path = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR
            . 'webisters-cache-' . $this->getUserId();
        if (\is_link($path)) {
            throw new RuntimeException(
                "Default cache directory must not be a symbolic link: {$path}"
            );
        }
        if (!\is_dir($path) && !@\mkdir($path, 0700, true) && !\is_dir($path)) {
            throw new RuntimeException(
                "Default cache directory was not created: {$path}"
            );
        }
        $this->assertPrivateDirectory($path);
        $this->configs['directory'] = $path;
        return $path;
    }

    /**
     * Identify the user this process runs as, to keep the default directory of
     * one user out of the reach of the others.
     *
     * @return string
     */
    protected function getUserId() : string
    {
        if (\function_exists('posix_geteuid')) {
            return (string) \posix_geteuid();
        }
        $user = \getenv('USER');
        if ($user === false || $user === '') {
            $user = \getenv('USERNAME');
        }
        if ($user === false || $user === '') {
            return 'shared';
        }
        return \substr(\preg_replace('/[^A-Za-z0-9_-]/', '', $user) ?? '', 0, 32)
            ?: 'shared';
    }

    /**
     * Make sure a directory is owned by this user and closed to everybody else.
     *
     * Only meaningful where the temp directory is shared between users. On
     * Windows every user gets their own temp directory, and the permission
     * bits do not map onto its access control lists, so the check is skipped.
     *
     * @param string $path The directory path
     *
     * @throws RuntimeException if the directory is not private to this user
     */
    protected function assertPrivateDirectory(string $path) : void
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        if (\function_exists('posix_geteuid')) {
            $owner = @\fileowner($path);
            if ($owner !== false && $owner !== \posix_geteuid()) {
                throw new RuntimeException(
                    "Default cache directory is not owned by this user: {$path}"
                );
            }
        }
        $permissions = @\fileperms($path);
        if ($permissions !== false && ($permissions & 0o077) !== 0) {
            throw new RuntimeException(
                "Default cache directory is accessible to other users: {$path}"
            );
        }
    }

    public function get(string $key) : mixed
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugGet(
                $key,
                $start,
                $this->getContents($this->renderFilepath($key))
            );
        }
        return $this->getContents($this->renderFilepath($key));
    }

    /**
     * @param string $filepath
     *
     * @return mixed
     */
    protected function getContents(string $filepath) : mixed
    {
        if (!\is_file($filepath)) {
            return null;
        }
        $value = @\file_get_contents($filepath);
        if ($value === false) {
            $this->log("Cache (files): File '{$filepath}' could not be read");
            return null;
        }
        $value = (array) $this->unserialize($value);
        if (!isset($value['ttl'], $value['data']) || $value['ttl'] <= \time()) {
            $this->deleteFile($filepath);
            return null;
        }
        return $value['data'];
    }

    protected function createSubDirectory(string $filepath) : void
    {
        $dirname = \dirname($filepath);
        if (\is_dir($dirname)) {
            return;
        }
        if (!\mkdir($dirname, 0777, true) || !\is_dir($dirname)) {
            throw new RuntimeException(
                "Directory key was not created: {$filepath}"
            );
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null) : bool
    {
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

    public function setValue(string $key, mixed $value, ?int $ttl = null) : bool
    {
        $filepath = $this->renderFilepath($key);
        $this->createSubDirectory($filepath);
        $contents = $this->serialize([
            'ttl' => \time() + $this->makeTtl($ttl),
            'data' => $value,
        ]);
        $temporary = $this->writeTemporaryFile($filepath, $contents);
        if ($temporary === false) {
            return false;
        }
        // Renaming over the destination swaps it in one step, so a reader sees
        // either the whole previous item or the whole new one, never the
        // half-written file that writing in place would leave behind.
        if (!@\rename($temporary, $filepath)) {
            @\unlink($temporary);
            $this->log("Cache (files): File '{$filepath}' could not be written");
            return false;
        }
        return true;
    }

    /**
     * Write the contents of an item to a temporary file, ready to be moved
     * into place.
     *
     * The temporary file is made in the directory the item belongs to, so the
     * move that follows stays on one file system and is a rename rather than a
     * copy.
     *
     * @since 4.2
     *
     * @param string $filepath The final path of the item
     * @param string $contents The serialized item
     *
     * @return false|string The temporary file path, or false on failure
     */
    protected function writeTemporaryFile(string $filepath, string $contents) : false | string
    {
        $directory = \dirname($filepath);
        $temporary = @\tempnam($directory, static::TEMPORARY_PREFIX);
        if ($temporary === false) {
            $this->log("Cache (files): Temporary file could not be created in '{$directory}'");
            return false;
        }
        // tempnam quietly falls back to the system temp directory when it
        // cannot write in the one it was given. Moving a file from there would
        // cross file systems, turning the rename that follows into a copy and
        // losing the atomicity this is all for.
        if (\realpath(\dirname($temporary)) !== \realpath($directory)) {
            @\unlink($temporary);
            $this->log("Cache (files): Temporary file could not be created in '{$directory}'");
            return false;
        }
        if (@\file_put_contents($temporary, $contents) === false) {
            @\unlink($temporary);
            $this->log("Cache (files): Temporary file '{$temporary}' could not be written");
            return false;
        }
        @\chmod($temporary, $this->configs['files_permission']);
        return $temporary;
    }

    #[Override]
    public function add(string $key, mixed $value, ?int $ttl = null) : bool
    {
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

    /**
     * Write an item only if its file is not already there.
     *
     * The exclusive create mode of fopen is what makes this atomic: the file
     * system refuses the second concurrent create. An expired file is removed
     * first, since it no longer counts as an existing item.
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
    public function addValue(string $key, mixed $value, ?int $ttl = null) : bool
    {
        $filepath = $this->renderFilepath($key);
        $this->createSubDirectory($filepath);
        if ($this->isExpiredFile($filepath)) {
            $this->deleteFile($filepath);
        }
        $contents = $this->serialize([
            'ttl' => \time() + $this->makeTtl($ttl),
            'data' => $value,
        ]);
        $temporary = $this->writeTemporaryFile($filepath, $contents);
        if ($temporary === false) {
            return false;
        }
        // Hard linking the finished file into place refuses to overwrite, so
        // it claims the name and publishes the contents in the same step. The
        // loser of a race sees a complete item, never an empty placeholder.
        if (@\link($temporary, $filepath)) {
            @\unlink($temporary);
            return true;
        }
        if (\is_file($filepath)) {
            @\unlink($temporary);
            return false;
        }
        // The file system has no hard links. Claim the name with an exclusive
        // create and move the finished file over the placeholder.
        $handle = @\fopen($filepath, 'xb');
        if ($handle === false) {
            @\unlink($temporary);
            return false;
        }
        \fclose($handle);
        if (!@\rename($temporary, $filepath)) {
            @\unlink($temporary);
            $this->deleteFile($filepath);
            $this->log("Cache (files): File '{$filepath}' could not be written");
            return false;
        }
        return true;
    }

    /**
     * Tell whether a file holds an item that is already expired.
     *
     * @since 4.2
     *
     * @param string $filepath
     *
     * @return bool TRUE when the file is there but no longer valid, FALSE when
     * it is missing or still valid
     */
    protected function isExpiredFile(string $filepath) : bool
    {
        if (!\is_file($filepath)) {
            return false;
        }
        $value = @\file_get_contents($filepath);
        if ($value === false) {
            return true;
        }
        $value = (array) $this->unserialize($value);
        return !isset($value['ttl']) || $value['ttl'] <= \time();
    }

    public function delete(string $key) : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugDelete(
                $key,
                $start,
                $this->deleteFile($this->renderFilepath($key))
            );
        }
        return $this->deleteFile($this->renderFilepath($key));
    }

    public function flush() : bool
    {
        if (isset($this->debugCollector)) {
            $start = \microtime(true);
            return $this->addDebugFlush(
                $start,
                $this->deleteAll($this->baseDirectory)
            );
        }
        return $this->deleteAll($this->baseDirectory);
    }

    /**
     * Garbage collector.
     *
     * Deletes all expired items.
     *
     * @return bool TRUE if all expired items was deleted, FALSE if a fail occurs
     */
    public function gc() : bool
    {
        return $this->deleteExpired($this->baseDirectory);
    }

    /**
     * Delete every expired item and report how many files went.
     *
     * Expired items are skipped on read but stay on disk until something
     * removes them, so a cache with a lot of short lived keys keeps growing.
     * This is the maintenance entry point for a scheduled job, its count
     * being something a cron job can log or alert on:
     *
     * ```php
     * $removed = $cache->purge();
     * ```
     *
     * Set the `gc` config to 0 to leave collection entirely to that job,
     * rather than paying for it on a share of the requests.
     *
     * A failure part way through is logged, and what was removed up to that
     * point is still counted.
     *
     * @since 4.2
     *
     * @return int Number of files removed, expired items and abandoned
     * temporary files together
     */
    public function purge() : int
    {
        $this->purged = 0;
        $this->deleteExpired($this->baseDirectory);
        return $this->purged;
    }

    protected function deleteExpired(string $baseDirectory) : bool
    {
        $handle = $this->openDir($baseDirectory);
        if ($handle === false) {
            return false;
        }
        $baseDirectory = \rtrim($baseDirectory, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
        $status = true;
        while (($filename = \readdir($handle)) !== false) {
            if ($filename[0] === '.') {
                continue;
            }
            $path = $baseDirectory . $filename;
            if (\is_file($path)) {
                \str_starts_with($filename, static::TEMPORARY_PREFIX)
                    ? $this->deleteAbandonedTemporaryFile($path)
                    : $this->getContents($path);
                if (!\is_file($path)) {
                    $this->purged++;
                }
                continue;
            }
            if (!$this->deleteExpired($path)) {
                $status = false;
                break;
            }
            if (\scandir($path, \SCANDIR_SORT_ASCENDING) === ['.', '..'] && !\rmdir($path)) {
                $status = false;
                break;
            }
        }
        $this->closeDir($handle);
        return $status;
    }

    /**
     * Delete a temporary file left behind by a write that never finished.
     *
     * A temporary file that is still fresh may belong to a write happening
     * right now, and taking it away would fail that write, so only the ones
     * old enough to be abandoned are removed.
     *
     * @since 4.2
     *
     * @param string $filepath
     */
    protected function deleteAbandonedTemporaryFile(string $filepath) : void
    {
        $time = @\filemtime($filepath);
        if ($time !== false && $time <= \time() - static::TEMPORARY_GRACE) {
            $this->deleteFile($filepath);
        }
    }

    protected function deleteAll(string $baseDirectory) : bool
    {
        $handle = $this->openDir($baseDirectory);
        if ($handle === false) {
            return false;
        }
        $baseDirectory = \rtrim($baseDirectory, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
        $status = true;
        while (($path = \readdir($handle)) !== false) {
            if ($path[0] === '.') {
                continue;
            }
            $path = $baseDirectory . $path;
            if (\is_file($path)) {
                if (\unlink($path)) {
                    continue;
                }
                $this->log("Cache (files): File '{$path}' could not be deleted");
                $status = false;
                break;
            }
            if (!$this->deleteAll($path)) {
                $status = false;
                break;
            }
            if (\scandir($path, \SCANDIR_SORT_ASCENDING) === ['.', '..'] && !\rmdir($path)) {
                $status = false;
                break;
            }
        }
        $this->closeDir($handle);
        return $status;
    }

    protected function deleteFile(string $filepath) : bool
    {
        if (\is_file($filepath)) {
            $deleted = \unlink($filepath);
            if ($deleted === false) {
                $this->log("Cache (files): File '{$filepath}' could not be deleted");
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $dirpath
     *
     * @return false|resource
     */
    protected function openDir(string $dirpath)
    {
        $real = \realpath($dirpath);
        if ($real === false) {
            return false;
        }
        if (!\is_dir($real)) {
            return false;
        }
        $real = \rtrim($real, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
        if (!\str_starts_with($real, $this->configs['directory'])) {
            return false;
        }
        return \opendir($real);
    }

    /**
     * @param resource $resource
     */
    protected function closeDir($resource) : void
    {
        if (\is_resource($resource)) {
            \closedir($resource);
        }
    }

    #[Pure]
    protected function renderFilepath(string $key) : string
    {
        $key = \md5($key);
        return $this->baseDirectory .
            $key[0] . $key[1] . \DIRECTORY_SEPARATOR .
            $key;
    }
}
