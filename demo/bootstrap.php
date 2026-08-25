<?php declare(strict_types=1);
/**
 * Shared setup for the demos. Nothing interesting here.
 */
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "\n  Run 'composer install' in this folder first.\n\n");
    exit(1);
}
require $autoload;

function demo_cache() : Framework\Cache\FilesCache
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return new Framework\Cache\FilesCache(['directory' => realpath($dir), 'gc' => 1]);
}

/**
 * Start $count copies of this script at once and wait for all of them.
 */
function demo_spawn(int $count, string $arguments) : void
{
    // PHP_BINARY, not "php": the recording machine may not have php on its
    // PATH, or may have a different one there, and a worker that fails to
    // start is invisible. It would just look like the cache did nothing.
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($_SERVER['SCRIPT_FILENAME'])
        . ' ' . $arguments;
    $handles = [];
    for ($i = 0; $i < $count; $i++) {
        $handle = popen($command, 'r');
        if ($handle === false) {
            fwrite(STDERR, "\n  Could not start a worker process.\n\n");
            exit(1);
        }
        $handles[] = $handle;
    }
    $failed = 0;
    foreach ($handles as $handle) {
        stream_get_contents($handle);
        if (pclose($handle) !== 0) {
            $failed++;
        }
    }
    if ($failed > 0) {
        fwrite(STDERR, "\n  {$failed} of {$count} workers failed. The numbers below are not real.\n\n");
        exit(1);
    }
}
