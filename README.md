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
