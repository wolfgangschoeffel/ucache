# UCache

A simple, filesystem based html(/xml/json/...) cache

## Installation

Via composer:

```sh
composer require em4nl/ucache
```

## Usage

Assuming you're using autoloading and your composer vendor dir is
at `./vendor`:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$cache = new Em4nl\U\Cache(__DIR__ . '/cache');

// not mandatory: register cache invalidation function
$cache->invalidate(function($filename) {
    // don't serve files from cache that are older than 3 hours
    // (returning TRUE here means TO INVALIDATE, returning false
    // or nothing means the file remains in the cache!)
    return filemtime($filename) < time() - 60 * 60 * 3;
});

// try to serve from cache; if that fails, create the output and
// cache it for next time
if (!$cache->serve()) {
    $cache->start();
    echo 'Hello World';
    $cache->end();
}
```

## The cache key and query strings

By default the cache key is the whole request URI, query string
included.

Pass `cache_key_params` to change what's part of the key. An empty
array means no query parameter matters at all - for a site whose
router already ignores the query string and no route reads `$_GET`,
so `/projekte` and `/projekte?kategorie=neubau` share one cache
entry:

```php
$cache = new Em4nl\U\Cache(__DIR__ . '/cache', NULL, [
    'cache_key_params' => [],
]);
```

Or a non-empty list to keep only those parameters, dropping
everything else:

```php
// only ?kategorie= makes a distinct page
$cache = new Em4nl\U\Cache(__DIR__ . '/cache', NULL, [
    'cache_key_params' => ['kategorie'],
]);
```

The key is built from your list rather than from what came in, so
`?a=1&b=2` and `?b=2&a=1` also share one entry.

**Omitting the option keeps the default behaviour**, so a site whose
responses depend on `$_GET` is unaffected: the variant produced for
one query string is never served for a different one.

This is a fixed setting for the whole process, not derived per
request - there is no route lookup involved that could know more by
the time a response is written than it did when the cache was first
consulted, so the two can never disagree.

Parameter names are checked against `parse_str()`, which rewrites a
dot or a space in a name to an underscore. `'a.b'` could therefore
never match anything and would silently drop the parameter it was
meant to keep, so it throws instead - use `'a_b'`, the name PHP
actually produces.

## The cache key's path

By default the path part of the key comes from `REQUEST_URI`. If your
application routes requests itself, pass the path your router matched
on instead:

```php
$cache = new Em4nl\U\Cache(__DIR__ . '/cache', NULL, [
    'cache_key_path' => $my_router->matched_path(),
]);
```

Whatever the router considers the same page has to end up under the
same key. Most routers normalise the path before matching, so pass a
path that is normalised the same way the router normalises it.

## Development

Install dependencies

```sh
composer install
```

Run tests
```sh
./vendor/bin/phpunit tests
```

## License

[The MIT License](https://github.com/em4nl/ucache/blob/master/LICENSE)
