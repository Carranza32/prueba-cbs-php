<?php
/**
 * Request-scoped memo for the per-siteId rules lookups (OE-26548).
 *
 * @package NorthStarOnlineOrdering
 */

namespace CBSNorthStar\Helpers;

/**
 * Component::getComponentsRule() and ServingOption::getOrSaveServingOptionRules()
 * each ran an uncached `SELECT ... FROM cbs_save_api_response WHERE siteid = ...`
 * per component / per serving-option category, per product — 72-500+ round trips
 * on a 12-product page even though siteId never changes within a request. This
 * memo collapses that to at most one query per site per request per rules type.
 *
 * Static, so the memo lives for the lifetime of the PHP request (WordPress
 * processes are not long-running) and is reset explicitly between test cases.
 * The deploy path (SaveProduct's `$apiCallCount == 0` branch) writes fresh rules
 * to the DB and bypasses this memo entirely, so it always sees current data.
 */
class SiteRulesCache {

	/** @var array<string,mixed> siteId => decoded sites_rules (or null). */
	private static array $sitesRulesMemo = [];

	/** @var array<string,mixed> siteId => decoded servingoptions_rules (or null). */
	private static array $servingOptionRulesMemo = [];

	/**
	 * Memoise the sites_rules lookup for a siteId within the current request.
	 *
	 * `array_key_exists` (not `isset`) so a loader that legitimately returns
	 * null is still remembered — otherwise a site with no row would re-run the
	 * query on every call instead of memoising the "no rules" outcome.
	 *
	 * @param string   $siteId
	 * @param callable $loader Runs the real DB read; invoked at most once per siteId.
	 * @return mixed
	 */
	public static function rememberSitesRulesJson( string $siteId, callable $loader ) {
		if ( ! array_key_exists( $siteId, self::$sitesRulesMemo ) ) {
			self::$sitesRulesMemo[ $siteId ] = $loader();
		}
		return self::$sitesRulesMemo[ $siteId ];
	}

	/**
	 * Memoise the servingoptions_rules lookup for a siteId within the current request.
	 *
	 * @param string   $siteId
	 * @param callable $loader Runs the real DB read; invoked at most once per siteId.
	 * @return mixed
	 */
	public static function rememberServingOptionRulesJson( string $siteId, callable $loader ) {
		if ( ! array_key_exists( $siteId, self::$servingOptionRulesMemo ) ) {
			self::$servingOptionRulesMemo[ $siteId ] = $loader();
		}
		return self::$servingOptionRulesMemo[ $siteId ];
	}

	/**
	 * Overwrite the memoised servingoptions_rules for a siteId with a fresh value.
	 *
	 * Needed because getOrSaveServingOptionRules() is get-OR-SAVE: when the DB has
	 * no rules yet, the read memoises null and the method falls through to the
	 * OEAPI fetch + DB write. Without this write-back, every later call in the
	 * same request would see the memoised null and re-run the OEAPI fetch — the
	 * fetch path must prime the memo with what it just persisted.
	 *
	 * @param string $siteId
	 * @param mixed  $json Raw JSON string as persisted to cbs_save_api_response.
	 */
	public static function primeServingOptionRulesJson( string $siteId, $json ): void {
		self::$servingOptionRulesMemo[ $siteId ] = $json;
	}

	/** Clear both memos. Deploy code never needs this; tests call it in setUp(). */
	public static function reset(): void {
		self::$sitesRulesMemo = [];
		self::$servingOptionRulesMemo = [];
	}
}
