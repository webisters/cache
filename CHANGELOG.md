# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-11-01

A safety release. Two of the changes stop a routine looking call destroying data
it was never meant to touch, which is why the major version moves.

### Security

- `RedisCache::flush()` called `FLUSHALL`, which removes every key from every
  database on the server. An application configured onto `database: 3`
  precisely to keep out of everyone's way still destroyed databases 0 to 15
  when it called a method that reads like a local cache clear. `FLUSHALL` is
  now used nowhere in this driver, and nothing in it can clear the whole server
  ([#48]).

### Changed

- **`flush()` clears only this instance's items.** It used to clear the whole
  storage on every driver except the files one, ignoring the prefix that exists
  so applications can share a storage. `flushStorage()` is the deliberate
  opposite and ignores the prefix. Per driver: Redis uses `SCAN` then `DEL`,
  APCu an `APCuIterator`, Database `INSTR(key, prefix) = 1`, Array filters its
  own array, and Files was already scoped because its prefix is a directory
  ([#49]).
- **Memcached cannot be scoped** and is the one exception. It offers no way to
  list or select keys, so its `flush()` still clears the instance. This is now
  stated in the docblock and the README rather than left to be discovered, and
  `flushStorage()` exists so a caller can say what they mean ([#49]).
- `FilesCache` creates the prefix subdirectory when it is missing, as `0700`,
  instead of refusing to construct. The same prefix argument that works on
  every other driver no longer stops this one from starting ([#52]).
- The stored name of a key long enough to be digested now carries the prefix
  outside the hash, so it can be found by prefix. Affects Memcached over 250
  characters and Database over 255; those entries are not found after upgrading
  and age out as misses ([#66]).

### Fixed

- `FilesCache::flush()`, `gc()`, `purge()` and `deleteAll()` silently did
  nothing when the configured `directory` held a `.` or `..` segment.
  `realpath()` was called and then thrown away, so `openDir()` compared a
  canonical path against an unresolved one and refused every walk. `flush()`
  returned `false` and the items stayed readable ([#62]).
- `FilesCache::add()` could refuse every caller on a key that was not there at
  all, around 10% of rounds with five processes on Windows. The winner claimed
  the name with an exclusive create, failed to rename over its own placeholder,
  and deleted the placeholder on the way out. The rename is gone from that
  path, so the outcome cannot happen by construction. Since `lock()` is
  `add()`, this also cost counters increments while reporting no timeouts
  ([#55]).
- `FilesCache` no longer logs `unlink(): No such file or directory` under
  concurrency, and no longer reports an already deleted file as a failure
  ([#55]).
- `RedisCache::set()` passed the TTL to `Redis::set()` as a bare `int`, the
  legacy phpredis form that current stubs no longer accept, which had left the
  static-analysis gate failing. Now `['ex' => $ttl]`, the same command.

### Performance

- `getMulti()`, `setMulti()` and `deleteMulti()` are batched on Redis and
  Memcached. They were inherited from `Cache`, which loops, so fifty keys meant
  fifty round trips on the two drivers people reach for under load. Redis uses
  one `MGET` and one pipeline; Memcached uses its native multi calls. APCu is
  deliberately left measuring-first, tracked in [#65] ([#50]).
- A repeated tagged write now costs what an untagged one costs. Every tagged
  write used to rewrite the version of every tag it touched, so three tags cost
  four writes for ever. A version now carries the time it is next due for
  renewal and is rewritten once per window, which is half the tag TTL, so it
  still cannot lapse. Measured: three tags over ten writes went from 40 writes
  to 10 ([#51]).

### Added

- `Cache::flushStorage()`, overridden per driver, for clearing the storage
  rather than this instance's items ([#49]).
- `CHANGELOG.md`.

### Documentation

- The README documents the `flush()` and `flushStorage()` split with a
  per-driver table and the Memcached caveat.

### Decided, not changed

Recorded here so the reasoning is not only in an issue:

- Staying at PHPStan level 8. It is the highest level this codebase reaches
  clean, so the job is a gate rather than a record of accepted failures. Level
  9's 76 findings are a real question about the public `mixed` types, not
  busywork to silence ([#54]).
- Counters keep their lock rather than using native atomic operations. The
  native calls cannot touch a serialized `i:5;`, so using them means counters
  stored differently from every other item, which raises upgrade and
  cross-driver questions this design does not currently have to answer ([#53]).

## [1.1.1] - 2026-08-25

### Documentation

- A demo folder that runs.

## [1.1.0] - 2026-08-25

### Added

- A PSR-16 `SimpleCache` adapter, `purge()`, and documentation for collecting
  expired items from cron.
- PHPStan at level 8 with a composer script and a CI job, and CI across the
  whole PHP range `composer.json` claims to support.

### Fixed

- Keys of any shape are accepted on every driver, including ones longer than
  the database column.
- Internal bookkeeping keys are kept out of reach of ordinary keys.
- Counters no longer lose concurrent moves.
- TTL semantics are the same on every driver.

## [1.0.2] - 2026-05-01

Tagged with no changes over 1.0.1.

## [1.0.1] - 2026-05-01

### Documentation

- README corrections.

## [1.0.0] - 2026-04-29

### Added

- Initial release, with the Array, APCu, Database, Files, Memcached, Null and
  Redis drivers, tagging, locks and counters.

[Unreleased]: https://github.com/webisters/cache/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/webisters/cache/compare/v1.1.1...v2.0.0
[1.1.1]: https://github.com/webisters/cache/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/webisters/cache/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/webisters/cache/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/webisters/cache/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/webisters/cache/releases/tag/v1.0.0

[#48]: https://github.com/webisters/cache/issues/48
[#49]: https://github.com/webisters/cache/issues/49
[#50]: https://github.com/webisters/cache/issues/50
[#51]: https://github.com/webisters/cache/issues/51
[#52]: https://github.com/webisters/cache/issues/52
[#53]: https://github.com/webisters/cache/issues/53
[#54]: https://github.com/webisters/cache/issues/54
[#55]: https://github.com/webisters/cache/issues/55
[#62]: https://github.com/webisters/cache/issues/62
[#65]: https://github.com/webisters/cache/issues/65
[#66]: https://github.com/webisters/cache/issues/66
