<?php declare(strict_types=1);
/*
 * This file is part of Webisters Cache Library.
 *
 * (c) Hafiz Muhammad Moaz <thewebisters@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Framework\Cache\Debug;

use Framework\Debug\Collection;

/**
 * Class CacheCollection.
 *
 * @package cache
 */
class CacheCollection extends Collection
{
    protected string $iconPath = __DIR__ . '/icons/cache.svg';
}
