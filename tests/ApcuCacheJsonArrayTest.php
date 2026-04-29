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

use Framework\Cache\Serializer;

final class ApcuCacheJsonArrayTest extends ApcuCacheTest
{
    protected Serializer $serializer = Serializer::JSON_ARRAY;
}
