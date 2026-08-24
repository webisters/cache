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

use Framework\Cache\FilesCache;

class FilesCacheTest extends TestCase
{
    protected array $configs = [
        'directory' => '/tmp/cache/',
        'gc' => 100,
    ];

    public function setUp() : void
    {
        \exec('rm -rf ' . $this->configs['directory']);
        \exec('mkdir -p ' . $this->configs['directory'] . $this->prefix);
        $this->cache = new FilesCache(
            $this->configs,
            $this->prefix,
            $this->serializer,
            $this->getLogger()
        );
    }

    public function testSerializer() : void
    {
        $this->cache = new FilesCache(
            $this->configs,
            $this->prefix,
            $this->serializer->value,
            $this->getLogger()
        );
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage(
            '"foo" is not a valid backing value for enum Framework\Cache\Serializer'
        );
        $this->cache = new FilesCache(
            $this->configs,
            $this->prefix,
            'foo',
            $this->getLogger()
        );
    }

    public function testGC() : void
    {
        $this->cache->set('foo', 'bar', 1);
        $this->cache->set('bar', 'baz', 2);
        \sleep(1);
        self::assertTrue($this->cache->gc()); // @phpstan-ignore-line
        self::assertNull($this->cache->get('foo'));
        self::assertSame('baz', $this->cache->get('bar'));
    }

    public function testInvalidGCValue() : void
    {
        $this->configs['gc'] = -1;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cache GC: -1');
        new FilesCache($this->configs, $this->prefix, $this->serializer);
    }

    public function testGCValueAboveOneHundredIsRejected() : void
    {
        $this->configs['gc'] = 101;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cache GC: 101');
        new FilesCache($this->configs, $this->prefix, $this->serializer);
    }

    public function testGCCanBeTurnedOff() : void
    {
        // Zero is for setups running purge from a scheduled job instead.
        $this->configs['gc'] = 0;
        $cache = new FilesCache($this->configs, $this->prefix, $this->serializer);
        self::assertTrue($cache->set('foo', 'bar', 60));
        self::assertSame('bar', $cache->get('foo'));
    }

    public function testPurgeReportsWhatItRemoved() : void
    {
        self::assertTrue($this->cache->set('gone', 'x', 1));
        self::assertTrue($this->cache->set('also-gone', 'y', 1));
        self::assertTrue($this->cache->set('kept', 'z', 60));
        \sleep(2);
        self::assertSame(2, $this->cache->purge()); // @phpstan-ignore-line
        // Nothing left to remove on a second pass.
        self::assertSame(0, $this->cache->purge()); // @phpstan-ignore-line
        self::assertSame('z', $this->cache->get('kept'));
    }

    public function testPurgeCountsAbandonedTemporaryFiles() : void
    {
        self::assertTrue($this->cache->set('kept', 'z', 60));
        $directory = $this->configs['directory'] . $this->prefix
            . \DIRECTORY_SEPARATOR . 'zz';
        \mkdir($directory, 0777, true);
        $abandoned = $directory . \DIRECTORY_SEPARATOR . 'tmp-abandoned';
        \file_put_contents($abandoned, 'half written');
        \touch($abandoned, \time() - 3600);
        self::assertSame(1, $this->cache->purge()); // @phpstan-ignore-line
        self::assertFileDoesNotExist($abandoned);
        self::assertSame('z', $this->cache->get('kept'));
    }

    public function testInvalidCacheDirectory() : void
    {
        $this->configs['directory'] = '/foo';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid cache directory: /foo');
        new FilesCache($this->configs, $this->prefix, $this->serializer);
    }

    public function testInvalidCacheDirectoryPath() : void
    {
        $this->prefix = 'foo';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "Invalid cache directory path: {$this->configs['directory']}{$this->prefix}"
        );
        new FilesCache($this->configs, $this->prefix, $this->serializer);
    }

    public function testDefaultConfigs() : void
    {
        self::assertInstanceOf(FilesCache::class, new FilesCache());
    }

    public function testDefaultConfigsGarbageCollector() : void
    {
        $cache = new FilesCache();
        self::assertTrue($cache->gc());
    }

    public function testDefaultConfigsRoundTrip() : void
    {
        $cache = new FilesCache();
        self::assertTrue($cache->set('foo', 'bar', 60));
        self::assertSame('bar', $cache->get('foo'));
        self::assertTrue($cache->flush());
        self::assertNull($cache->get('foo'));
    }

    public function testOverwritingLeavesNoTemporaryFilesBehind() : void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($this->cache->set('foo', 'value-' . $i, 60));
        }
        self::assertTrue($this->cache->add('bar', 'added', 60));
        self::assertSame('value-4', $this->cache->get('foo'));
        self::assertSame('added', $this->cache->get('bar'));
        self::assertSame([], $this->findTemporaryFiles());
    }

    public function testGarbageCollectorSparesTemporaryFilesInFlight() : void
    {
        self::assertTrue($this->cache->set('foo', 'bar', 60));
        $directory = $this->configs['directory'] . $this->prefix
            . \DIRECTORY_SEPARATOR . 'zz';
        \mkdir($directory, 0777, true);
        $fresh = $directory . \DIRECTORY_SEPARATOR . 'tmp-fresh';
        $abandoned = $directory . \DIRECTORY_SEPARATOR . 'tmp-abandoned';
        \file_put_contents($fresh, 'half written');
        \file_put_contents($abandoned, 'half written');
        \touch($abandoned, \time() - 3600);
        self::assertTrue($this->cache->gc()); // @phpstan-ignore-line
        // A temporary file that is still fresh may belong to a write happening
        // right now, so only the abandoned one is collected.
        self::assertFileExists($fresh);
        self::assertFileDoesNotExist($abandoned);
        self::assertSame('bar', $this->cache->get('foo'));
    }

    /**
     * @return array<int,string>
     */
    protected function findTemporaryFiles() : array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->configs['directory'],
                \FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && \str_starts_with($file->getFilename(), 'tmp-')) {
                $found[] = $file->getFilename();
            }
        }
        return $found;
    }

    public function testDefaultDirectoryIsPrivateToTheUser() : void
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX permission bits only');
        }
        $cache = new FilesCache();
        $directory = (new \ReflectionProperty(FilesCache::class, 'configs'))
            ->getValue($cache)['directory'];
        self::assertDirectoryExists($directory);
        self::assertStringContainsString('webisters-cache-', $directory);
        // The temp directory is shared, and cached items are unserialized when
        // read, so nobody else may write here.
        self::assertSame(0, \fileperms($directory) & 0o077);
    }

    public function testDefaultDirectoryIsNotTheSharedTempDirectory() : void
    {
        // flush and gc unlink what they find in the cache directory, so the
        // default must be a directory of this library's own. A file sitting
        // directly in the system temp directory has to survive both.
        $foreign = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'not-a-cache-file.txt';
        \file_put_contents($foreign, 'keep me');
        try {
            $cache = new FilesCache();
            self::assertTrue($cache->set('foo', 'bar', 60));
            self::assertTrue($cache->gc());
            self::assertFileExists($foreign);
            self::assertTrue($cache->flush());
            self::assertFileExists($foreign);
            self::assertSame('keep me', \file_get_contents($foreign));
        } finally {
            \unlink($foreign);
        }
    }

    public function testCacheDirectoryIsNotWritable() : void
    {
        if (\getenv('GITLAB_CI')) {
            $this->markTestIncomplete();
        }
        \exec('chmod 400 ' . $this->configs['directory'] . '*');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "Cache directory is not writable: {$this->configs['directory']}{$this->prefix}"
        );
        new FilesCache($this->configs, $this->prefix, $this->serializer);
    }

    public function testSetFailure() : void
    {
        if (\getenv('GITLAB_CI')) {
            $this->markTestIncomplete();
        }
        self::assertTrue($this->cache->set('key', 'value'));
        // An item is written to a temporary file and renamed into place, so a
        // write fails when the directory will not take a new file. Making the
        // item's own file read only no longer stops it, see
        // testReadOnlyItemFileIsStillReplaceable.
        $directory = $this->renderItemDirectory('key');
        \exec('chmod 500 ' . $directory);
        try {
            self::assertFalse($this->cache->set('key', 'value'));
        } finally {
            \exec('chmod 755 ' . $directory);
        }
    }

    public function testReadOnlyItemFileIsStillReplaceable() : void
    {
        if (\getenv('GITLAB_CI')) {
            $this->markTestIncomplete();
        }
        self::assertTrue($this->cache->set('key', 'value'));
        \exec('chmod 444 ' . $this->renderItemDirectory('key') . '/*');
        // Renaming over a file needs write permission on the directory, not on
        // the file, so a read only item no longer wedges its key for good.
        self::assertTrue($this->cache->set('key', 'new value'));
        self::assertSame('new value', $this->cache->get('key'));
    }

    /**
     * Path of the directory holding an item, as renderFilepath lays it out.
     */
    protected function renderItemDirectory(string $key) : string
    {
        $hash = \md5($key);
        $prefix = $this->prefix === '' ? '' : $this->prefix . '/';
        return $this->configs['directory'] . $prefix . $hash[0] . $hash[1];
    }

    public function testGetFailure() : void
    {
        if (\getenv('GITLAB_CI')) {
            $this->markTestIncomplete();
        }
        self::assertTrue($this->cache->set('key', 'value'));
        self::assertSame('value', $this->cache->get('key'));
        \exec('chmod 444 ' . $this->configs['directory'] . '*');
        self::assertNull($this->cache->get('key'));
        \exec('chmod 777 ' . $this->configs['directory'] . '*');
    }

    public function testGetInvalidContents() : void
    {
        self::assertTrue($this->cache->set('key', 'value'));
        foreach ((array) \glob($this->configs['directory'] . '*/*/*') as $file) {
            \file_put_contents((string) $file, '');
        }
        self::assertNull($this->cache->get('key'));
    }
}
