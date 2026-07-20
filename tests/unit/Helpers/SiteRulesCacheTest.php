<?php

namespace CBSNorthStar\Tests\Helpers;

use CBSNorthStar\Helpers\SiteRulesCache;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SiteRulesCache (OE-26548).
 *
 * The memo is what collapses the per-component / per-serving-option N+1 rules
 * queries down to at most one DB read per siteId per rules type per request.
 * These tests assert the loader only runs once per siteId, that a null result
 * is still memoised (array_key_exists, not isset), that the two memos never
 * collide, and that reset() clears both.
 *
 * Pure helper — no WordPress dependency — so it runs identically with or
 * without the WP test library loaded.
 */
final class SiteRulesCacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		SiteRulesCache::reset();
	}

	private function countingLoader( &$calls, $returnValue ) {
		return function () use ( &$calls, $returnValue ) {
			$calls++;
			return $returnValue;
		};
	}

	public function test_sites_rules_loader_runs_once_for_repeated_same_site_calls(): void {
		$calls = 0;
		$loader = $this->countingLoader( $calls, '{"Data":[]}' );

		$first  = SiteRulesCache::rememberSitesRulesJson( 'site-1', $loader );
		$second = SiteRulesCache::rememberSitesRulesJson( 'site-1', $loader );

		$this->assertSame( '{"Data":[]}', $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $calls );
	}

	public function test_sites_rules_loader_runs_again_for_a_different_site(): void {
		$calls = 0;
		$loader = $this->countingLoader( $calls, '{"Data":[]}' );

		SiteRulesCache::rememberSitesRulesJson( 'site-1', $loader );
		SiteRulesCache::rememberSitesRulesJson( 'site-2', $loader );

		$this->assertSame( 2, $calls );
	}

	public function test_serving_option_rules_loader_runs_once_for_repeated_same_site_calls(): void {
		$calls = 0;
		$loader = $this->countingLoader( $calls, '{"Data":[]}' );

		$first  = SiteRulesCache::rememberServingOptionRulesJson( 'site-1', $loader );
		$second = SiteRulesCache::rememberServingOptionRulesJson( 'site-1', $loader );

		$this->assertSame( '{"Data":[]}', $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $calls );
	}

	public function test_serving_option_rules_loader_runs_again_for_a_different_site(): void {
		$calls = 0;
		$loader = $this->countingLoader( $calls, '{"Data":[]}' );

		SiteRulesCache::rememberServingOptionRulesJson( 'site-1', $loader );
		SiteRulesCache::rememberServingOptionRulesJson( 'site-2', $loader );

		$this->assertSame( 2, $calls );
	}

	public function test_sites_rules_and_serving_option_rules_memos_do_not_collide(): void {
		$sitesCalls = 0;
		$servingCalls = 0;

		SiteRulesCache::rememberSitesRulesJson( 'site-1', $this->countingLoader( $sitesCalls, 'sites-json' ) );
		SiteRulesCache::rememberServingOptionRulesJson( 'site-1', $this->countingLoader( $servingCalls, 'serving-json' ) );

		$sitesResult   = SiteRulesCache::rememberSitesRulesJson( 'site-1', $this->countingLoader( $sitesCalls, 'sites-json' ) );
		$servingResult = SiteRulesCache::rememberServingOptionRulesJson( 'site-1', $this->countingLoader( $servingCalls, 'serving-json' ) );

		$this->assertSame( 'sites-json', $sitesResult );
		$this->assertSame( 'serving-json', $servingResult );
		$this->assertSame( 1, $sitesCalls );
		$this->assertSame( 1, $servingCalls );
	}

	public function test_null_result_is_memoised_and_loader_is_not_rerun(): void {
		$calls = 0;
		$loader = $this->countingLoader( $calls, null );

		$first  = SiteRulesCache::rememberSitesRulesJson( 'site-1', $loader );
		$second = SiteRulesCache::rememberSitesRulesJson( 'site-1', $loader );

		$this->assertNull( $first );
		$this->assertNull( $second );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Mirrors getOrSaveServingOptionRules()'s fallback: DB read memoises null,
	 * the OEAPI fetch persists fresh rules and primes the memo — later calls in
	 * the same request must serve the primed value without re-running the loader
	 * (i.e. without another OEAPI fetch).
	 */
	public function test_priming_replaces_a_memoised_null_and_loader_does_not_rerun(): void {
		$calls = 0;
		$loader = $this->countingLoader( $calls, null );

		$this->assertNull( SiteRulesCache::rememberServingOptionRulesJson( 'site-1', $loader ) );

		SiteRulesCache::primeServingOptionRulesJson( 'site-1', '{"Data":[]}' );

		$this->assertSame( '{"Data":[]}', SiteRulesCache::rememberServingOptionRulesJson( 'site-1', $loader ) );
		$this->assertSame( 1, $calls );
	}

	public function test_priming_serving_option_rules_does_not_touch_the_sites_rules_memo(): void {
		$calls = 0;

		SiteRulesCache::primeServingOptionRulesJson( 'site-1', 'serving-json' );

		$this->assertSame( 'sites-json', SiteRulesCache::rememberSitesRulesJson( 'site-1', $this->countingLoader( $calls, 'sites-json' ) ) );
		$this->assertSame( 1, $calls );
	}

	public function test_reset_clears_both_memos(): void {
		$sitesCalls = 0;
		$servingCalls = 0;

		SiteRulesCache::rememberSitesRulesJson( 'site-1', $this->countingLoader( $sitesCalls, 'sites-json' ) );
		SiteRulesCache::rememberServingOptionRulesJson( 'site-1', $this->countingLoader( $servingCalls, 'serving-json' ) );

		SiteRulesCache::reset();

		SiteRulesCache::rememberSitesRulesJson( 'site-1', $this->countingLoader( $sitesCalls, 'sites-json' ) );
		SiteRulesCache::rememberServingOptionRulesJson( 'site-1', $this->countingLoader( $servingCalls, 'serving-json' ) );

		$this->assertSame( 2, $sitesCalls );
		$this->assertSame( 2, $servingCalls );
	}
}
