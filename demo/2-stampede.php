<?php declare(strict_types=1);
/**
 * DEMO 2: ten requests, one expired key, one expensive query.
 *
 * Every worker needs the same value at the same moment. How many of them
 * actually run the query?
 */
require __DIR__ . '/bootstrap.php';

const WORKERS = 10;

$cache = demo_cache();
// Outside the cache folder: the library's collector clears anything in
// there that is not one of its own items, marks included.
$marks = __DIR__ . '/marks';

if (!is_dir($marks)) {
    mkdir($marks, 0777, true);
}

if (($argv[1] ?? '') === 'worker') {
    $how = $argv[2];
    $query = static function () use ($marks) {
        // Each worker leaves its own mark, so nothing is lost to a race.
        touch($marks . DIRECTORY_SEPARATOR . getmypid());
        usleep(400000); // a slow query
        return 'the answer';
    };
    $value = $how === 'plain'
        ? $cache->remember('report', $query, 60)
        : $cache->rememberProtected('report', $query, 60);
    exit($value === 'the answer' ? 0 : 1);
}

echo "\n  " . WORKERS . " workers want the same expired key. The query takes 400ms.\n\n";

foreach (['plain' => 'remember()', 'protected' => 'rememberProtected()'] as $how => $label) {
    $cache->delete('report');
    foreach (glob($marks . '/*') as $mark) {
        @unlink($mark);
    }
    demo_spawn(WORKERS, 'worker ' . $how);
    printf("  %-22s %2d queries\n", $label, count(glob($marks . '/*')));
}

foreach (glob($marks . '/*') as $mark) {
    @unlink($mark);
}
echo "\n";
