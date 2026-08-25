<?php declare(strict_types=1);
/**
 * DEMO 3: keys the backend would normally refuse.
 */
require __DIR__ . '/bootstrap.php';

$cache = demo_cache();
$cache->flush();

$keys = [
    'a key with spaces' => 'user profile name',
    '5,000 characters'  => str_repeat('k', 5000),
    'unicode'           => 'ключ-مفتاح-鍵',
    'a whole URL'       => 'https://example.com/a/b?c=d&e=f',
];

echo "\n  Keys most caches argue with:\n\n";
foreach ($keys as $label => $key) {
    $cache->set($key, 'stored', 300);
    printf("    %-20s %s\n", $label, $cache->get($key) === 'stored' ? 'works' : 'FAILED');
}
echo "\n  Same on Memcached, which refuses spaces and anything over 250 bytes.\n";
echo "  The library hashes it for you. You never find out.\n\n";
