<?php declare(strict_types=1);
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

/**
 * A files cache that behaves as though the file system had no hard links.
 *
 * ext4 does have them, so addValue() always takes the link branch on the
 * machines this suite runs on and the fallback below it would never be
 * exercised. That fallback is where the defect in issue #55 lived, so it needs
 * to be reachable in a test.
 */
class FilesCacheNoLinkMock extends FilesCache
{
    protected function linkFile(string $from, string $to) : bool
    {
        return false;
    }
}
