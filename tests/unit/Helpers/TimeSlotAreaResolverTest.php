<?php

namespace CBSNorthStar\Tests\Helpers;

use CBSNorthStar\Helpers\TimeSlotAreaResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TimeSlotAreaResolver (OE-26541).
 *
 * The timeslots /available endpoint must use the visited site's configured area, not a
 * stale areaId the client may carry from a previously visited site. Each site maps to
 * exactly one area (cbs_site_details), so the site's area is authoritative whenever it
 * exists; otherwise the client value is preserved (prior behavior).
 */
final class TimeSlotAreaResolverTest extends TestCase {

	public function test_overrides_stale_client_area_with_site_area(): void {
		// Client carries site A's area, but we are visiting site B (area B): use B.
		$this->assertSame(
			'area-B',
			TimeSlotAreaResolver::resolveForSite( 'area-A', 'area-B' )
		);
	}

	public function test_preserves_matching_area(): void {
		$this->assertSame(
			'area-B',
			TimeSlotAreaResolver::resolveForSite( 'area-B', 'area-B' )
		);
	}

	public function test_derives_area_when_client_is_empty(): void {
		$this->assertSame(
			'area-B',
			TimeSlotAreaResolver::resolveForSite( '', 'area-B' )
		);
	}

	public function test_falls_back_to_client_when_site_has_no_area(): void {
		// Site has no configured area: keep whatever the client sent (no override).
		$this->assertSame(
			'area-A',
			TimeSlotAreaResolver::resolveForSite( 'area-A', '' )
		);
	}

	public function test_returns_empty_when_both_empty(): void {
		$this->assertSame(
			'',
			TimeSlotAreaResolver::resolveForSite( '', '' )
		);
	}
}
