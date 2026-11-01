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
use Framework\Cache\Serializer;
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

    public function testARepeatedTaggedWriteCostsNoMoreThanAnUntaggedOne() : void
    {
        $tagged = $this->cache->tags(['a', 'b', 'c']);
        // First write creates the three tag versions alongside the item.
        $this->cache->setCount = 0;
        $tagged->set('key', 'value');
        self::assertSame(4, $this->cache->setCount);
        // Later writes reuse them. Before the renewal window every write
        // rewrote all three versions, costing 4 writes every time.
        $this->cache->setCount = 0;
        $tagged->set('key', 'value');
        self::assertSame(1, $this->cache->setCount);
        $this->cache->setCount = 0;
        for ($i = 0; $i < 10; $i++) {
            $tagged->set('key' . $i, $i);
        }
        self::assertSame(10, $this->cache->setCount);
    }

    /**
     * A tag TTL of 4 gives a renewal window of 2, so sleeping 3 lands past the
     * window but before the version would expire. That is renewal rather than
     * a lapse, and the difference is the token: a renewed version keeps it, a
     * lapsed one gets a new one and takes its items with it.
     */
    public function testTagVersionIsRenewedWithoutChangingTheToken() : void
    {
        $tagged = $this->cache->tags('posts')->setTagTtl(4);
        $tagged->set('list', 'value');
        $before = $this->tagToken('posts');
        \sleep(3);
        $this->cache->setCount = 0;
        $tagged->set('list', 'value');
        self::assertSame(2, $this->cache->setCount, 'the version is rewritten');
        self::assertSame($before, $this->tagToken('posts'));
        self::assertSame('value', $tagged->get('list'));
    }

    public function testRenewalKeepsItemsReachableBeyondTheOriginalTagTtl() : void
    {
        $tagged = $this->cache->tags('posts')->setTagTtl(4);
        $tagged->set('list', 'value');
        \sleep(3);
        // Renews the version, pushing its expiry out from 4 seconds to 7.
        $tagged->set('other', 'second');
        \sleep(3);
        // Six seconds in. Without the renewal the version would have expired
        // at four and a new token would have orphaned both items.
        self::assertSame('value', $tagged->get('list'));
        self::assertSame('second', $tagged->get('other'));
    }

    public function testFlushStillInvalidatesAfterARenewal() : void
    {
        $tagged = $this->cache->tags('posts')->setTagTtl(4);
        $tagged->set('list', 'value');
        \sleep(3);
        $tagged->set('list', 'value');
        self::assertTrue($tagged->flush());
        self::assertNull($tagged->get('list'));
    }

    /**
     * A version written by a release before the renewal window is a plain
     * string. It must be kept rather than replaced, or every item already
     * carrying it would be invalidated by the upgrade alone.
     */
    public function testAPlainStringVersionFromAnOlderReleaseIsKept() : void
    {
        $tagged = $this->cache->tags('posts');
        $tagged->set('list', 'value');
        $version = $this->tagToken('posts');
        // Put the tag key back into the old shape: a bare token, no colon.
        $this->cache->set('@w:tag:posts', $version);
        self::assertSame('value', $tagged->get('list'));
        // The next write stamps a renewal time on without changing the token.
        $tagged->set('list', 'again');
        self::assertStringContainsString(':', (string) $this->cache->get('@w:tag:posts'));
        self::assertSame($version, $this->tagToken('posts'));
        self::assertSame('again', $tagged->get('list'));
    }

    public function testAGarbageTagVersionIsReplacedRatherThanTrusted() : void
    {
        $tagged = $this->cache->tags('posts');
        $tagged->set('list', 'value');
        $this->cache->set('@w:tag:posts', '0:');
        // An unusable version is treated as absent, so a fresh one is made and
        // the items under the old one are no longer reachable.
        self::assertNull($tagged->get('list'));
        self::assertTrue($tagged->set('list', 'new'));
        self::assertSame('new', $tagged->get('list'));
    }

    public function testASmallestTagTtlKeepsItemsReachable() : void
    {
        // intdiv(1, 2) is 0, which as a renewal window would mean "renew
        // always". The floor of 1 keeps a real window, and either way a write
        // must never orphan the items it just grouped.
        $tagged = $this->cache->tags('posts')->setTagTtl(1);
        self::assertTrue($tagged->set('list', 'value'));
        self::assertSame('value', $tagged->get('list'));
        self::assertTrue($tagged->set('other', 'second'));
        self::assertSame('second', $tagged->get('other'));
    }

    /**
     * Read the current token of a tag out of the stored value.
     *
     * @param string $tag
     *
     * @return string
     */
    private function tagToken(string $tag) : string
    {
        $stored = (string) $this->cache->get('@w:tag:' . $tag);
        $parts = \explode(':', $stored, 2);
        return \count($parts) === 2 ? $parts[1] : $stored;
    }

    /**
     * The renewal timestamp travels with the token in one string because the
     * value passes through the configured serializer, and Serializer::JSON
     * decodes a map to stdClass rather than to an array. An array here would
     * be unreadable on every serializing driver configured that way, so the
     * version would be reminted on every read and tags would never match.
     *
     * @return void
     */
    public function testTheStoredVersionSurvivesEverySerializer() : void
    {
        $directory = \sys_get_temp_dir() . '/webisters-tagver-' . \getmypid();
        // FilesCache requires the directory to exist already, see issue #52.
        if (!\is_dir($directory)) {
            \mkdir($directory, 0700, true);
        }
        foreach ([Serializer::PHP, Serializer::JSON, Serializer::JSON_ARRAY] as $serializer) {
            $cache = new FilesCache(['directory' => $directory], null, $serializer);
            $tagged = $cache->tags('posts');
            self::assertTrue($tagged->set('list', 'value'), $serializer->value);
            self::assertSame('value', $tagged->get('list'), $serializer->value);
            // The window must hold, which it cannot if the value came back in
            // a shape readTagVersion() does not recognise.
            $cache->flush();
            $tagged->set('list', 'value');
            $tagged->set('other', 'second');
            self::assertSame('value', $tagged->get('list'), $serializer->value);
            self::assertSame('second', $tagged->get('other'), $serializer->value);
            $cache->flush();
        }
    }
}
