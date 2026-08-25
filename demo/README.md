# Demos

Three things this library does, that you can run rather than read about.

```bash
composer install
php 1-tags.php       # tags, on a folder of files
php 2-stampede.php   # ten requests, one query
php 3-keys.php       # keys most caches refuse
```

Needs PHP 8.2 or newer, and nothing else. No Redis, no Memcached, no database.

Composer takes the library from `../`, so these always run the code in this
checkout rather than whatever is on Packagist.

## What each one shows

**`1-tags.php`** groups items under tags and drops a group in one call, on the
plain files driver. Tags are usually a Redis-only feature.

**`2-stampede.php`** is the one worth watching. Ten workers want the same expired
key at the same moment, and the value takes 400ms to build. `remember()` runs the
query ten times. `rememberProtected()` runs it once and hands the answer to the
other nine.

**`3-keys.php`** stores under keys with spaces, 5,000 characters, unicode and a
whole URL. Memcached refuses spaces and anything past 250 bytes; the library
digests the key so your code never has to know.

`storage/` and `marks/` are created on first run and can be deleted at any time.
