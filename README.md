# Webisters Cache

A caching library with one API over seven storage backends, so the driver is a configuration
choice rather than something the calling code has to know about.

## Drivers

| Driver | Class | Backed by | Survives the request | Shared between servers |
| --- | --- | --- | --- | --- |
| APCu | `ApcuCache` | Shared memory on the machine | Yes | No |
| Redis | `RedisCache` | A Redis server | Yes | Yes |
| Memcached | `MemcachedCache` | A Memcached pool | Yes | Yes |
| Files | `FilesCache` | A directory on disk | Yes | Only on shared storage |
| Database | `DatabaseCache` | A MariaDB or MySQL table | Yes | Yes |
| Array | `ArrayCache` | A PHP array | No | No |
| Null | `NullCache` | Nothing, every read misses | No | No |

`ArrayCache` suits tests and request-scoped memoization. `NullCache` turns caching off without
the calling code having to change.

## What It Provides

- **The basics on every driver**: `get`, `set`, `delete`, their multi-key forms, `flush`,
  `increment` and `decrement`, with a Time To Live on each item.
- **Compute on miss**: `remember()` and `getOrSet()` build a value only when it is not cached.
- **Stampede protection**: `rememberProtected()` recomputes an expiring item once instead of
  once per concurrent request, using early recompute and a lock.
- **Tag-based invalidation**: group items under tags and drop them together, on any driver,
  including the ones with no native tag support.
- **Atomic primitives**: `add()` writes only when a key is absent, and `lock()`/`unlock()` build
  mutual exclusion on top of it.
- **Pluggable serialization**: PHP `serialize`, igbinary, JSON, JSON as arrays, or msgpack.
- **Debug collector** integration for the Webisters debug toolbar.

## Try It

The `demo/` folder has three short scripts you can run instead of reading about it:

```bash
cd demo && composer install
php 1-tags.php       # tags, on a folder of files
php 2-stampede.php   # ten requests, one query
php 3-keys.php       # keys most caches refuse
```

They need PHP 8.2 and nothing else. No Redis, no Memcached, no database.

## Usage

Every driver answers the same calls, so the only thing that differs between them is the line that
builds one. Pick the driver in configuration and the rest of the code never has to know:

```php
$cache->set('user.1', ['name' => 'Ada'], 300); // true
$cache->get('user.1');                         // ['name' => 'Ada'], or null on a miss
$cache->delete('user.1');                      // true
$cache->increment('hits');                     // 1
$cache->increment('hits', 5);                  // 6
$cache->decrement('hits', 2);                  // 4
```

The constructor is the same shape everywhere too:

```php
new SomeCache($configs, $prefix, $serializer, $logger);
```

Only `$configs` is required, and only for the drivers that need somewhere to connect to. `$prefix`
is what lets several applications share one storage without colliding: it is prepended to every
key, except on `FilesCache`, where it names a subdirectory instead (see below).

### Files

Items live in a directory. Good default when there is no cache server to hand.

```php
use Framework\Cache\FilesCache;

$cache = new FilesCache([
    'directory' => '/var/www/app/storage/cache', // must already exist and be writable
    'files_permission' => 0644,
    'gc' => 1, // percent of destructs that collect expired items, 0 to leave it to cron
]);

$cache->set('user.1', ['name' => 'Ada'], 300);
$cache->get('user.1');
$cache->delete('user.1');
$cache->increment('hits');
```

Leave `directory` out and it uses a directory of its own inside the system temp directory. See
[Maintenance](#maintenance) for collecting expired files.

**The prefix works differently here.** On every other driver it is prepended to the key. On this
one it names a **subdirectory of `directory`**, which must already exist:

```php
// /var/www/app/storage/cache/app/ has to be there first
$cache = new FilesCache(['directory' => '/var/www/app/storage/cache'], 'app');
```

Construction throws `Invalid cache directory path` if it is not, rather than creating it.

### APCu

Shared memory on the machine, so it is the fastest option and the one that goes no further than
the one server. Needs `ext-apcu`, and `apc.enable_cli=1` to work from the command line.

```php
use Framework\Cache\ApcuCache;

$cache = new ApcuCache([], 'app-');

$cache->set('user.1', ['name' => 'Ada'], 300);
$cache->get('user.1');
$cache->delete('user.1');
$cache->increment('hits');
```

### Redis

Shared between servers and survives a restart. Needs `ext-redis`.

```php
use Framework\Cache\RedisCache;

$cache = new RedisCache([
    'host' => '127.0.0.1',
    'port' => 6379,
    'timeout' => 2.5,
    'password' => null,
    'database' => null,
], 'app-');

$cache->set('user.1', ['name' => 'Ada'], 300);
$cache->get('user.1');
$cache->delete('user.1');
$cache->increment('hits');
```

An existing `Redis` object can be handed over instead of configs, in which case the connection is
left open when the cache goes away:

```php
$cache = new RedisCache($redis, 'app-');
```

### Memcached

A pool of servers, weighted. Needs `ext-memcached`.

```php
use Framework\Cache\MemcachedCache;

$cache = new MemcachedCache([
    'servers' => [
        ['host' => '10.0.0.1', 'port' => 11211, 'weight' => 2],
        ['host' => '10.0.0.2', 'port' => 11211, 'weight' => 1],
    ],
], 'app-');

$cache->set('user.1', ['name' => 'Ada'], 300);
$cache->get('user.1');
$cache->delete('user.1');
$cache->increment('hits');
```

Construction fails if no server in the pool answers, naming the ones it tried. As with Redis, an
existing `Memcached` object can be passed instead.

### Database

A table in MariaDB or MySQL, for a cache that outlives a restart of everything else. Needs
`webisters/database`, which is a suggestion rather than a requirement, so install it as well.

```php
use Framework\Cache\DatabaseCache;

$cache = new DatabaseCache([
    'host' => '127.0.0.1',
    'port' => 3306,
    'username' => 'app',
    'password' => 'secret',
    'schema' => 'app',
    'table' => 'Cache',
], 'app-');

$cache->createTable(); // once, or leave it to a migration

$cache->set('user.1', ['name' => 'Ada'], 300);
$cache->get('user.1');
$cache->delete('user.1');
$cache->increment('hits');
```

`getMulti()` reads every key in one statement here, which is worth reaching for when the round
trip is a database query. See [Maintenance](#maintenance) for collecting expired rows.

### Array

A PHP array, so nothing is shared and nothing outlives the request. For tests, and for not
fetching the same value twice while one request is handled.

```php
use Framework\Cache\ArrayCache;

$cache = new ArrayCache();

$cache->set('user.1', ['name' => 'Ada'], 300);
$cache->get('user.1');
$cache->delete('user.1');
$cache->increment('hits');
```

### Null

Stores nothing and reports success, so caching can be switched off without the calling code
changing:

```php
use Framework\Cache\NullCache;

$cache = IS_DEV ? new NullCache() : new RedisCache($configs, 'app-');

$cache->set('user.1', ['name' => 'Ada'], 300); // true
$cache->get('user.1');                         // null, always
$cache->delete('user.1');                      // true
$cache->increment('hits');                     // 1, every time
```

## Serialization

Values are turned into bytes on the way into the storage and back on the way out. Which
serializer does that is the third constructor argument, as an enum case or its name:

```php
use Framework\Cache\Serializer;

new FilesCache($configs, $prefix, Serializer::IGBINARY);
new FilesCache($configs, $prefix, 'igbinary');
```

The default is `Serializer::PHP`.

| Serializer | Needs | Keeps objects | Size and speed | Readable outside PHP |
| --- | --- | --- | --- | --- |
| `PHP` | nothing | Yes | Baseline | No |
| `IGBINARY` | `ext-igbinary` | Yes | Smaller and faster than PHP | No |
| `MSGPACK` | `ext-msgpack` | Yes | Compact binary | Yes |
| `JSON` | `ext-json` | No, see below | Text, larger | Yes |
| `JSON_ARRAY` | `ext-json` | No, see below | Text, larger | Yes |

### What each one gives back

This is the part worth knowing before choosing. Strings, integers, floats, booleans and lists
come back unchanged from all five. Anything else depends:

| Stored | `PHP`, `IGBINARY`, `MSGPACK` | `JSON` | `JSON_ARRAY` |
| --- | --- | --- | --- |
| `['a' => 1]` | `array` | **`stdClass`** | `array` |
| An object | Its own class | **`stdClass`** | **`array`** |

So the JSON serializers lose the class of an object, and `JSON` also turns an associative array
into an object. Reach for them when the cached data is plain and something other than PHP may
read it. Use `PHP`, `IGBINARY` or `MSGPACK` when a value has to come back exactly as it went in.

`PHP` and `IGBINARY` rebuild objects, which means `__wakeup()` and `__unserialize()` run on read.
Do not point them at a storage something untrusted can write to.

### Checking what is available

`IGBINARY` and `MSGPACK` need extensions that may not be installed:

```php
Serializer::IGBINARY->isAvailable();  // bool
Serializer::IGBINARY->getExtension(); // 'igbinary'
Serializer::available();              // every usable case
```

Choosing one that is not installed throws at construction, naming the missing extension and the
ones that would work, rather than failing later on the first write with an undefined function.

`MemcachedCache` is the exception: it hands values to Memcached whole and Memcached serializes
them with the support it was compiled with, so what PHP has loaded is not the question. An
unusable choice there is reported through the logger when the connection is set up.

## Time To Live

Every write takes a TTL in seconds, saying how long the item stays readable.

```php
$cache->set('key', $value, 300); // readable for five minutes
```

| TTL | Meaning |
| --- | --- |
| A positive integer | Seconds the item stays readable |
| `null` (the default argument) | Use the instance default, see below |
| `0` or negative | The item is expired on arrival, so nothing is stored |

These mean the same on every driver. An already-expired TTL is decided before the storage is
touched, because the backends disagree left to themselves: APCu and Memcached read a `0` as *never
expire*, Redis refuses a non-positive expiry outright, and the files, array and database drivers
write an item that is stale the moment it lands.

`set()` with an expired TTL removes anything already under that name and reports `true`, the item
correctly not being there afterwards. `add()` reports `false`, since nothing was added, and leaves
an existing item alone, because `add()` never overwrites.

### The instance default

Passing no TTL uses the instance default, which starts at 60 seconds:

```php
$cache->getDefaultTtl();   // 60
$cache->setDefaultTtl(300);
$cache->set('key', $value); // now readable for five minutes
```

`setDefaultTtl()` rejects anything below 1, so the default can never be a value that expires
immediately. `increment()`, `decrement()`, `remember()` and the tagged writes all take a TTL the
same way and fall back to the same default.

### Expiry is lazy

An expired item stops being readable at once, but the space it used is not always reclaimed at
that moment. Redis, Memcached and APCu evict on their own. The files and database drivers skip
expired items on read and leave the removal to the garbage collector, so a read never turns into
a write. See Maintenance below.

## Maintenance

`FilesCache` and `DatabaseCache` skip expired items on read but leave them where they are, so a
read never turns into a write. Nothing reclaims that space on its own, and a cache with many
short-lived keys keeps growing.

`purge()` removes them and reports how many went, which is what a scheduled job wants:

```php
$removed = $cache->purge();
```

By default each instance also collects on destruction, with a probability set by the `gc` config
(`1` means one request in a hundred pays for it). Running a cron job instead lets requests skip
that work entirely; set `gc` to `0` to turn the inline collection off.

```php
// config/cache.php
'default' => [
    'class' => Framework\Cache\FilesCache::class,
    'configs' => [
        'directory' => STORAGE_DIR . 'cache',
        'gc' => 0, // collected by the cron job below
    ],
],
```

```php
#!/usr/bin/env php
<?php // bin/cache-purge
require __DIR__ . '/../vendor/autoload.php';

$cache = new Framework\Cache\FilesCache(['directory' => __DIR__ . '/../storage/cache', 'gc' => 0]);
echo $cache->purge(), ' expired items removed', \PHP_EOL;
```

```cron
*/15 * * * * /usr/bin/php /srv/app/bin/cache-purge >> /var/log/cache-purge.log 2>&1
```

How often to run it depends on how fast keys expire and how much space is spare. Every fifteen
minutes suits most applications; a cache holding large items with short TTLs wants it more often.

`ArrayCache` also has `purge()`, though it only gives memory back on a long-running process, and
the APCu, Redis and Memcached servers evict expired items themselves, so they need none of this.

## Installation
```bash
composer require webisters/cache
```

## Requirements
- PHP: `>=8.2`
- Composer: Compatible with Composer 2.x.

## Documentation
- Guide: https://docs.webisters.com/guides/libraries/cache/
- Package: https://webisters.com/packages/cache

## Included in Webisters Framework
If you're building a full Webisters application, install the framework meta-package:

```bash
composer require webisters/framework
```

## Development
```bash
composer install
vendor/bin/phpunit
```
Follow consistent coding style and run available linters before opening pull requests.

## Support
- Issues: https://github.com/webisters/cache/issues
- Source: https://github.com/webisters/cache
- Documentation: https://webisters.com
- Forum: https://github.com/webisters/forum
- Email: support@webisters.com

## License
MIT
