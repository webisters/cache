<?php declare(strict_types=1);
/*
 * This file is part of Webisters Cache Library.
 *
 * (c) Hafiz Muhammad Moaz <thewebisters@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Framework\Cache;

/**
 * Enum Serializer.
 *
 * How an item is turned into bytes on the way into the storage and back again
 * on the way out. The choice decides what a value looks like when it is read,
 * so it is worth knowing what each one keeps:
 *
 * - PHP hands everything back as it went in, objects included.
 * - IGBINARY does the same in a smaller, faster binary form, but needs its
 *   extension.
 * - MSGPACK is a compact format other languages can also read, and needs its
 *   extension.
 * - JSON and JSON_ARRAY are portable and readable, but only carry what JSON
 *   can express, so an object comes back as a stdClass or an array and its
 *   class is gone.
 *
 * @package cache
 */
enum Serializer : string
{
    /**
     * The Igbinary serializer.
     */
    case IGBINARY = 'igbinary';
    /**
     * The JSON serializer.
     */
    case JSON = 'json';
    /**
     * The JSON Array serializer.
     */
    case JSON_ARRAY = 'json-array';
    /**
     * The MessagePack serializer.
     */
    case MSGPACK = 'msgpack';
    /**
     * The PHP serializer.
     */
    case PHP = 'php';

    /**
     * Get the PHP extension this serializer needs.
     *
     * @since 4.2
     *
     * @return string|null The extension name, or null when it needs none
     */
    public function getExtension() : ?string
    {
        return match ($this) {
            self::IGBINARY => 'igbinary',
            self::JSON, self::JSON_ARRAY => 'json',
            self::MSGPACK => 'msgpack',
            self::PHP => null,
        };
    }

    /**
     * Tell whether this serializer can be used on this installation.
     *
     * @since 4.2
     *
     * @return bool
     */
    public function isAvailable() : bool
    {
        return match ($this) {
            self::IGBINARY => \function_exists('igbinary_serialize'),
            self::JSON, self::JSON_ARRAY => \function_exists('json_encode'),
            self::MSGPACK => \function_exists('msgpack_serialize'),
            self::PHP => true,
        };
    }

    /**
     * Get every serializer usable on this installation.
     *
     * ```php
     * $names = \array_column(Serializer::available(), 'value');
     * ```
     *
     * @since 4.2
     *
     * @return array<int,self>
     */
    public static function available() : array
    {
        return \array_values(
            \array_filter(
                self::cases(),
                static fn (self $serializer) : bool => $serializer->isAvailable()
            )
        );
    }
}
