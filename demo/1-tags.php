<?php declare(strict_types=1);
/**
 * DEMO 1: tags, on the plain files driver.
 *
 * No Redis. No Memcached. Just a folder on disk.
 */
require __DIR__ . '/bootstrap.php';

$cache = demo_cache();
$cache->flush();

$cache->tags('posts')->set('list', 'every post', 300);
$cache->tags('posts')->set('latest', 'newest post', 300);
$cache->tags('users')->set('list', 'every user', 300);
$cache->set('homepage', 'the homepage', 300);

$row = static function (string $label, mixed $value) : void {
    printf("    %-14s %s\n", $label, $value === null ? 'GONE' : $value);
};

echo "\n  Stored, on a folder of files:\n\n";
$row("posts/list", $cache->tags('posts')->get('list'));
$row("posts/latest", $cache->tags('posts')->get('latest'));
$row("users/list", $cache->tags('users')->get('list'));
$row("homepage", $cache->get('homepage'));

echo "\n  \$cache->tags('posts')->flush()\n\n";

$cache->tags('posts')->flush();

$row("posts/list", $cache->tags('posts')->get('list'));
$row("posts/latest", $cache->tags('posts')->get('latest'));
$row("users/list", $cache->tags('users')->get('list'));
$row("homepage", $cache->get('homepage'));

echo "\n";
