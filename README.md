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
