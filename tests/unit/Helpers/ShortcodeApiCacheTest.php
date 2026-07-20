<?php

namespace CBSNorthStar\Tests\Helpers;

use Brain\Monkey\Functions;
use CBSNorthStar\Helpers\ShortcodeApiCache;
use CBSNorthStar\Tests\TestCase;

/**
 * Unit tests for ShortcodeApiCache (OE-26548).
 *
 * Guards the contract that makes the OEAPI daypart-config caching safe:
 *  - a successful response is fetched once then served from cache,
 *  - failed / empty responses are never cached (so the next render retries),
 *  - a deploy (catalog version bump) invalidates the entry.
 *
 * WordPress is faked with Brain\Monkey: get_option / get_transient / set_transient
 * are backed by per-test in-memory arrays and the cbs_oeapi_cache_ttl filter by a
 * per-test override, so the cache logic runs without a WP runtime or database.
 *
 * @covers \CBSNorthStar\Helpers\ShortcodeApiCache
 */
final class ShortcodeApiCacheTest extends TestCase
{
    /** @var array<string,mixed> In-memory option store. */
    private array $options = [];

    /** @var array<string,mixed> In-memory transient store. */
    private array $transients = [];

    /** Value the cbs_oeapi_cache_ttl filter returns, or null to pass the value through. */
    private ?int $ttlOverride = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options     = ['cbs_catalog_cache_version' => '1'];
        $this->transients  = [];
        $this->ttlOverride = null;

        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $this->options[$name] ?? $default;
        });
        Functions\when('get_transient')->alias(function ($key) {
            return array_key_exists($key, $this->transients) ? $this->transients[$key] : false;
        });
        Functions\when('set_transient')->alias(function ($key, $value, $ttl = 0) {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('apply_filters')->alias(function ($hook, $value) {
            if ('cbs_oeapi_cache_ttl' === $hook && null !== $this->ttlOverride) {
                return $this->ttlOverride;
            }
            return $value;
        });
    }

    /** A successful OEAPI response: truthy with a non-empty ->Data. */
    private function ok(array $data = ['BclTimeZoneId' => 'UTC']): \stdClass
    {
        return (object) ['Data' => $data];
    }

    public function test_successful_response_is_fetched_once_then_cached(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) {
            $calls++;
            return $this->ok();
        };

        $first  = ShortcodeApiCache::remember('site', 'http://oeapi.test/sites/A', $fetch);
        $second = ShortcodeApiCache::remember('site', 'http://oeapi.test/sites/A', $fetch);

        $this->assertSame(1, $calls, 'fetch runs once; the second call is a cache hit');
        $this->assertEquals($first, $second);
    }

    public function test_empty_data_response_is_not_cached(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) {
            $calls++;
            return (object) ['Data' => []];
        };

        ShortcodeApiCache::remember('dayparts', 'http://oeapi.test/sites/A/dayparts', $fetch);
        ShortcodeApiCache::remember('dayparts', 'http://oeapi.test/sites/A/dayparts', $fetch);

        $this->assertSame(2, $calls, 'an empty Data response must never be cached');
    }

    public function test_null_response_is_not_cached(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) {
            $calls++;
            return null;
        };

        ShortcodeApiCache::remember('site', 'http://oeapi.test/sites/A', $fetch);
        ShortcodeApiCache::remember('site', 'http://oeapi.test/sites/A', $fetch);

        $this->assertSame(2, $calls, 'a failed (null) response must never be cached');
    }

    public function test_catalog_version_bump_invalidates_the_entry(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) {
            $calls++;
            return $this->ok();
        };

        ShortcodeApiCache::remember('site', 'http://oeapi.test/sites/A', $fetch); // miss -> fetch #1
        $this->options['cbs_catalog_cache_version'] = '2';                        // deploy bump
        ShortcodeApiCache::remember('site', 'http://oeapi.test/sites/A', $fetch); // new key -> fetch #2

        $this->assertSame(2, $calls, 'a catalog version bump changes the key -> fresh fetch');
    }

    public function test_key_varies_by_url_and_version(): void
    {
        $a = ShortcodeApiCache::key('site', 'http://oeapi.test/sites/A');
        $b = ShortcodeApiCache::key('site', 'http://oeapi.test/sites/B');
        $this->assertNotSame($a, $b, 'different site URL -> different key');

        $this->options['cbs_catalog_cache_version'] = '99';
        $c = ShortcodeApiCache::key('site', 'http://oeapi.test/sites/A');
        $this->assertNotSame($a, $c, 'different catalog version -> different key');
    }

    public function test_ttl_default_is_a_positive_int(): void
    {
        $this->assertIsInt(ShortcodeApiCache::ttl());
        $this->assertGreaterThan(0, ShortcodeApiCache::ttl());
    }

    public function test_ttl_is_filterable(): void
    {
        $this->ttlOverride = 42;
        $this->assertSame(42, ShortcodeApiCache::ttl());
    }
}
