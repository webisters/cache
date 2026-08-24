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

use Framework\Cache\TaggedCache;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TaggedCacheTest extends TestCase
{
    protected ArrayCacheMock $cache;

    protected function setUp() : void
    {
        $this->cache = new ArrayCacheMock(prefix: 'test-');
    }

    public function testSetAndGet() : void
    {
        $tagged = $this->cache->tags('posts');
        self::assertNull($tagged->get('list'));
        self::assertTrue($tagged->set('list', ['a', 'b']));
        self::assertSame(['a', 'b'], $tagged->get('list'));
    }

    public function testFlushInvalidatesOnlyTaggedItems() : void
    {
        $this->cache->set('untagged', 'kept');
        $this->cache->tags('posts')->set('list', 'posts-value');
        $this->cache->tags('users')->set('list', 'users-value');
        self::assertTrue($this->cache->tags('posts')->flush());
        self::assertNull($this->cache->tags('posts')->get('list'));
        self::assertSame('users-value', $this->cache->tags('users')->get('list'));
        self::assertSame('kept', $this->cache->get('untagged'));
    }

    public function testFlushOneTagInvalidatesItemsSharingIt() : void
    {
        $this->cache->tags(['posts', 'users'])->set('feed', 'value');
        self::assertSame('value', $this->cache->tags(['posts', 'users'])->get('feed'));
        self::assertTrue($this->cache->tags('users')->flush());
        self::assertNull($this->cache->tags(['posts', 'users'])->get('feed'));
    }

    public function testFlushTags() : void
    {
        $this->cache->tags('posts')->set('a', 1);
        $this->cache->tags('users')->set('b', 2);
        $this->cache->tags('pages')->set('c', 3);
        self::assertTrue($this->cache->flushTags(['posts', 'users']));
        self::assertNull($this->cache->tags('posts')->get('a'));
        self::assertNull($this->cache->tags('users')->get('b'));
        self::assertSame(3, $this->cache->tags('pages')->get('c'));
    }

    public function testTagOrderDoesNotMatter() : void
    {
        $this->cache->tags(['users', 'posts'])->set('feed', 'value');
        self::assertSame('value', $this->cache->tags(['posts', 'users'])->get('feed'));
    }

    public function testDuplicatedTagsAreCollapsed() : void
    {
        $tagged = $this->cache->tags(['posts', 'posts']);
        self::assertSame(['posts'], $tagged->getTags());
        $tagged->set('list', 'value');
        self::assertSame('value', $this->cache->tags('posts')->get('list'));
    }

    public function testTaggedItemsDoNotCollideWithUntaggedOnes() : void
    {
        $this->cache->set('list', 'plain');
        $this->cache->tags('posts')->set('list', 'tagged');
        self::assertSame('plain', $this->cache->get('list'));
        self::assertSame('tagged', $this->cache->tags('posts')->get('list'));
        self::assertTrue($this->cache->tags('posts')->flush());
        self::assertSame('plain', $this->cache->get('list'));
    }

    public function testGetOnUnknownTagDoesNotWrite() : void
    {
        $this->cache->setCount = 0;
        self::assertNull($this->cache->tags('posts')->get('list'));
        self::assertSame(0, $this->cache->setCount);
    }

    public function testSetMultiAndGetMulti() : void
    {
        $tagged = $this->cache->tags('posts');
        self::assertSame(
            ['foo' => null, 'bar' => null],
            $tagged->getMulti(['foo', 'bar'])
        );
        self::assertSame(
            ['foo' => true, 'bar' => true],
            $tagged->setMulti(['foo' => 'x', 'bar' => 'y'])
        );
        self::assertSame(
            ['bar' => 'y', 'foo' => 'x', 'baz' => null],
            $tagged->getMulti(['bar', 'foo', 'baz'])
        );
        self::assertTrue($tagged->flush());
        self::assertSame(
            ['foo' => null, 'bar' => null],
            $tagged->getMulti(['foo', 'bar'])
        );
    }

    public function testDelete() : void
    {
        $tagged = $this->cache->tags('posts');
        $tagged->set('foo', 'bar');
        self::assertSame('bar', $tagged->get('foo'));
        self::assertTrue($tagged->delete('foo'));
        self::assertNull($tagged->get('foo'));
    }

    public function testDeleteMulti() : void
    {
        $tagged = $this->cache->tags('posts');
        $tagged->setMulti(['foo' => 'x', 'bar' => 'y']);
        self::assertSame(
            ['foo' => true, 'bar' => true],
            $tagged->deleteMulti(['foo', 'bar'])
        );
        self::assertSame(
            ['foo' => null, 'bar' => null],
            $tagged->getMulti(['foo', 'bar'])
        );
    }

    public function testIncrementAndDecrement() : void
    {
        $tagged = $this->cache->tags('counters');
        self::assertSame(1, $tagged->increment('hits'));
        self::assertSame(3, $tagged->increment('hits', 2));
        self::assertSame(2, $tagged->decrement('hits'));
        self::assertNull($this->cache->get('hits'));
        self::assertTrue($tagged->flush());
        self::assertSame(1, $tagged->increment('hits'));
    }

    public function testTagVersionOutlivesTheItems() : void
    {
        $tagged = $this->cache->tags('posts');
        $tagged->set('list', 'value', 1);
        self::assertSame(2592000, $tagged->getTagTtl());
        $tagged->setTagTtl(120);
        self::assertSame(120, $tagged->getTagTtl());
    }

    public function testEmptyTagListIsRejected() : void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tagged cache requires at least one tag');
        $this->cache->tags([]);
    }

    public function testEmptyTagNameIsRejected() : void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag name must not be empty');
        $this->cache->tags(['posts', '']);
    }

    public function testInvalidTagTtlIsRejected() : void
    {
        $tagged = $this->cache->tags('posts');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag TTL must be greater than 0. 0 given');
        $tagged->setTagTtl(0);
    }

    public function testTagsReturnsATaggedCache() : void
    {
        self::assertInstanceOf(TaggedCache::class, $this->cache->tags('posts'));
        self::assertSame(['posts', 'users'], $this->cache->tags(['users', 'posts'])->getTags());
    }
}
