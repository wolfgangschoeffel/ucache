<?php

namespace Em4nl\U;

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPUnit\Framework\TestCase;


function get_cache_dir() {
    return sys_get_temp_dir() . '/ucache_test.' . uniqid();
}


$mock_headers_list = [];
function headers_list() {
    global $mock_headers_list;
    return $mock_headers_list;
}

$mock_hash_algos = \hash_algos();
function hash_algos() {
    global $mock_hash_algos;
    return $mock_hash_algos;
}


class CacheTest extends TestCase {

    function testHasDefaultProperties() {
        $cache_dir = get_cache_dir();
        $cache = new Cache($cache_dir);
        $this->assertInstanceOf(Cache::class, $cache);
        $this->assertObjectHasAttribute('dir', $cache);
        $this->assertObjectHasAttribute('types', $cache);
        $this->assertObjectHasAttribute('invalidation_callbacks', $cache);
        $this->assertIsString($cache->dir);
        $this->assertIsArray($cache->types);
        $this->assertIsArray($cache->invalidation_callbacks);
        $this->assertEquals($cache_dir, $cache->dir);
        $this->assertEquals(3, count($cache->types));
        $this->assertEmpty($cache->invalidation_callbacks);
        $this->assertEquals('html', array_values($cache->types)[0]);
        $this->assertEquals('xml', array_values($cache->types)[1]);
        $this->assertEquals('json', array_values($cache->types)[2]);
    }

    function testCanBeInitialisedWithFewerTypes() {
        $cache = new Cache(get_cache_dir(), ['text/html' => 'html']);
        $this->assertEquals(1, count($cache->types));
        $this->assertEquals('html', array_values($cache->types)[0]);
    }

    function testCannotBeInitialisedWithoutTypes() {
        $this->expectException(\Exception::class);
        $cache = new Cache(get_cache_dir(), []);
    }

    function testRegisterInvalidationCallbacks() {
        $cache = new Cache(get_cache_dir());
        $callback1 = function() {};
        $cache->invalidate($callback1);
        $this->assertEquals(1, count($cache->invalidation_callbacks));
        $this->assertEquals($callback1, $cache->invalidation_callbacks[0]);
        $callback2 = function() {};
        $cache->invalidate($callback2);
        $this->assertEquals(2, count($cache->invalidation_callbacks));
        $this->assertEquals($callback2, $cache->invalidation_callbacks[1]);
    }

    function testGetFilenameFromUri() {
        $cache = new Cache(get_cache_dir());
        $this->assertEquals(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $cache->get_filename('')
        );
        $this->assertEquals(
            'wurm.6d6fd5253189ae603637bddf2f4906d01a24fe653750fbd5a96aa8c3777253da',
            $cache->get_filename('wurm')
        );
        $this->assertEquals(
            'what-goes-up.d4d2f745122fbb85eed77dd5a4c20bbdc9c5f4457a4ae8ad8c205322ad0cf841',
            $cache->get_filename('/what/goes/up')
        );
        $this->assertEquals(
            'farqu.4e21133fc479e356cc1f0c231d91f85df026ea1afc4eb8e25d7528e85ddb9115',
            $cache->get_filename('/färqü/')
        );
        $this->assertNotEquals(
            'uhowfoinawdkf',
            $cache->get_filename('')
        );
        $this->assertNotEquals(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $cache->get_filename('wurm')
        );
    }

    function testIsValidExtension() {
        $cache = new Cache(get_cache_dir());
        $this->assertTrue($cache->is_valid_extension('html'));
        $this->assertTrue($cache->is_valid_extension('xml'));
        $this->assertTrue($cache->is_valid_extension('json'));
        $this->assertFalse($cache->is_valid_extension('xhtml'));
        $this->assertFalse($cache->is_valid_extension('wav'));
    }

    function testGetExtensionFromHeader() {
        $cache = new Cache(get_cache_dir());
        $this->assertNull($cache->get_extension_from_header());
        global $mock_headers_list;
        $mock_headers_list = [
            'X-Wurm: 9003',
            'Content-Type: application/json',
        ];
        $this->assertEquals('json', $cache->get_extension_from_header());
        $mock_headers_list = ['Content-type: text/html'];
        $this->assertEquals('html', $cache->get_extension_from_header());
        $mock_headers_list = ['X-whatever: wurm'];
        $this->assertNull($cache->get_extension_from_header());
    }

    function testGetExtensionFromUri() {
        $cache = new Cache(get_cache_dir());
        $this->assertNull($cache->get_extension_from_uri(''));
        $this->assertNull($cache->get_extension_from_uri('/wurm'));
        $this->assertEquals(
            'html',
            $cache->get_extension_from_uri('/wurm.html')
        );
        $this->assertEquals(
            'xml',
            $cache->get_extension_from_uri('/welcome/ice-age.xml')
        );
        $this->assertNull($cache->get_extension_from_uri('/hey/move.mp4'));
    }

    private function keyFor($request_uri, $options=[]) {
        $_SERVER['REQUEST_URI'] = $request_uri;
        $cache = new Cache(get_cache_dir(), NULL, $options);
        return $cache->get_current_uri();
    }

    // the default, with no options given: upstream behaviour, the
    // whole query string is part of the key. a site whose responses
    // depend on $_GET relies on this - it must not start getting
    // another request's cached page just by upgrading the package.
    function testKeepsWholeQueryStringByDefault() {
        $this->assertEquals(
            '/projekte?kategorie=neubau',
            $this->keyFor('/projekte?kategorie=neubau')
        );
        $this->assertEquals('/?junk=1', $this->keyFor('/?junk=1'));
        // parameter order is preserved as it came in
        $this->assertEquals('/x?b=2&a=1', $this->keyFor('/x?b=2&a=1'));
        $this->assertEquals('/x', $this->keyFor('/x'));
    }

    // cache_key_params => [] opts in to ignoring the query string
    // entirely, for a site whose router already ignores it and no
    // route reads $_GET - so a distinct query string can't fill the
    // cache with a new file for no reason
    function testEmptyParamsDropsWholeQueryString() {
        $none = ['cache_key_params' => []];
        $this->assertEquals('/', $this->keyFor('/', $none));
        $this->assertEquals('/', $this->keyFor('/?junk=1', $none));
        $this->assertEquals('/', $this->keyFor('/?fbclid=abc', $none));
        $this->assertEquals('/projekte', $this->keyFor('/projekte', $none));
        $this->assertEquals(
            '/projekte',
            $this->keyFor('/projekte?kategorie=neubau', $none)
        );
        $this->assertEquals(
            '/projekte',
            $this->keyFor('/projekte?utm_source=nl&gclid=x', $none)
        );
    }

    // cache_key_params can also be a non-empty whitelist: only the
    // listed parameters are part of the key, everything else is
    // dropped
    function testWhitelistedParamsArePartOfTheKey() {
        $kat = ['cache_key_params' => ['kategorie']];
        $this->assertEquals(
            '/projekte?kategorie=neubau',
            $this->keyFor('/projekte?kategorie=neubau', $kat)
        );
        $this->assertEquals(
            '/projekte',
            $this->keyFor('/projekte?utm_source=nl', $kat)
        );
        $this->assertEquals(
            '/projekte?kategorie=x',
            $this->keyFor('/projekte?utm_source=nl&kategorie=x', $kat)
        );
        // built from our own list, so reordering the query string
        // can't produce a second entry for the same content
        $ab = ['cache_key_params' => ['a', 'b']];
        $this->assertEquals('/x?a=1&b=2', $this->keyFor('/x?a=1&b=2', $ab));
        $this->assertEquals('/x?a=1&b=2', $this->keyFor('/x?b=2&a=1', $ab));
    }

    function testCacheKeyParamsMustBeArrayOrNull() {
        $this->expectException(\Exception::class);
        new Cache(get_cache_dir(), NULL, ['cache_key_params' => 'nope']);
    }

    // parse_str turns a dot or a space in a parameter name into an
    // underscore, so such a name could never match and would silently
    // drop the parameter it was meant to keep
    function testCacheKeyParamsRejectsNamesParseStrWouldRewrite() {
        foreach (['a.b', 'a b', '', 5] as $bad) {
            $threw = FALSE;
            try {
                new Cache(get_cache_dir(), NULL,
                    ['cache_key_params' => [$bad]]);
            } catch (\Exception $e) {
                $threw = TRUE;
            }
            $this->assertTrue($threw, var_export($bad, TRUE) . ' should throw');
        }
        // the name parse_str actually produces is fine, and matches
        // a request that spelled it with a dot
        $cache = new Cache(get_cache_dir(), NULL,
            ['cache_key_params' => ['a_b']]);
        $_SERVER['REQUEST_URI'] = '/x?a.b=1';
        $this->assertEquals('/x?a_b=1', $cache->get_current_uri());
    }

    // a caller that routes requests itself passes the path its router
    // matched on, so that whatever the router treats as one page ends
    // up under one key
    function testCacheKeyPathReplacesThePath() {
        $none = ['cache_key_path' => '/projekte',
                 'cache_key_params' => []];
        $_SERVER['REQUEST_URI'] = '//projekte///?fbclid=x';
        $cache = new Cache(get_cache_dir(), NULL, $none);
        $this->assertEquals('/projekte', $cache->get_current_uri());

        // the query string is still handled the same way
        $kat = ['cache_key_path' => '/projekte',
                'cache_key_params' => ['kategorie']];
        $_SERVER['REQUEST_URI'] = '//projekte///?fbclid=x&kategorie=neubau';
        $cache = new Cache(get_cache_dir(), NULL, $kat);
        $this->assertEquals(
            '/projekte?kategorie=neubau',
            $cache->get_current_uri()
        );
    }

    // without it, nothing changes: the path still comes from
    // REQUEST_URI, exactly as upstream
    function testCacheKeyPathDefaultsToTheRequestUri() {
        $_SERVER['REQUEST_URI'] = '/projekte/';
        $cache = new Cache(get_cache_dir());
        $this->assertEquals('/projekte/', $cache->get_current_uri());
    }

    function testCacheKeyPathMustBeStringOrNull() {
        $this->expectException(\Exception::class);
        new Cache(get_cache_dir(), NULL, ['cache_key_path' => ['x']]);
    }

    // splitting happens before decoding, so an encoded ? inside a
    // path segment can't truncate the key - in either mode
    function testCurrentUriDecodesAfterSplittingOffTheQuery() {
        $this->assertEquals('/a?b/c', $this->keyFor('/a%3Fb/c'));
        $this->assertEquals('/prüfung', $this->keyFor('/pr%C3%BCfung'));
        $this->assertEquals('/prüfung?a=1', $this->keyFor('/pr%C3%BCfung?a=1'));

        $none = ['cache_key_params' => []];
        $this->assertEquals('/a?b/c', $this->keyFor('/a%3Fb/c', $none));
        $this->assertEquals('/prüfung', $this->keyFor('/pr%C3%BCfung', $none));
        $this->assertEquals(
            '/prüfung',
            $this->keyFor('/pr%C3%BCfung?a=1', $none)
        );
    }

    // two requests that differ only in the query string share one
    // cache entry when cache_key_params is set to drop it
    function testQueryStringsShareOneCacheEntryWhenDropped() {
        $cache_dir = get_cache_dir();
        $none = ['cache_key_params' => []];

        $_SERVER['REQUEST_URI'] = '/projekte?fbclid=x';
        $writer = new Cache($cache_dir, NULL, $none);
        $this->assertTrue($writer->add('<html>ok</html>'));
        $this->assertCount(1, glob("$cache_dir/*"));

        $_SERVER['REQUEST_URI'] = '/projekte?fbclid=totally-different';
        $reader = new Cache($cache_dir, NULL, $none);
        $this->assertIsString(
            $reader->find_cached_file($reader->get_current_uri())
        );

        // ... and a second write doesn't add a second file
        $this->assertTrue($reader->add('<html>ok</html>'));
        $this->assertCount(1, glob("$cache_dir/*"));

        Cache::recursive_remove_directory($cache_dir);
    }

    // by default (no options), two requests differing only in the
    // query string get two separate cache entries - unlike the mode
    // above, this is the upstream-compatible behaviour
    function testQueryStringsGetSeparateEntriesByDefault() {
        $cache_dir = get_cache_dir();

        $_SERVER['REQUEST_URI'] = '/projekte?a=1';
        $first = new Cache($cache_dir);
        $this->assertTrue($first->add('<html>one</html>'));

        $_SERVER['REQUEST_URI'] = '/projekte?a=2';
        $second = new Cache($cache_dir);
        $this->assertTrue($second->add('<html>two</html>'));

        $this->assertCount(2, glob("$cache_dir/*"));

        Cache::recursive_remove_directory($cache_dir);
    }

    function testAssertSha256Available() {
        Cache::assert_sha256_available();
        global $mock_hash_algos;
        $mock_hash_algos = [];
        $this->expectException(\Exception::class);
        Cache::assert_sha256_available();
        $mock_hash_algos = ['md5', 'sha1'];
        $this->expectException(\Exception::class);
        Cache::assert_sha256_available();
    }
}
