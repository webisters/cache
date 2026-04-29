<?php
/*
 * This file is part of Webisters Cache Library.
 *
 * (c) Hafiz Muhammad Moaz <thewebisters@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Tests\Cache\Debug;

use Framework\Cache\Debug\CacheCollection;
use PHPUnit\Framework\TestCase;

final class CacheCollectionTest extends TestCase
{
    protected CacheCollection $collection;

    protected function setUp() : void
    {
        $this->collection = new CacheCollection('Cache');
    }

    public function testIcon() : void
    {
        self::assertStringStartsWith('<svg ', $this->collection->getIcon());
    }
}
