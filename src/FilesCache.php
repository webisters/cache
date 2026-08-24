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

    protected function setGC(int $gc) : void
    {
        if ($gc < 1 || $gc > 100) {
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
     * The resolved path is recorded in the configs, which is what scopes the
     * directory check in openDir.
     *
     * @throws RuntimeException if the directory does not exist and cannot be created
     *
     * @return string The directory path
     */
    protected function makeDefaultDirectory() : string
    {
        $path = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'webisters-cache';
        if (!\is_dir($path) && !\mkdir($path, 0777, true) && !\is_dir($path)) {
            throw new RuntimeException(
                "Default cache directory was not created: {$path}"
            );
        }
        $this->configs['directory'] = $path;
        return $path;
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
        $value = [
            'ttl' => \time() + $this->makeTtl($ttl),
            'data' => $value,
        ];
        $value = $this->serialize($value);
        $isFile = \is_file($filepath);
        $written = @\file_put_contents($filepath, $value, \LOCK_EX);
        if ($written !== false && $isFile === false) {
            \chmod($filepath, $this->configs['files_permission']);
        }
        if ($written === false) {
            $this->log("Cache (files): File '{$filepath}' could not be written");
            return false;
        }
        return true;
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
        $handle = @\fopen($filepath, 'xb');
        if ($handle === false) {
            return false;
        }
        $contents = $this->serialize([
            'ttl' => \time() + $this->makeTtl($ttl),
            'data' => $value,
        ]);
        $written = \fwrite($handle, $contents);
        \fclose($handle);
        if ($written === false) {
            $this->deleteFile($filepath);
            $this->log("Cache (files): File '{$filepath}' could not be written");
            return false;
        }
        \chmod($filepath, $this->configs['files_permission']);
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

    protected function deleteExpired(string $baseDirectory) : bool
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
                $this->getContents($path);
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
