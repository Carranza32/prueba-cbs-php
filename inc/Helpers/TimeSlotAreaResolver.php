<?php

namespace CBSNorthStar\Helpers;

/**
 * Resolves which areaId to send to the timeslots /available request (OE-26541).
 *
 * Each site maps to exactly one area in cbs_site_details, so the visited site's
 * configured area is authoritative. The client (request param / cookies) may carry a
 * stale areaId from a previously visited site; this reconciliation enforces the
 * visited site's area whenever the site has a configured area, and falls back to the
 * client value only when the site has none (preserving prior behavior).
 */
class TimeSlotAreaResolver {

	/**
	 * Pick the effective areaId for a site.
	 *
	 * @param  string $clientAreaId    areaId supplied by the client (request param / cookie).
	 * @param  string $canonicalAreaId Site's configured areaid from cbs_site_details ('' if none).
	 * @return string The areaId to use for /available.
	 */
	public static function resolveForSite( string $clientAreaId, string $canonicalAreaId ): string {
		if ( '' !== $canonicalAreaId && $canonicalAreaId !== $clientAreaId ) {
			return $canonicalAreaId;
		}

		return $clientAreaId;
	}
}
