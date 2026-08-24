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

use Framework\Cache\ArrayCache;
use Framework\Cache\Serializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SerializerTest extends TestCase
{
    public function testExtensionOfEachSerializer() : void
    {
        self::assertSame('igbinary', Serializer::IGBINARY->getExtension());
        self::assertSame('json', Serializer::JSON->getExtension());
        self::assertSame('json', Serializer::JSON_ARRAY->getExtension());
        self::assertSame('msgpack', Serializer::MSGPACK->getExtension());
        self::assertNull(Serializer::PHP->getExtension());
    }

    public function testPhpAndJsonAreAlwaysAvailable() : void
    {
        // ext-json is required by composer.json and PHP has serialize built in.
        self::assertTrue(Serializer::PHP->isAvailable());
        self::assertTrue(Serializer::JSON->isAvailable());
        self::assertTrue(Serializer::JSON_ARRAY->isAvailable());
    }

    public function testAvailabilityFollowsTheLoadedExtensions() : void
    {
        self::assertSame(
            \function_exists('igbinary_serialize'),
            Serializer::IGBINARY->isAvailable()
        );
        self::assertSame(
            \function_exists('msgpack_serialize'),
            Serializer::MSGPACK->isAvailable()
        );
    }

    public function testAvailableListsOnlyUsableSerializers() : void
    {
        $available = Serializer::available();
        self::assertContains(Serializer::PHP, $available);
        self::assertContains(Serializer::JSON, $available);
        foreach ($available as $serializer) {
            self::assertTrue($serializer->isAvailable());
        }
        foreach (Serializer::cases() as $serializer) {
            if (!$serializer->isAvailable()) {
                self::assertNotContains($serializer, $available);
            }
        }
    }

    public function testAnUnavailableSerializerIsRefusedAtConstruction() : void
    {
        $missing = null;
        foreach ([Serializer::IGBINARY, Serializer::MSGPACK] as $serializer) {
            if (!$serializer->isAvailable()) {
                $missing = $serializer;
                break;
            }
        }
        if ($missing === null) {
            self::markTestSkipped('Every serializer is available here');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Serializer ' . $missing->value . ' needs the '
            . $missing->getExtension() . ' extension, which is not loaded'
        );
        new ArrayCache([], null, $missing);
    }

    public function testAnAvailableSerializerIsAccepted() : void
    {
        foreach (Serializer::available() as $serializer) {
            $cache = new ArrayCache([], null, $serializer);
            self::assertSame($serializer, $cache->getSerializer());
            self::assertTrue($cache->set('foo', 'bar', 60));
            self::assertSame('bar', $cache->get('foo'));
        }
    }

    public function testEverySerializerKeepsScalarsAndLists() : void
    {
        foreach (Serializer::available() as $serializer) {
            $cache = new ArrayCache([], null, $serializer);
            foreach (['text', 42, 1.5, true, false, [1, 2, 3]] as $value) {
                $cache->set('foo', $value, 60);
                self::assertSame(
                    $value,
                    $cache->get('foo'),
                    $serializer->value . ' changed ' . \get_debug_type($value)
                );
            }
        }
    }

    public function testJsonHandsBackAnObjectForAnAssociativeArray() : void
    {
        $cache = new ArrayCache([], null, Serializer::JSON);
        $cache->set('foo', ['a' => 1], 60);
        self::assertInstanceOf(\stdClass::class, $cache->get('foo'));
    }

    public function testJsonArrayKeepsAnAssociativeArray() : void
    {
        $cache = new ArrayCache([], null, Serializer::JSON_ARRAY);
        $cache->set('foo', ['a' => 1], 60);
        self::assertSame(['a' => 1], $cache->get('foo'));
    }

    public function testOnlyThePhpFamilyKeepsAnObjectClass() : void
    {
        $object = new \DateTimeImmutable('2026-01-01 00:00:00');
        foreach (Serializer::available() as $serializer) {
            $cache = new ArrayCache([], null, $serializer);
            $cache->set('foo', $object, 60);
            $back = $cache->get('foo');
            if ($serializer === Serializer::JSON) {
                self::assertInstanceOf(\stdClass::class, $back);
                continue;
            }
            if ($serializer === Serializer::JSON_ARRAY) {
                self::assertIsArray($back);
                continue;
            }
            self::assertInstanceOf(\DateTimeImmutable::class, $back);
            self::assertEquals($object, $back);
        }
    }

    public function testSerializerCanBeGivenAsAString() : void
    {
        $cache = new ArrayCache([], null, 'json-array');
        self::assertSame(Serializer::JSON_ARRAY, $cache->getSerializer());
    }

    public function testAnUnknownSerializerNameIsRefused() : void
    {
        $this->expectException(\ValueError::class);
        new ArrayCache([], null, 'not-a-serializer');
    }
}
