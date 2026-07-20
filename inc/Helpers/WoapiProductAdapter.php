<?php
namespace CBSNorthStar\Helpers;
use CBSNorthStar\Models\ProductParams;
class WoapiProductAdapter
{
  public static function adaptProductData(array $productData, int $termId ) : ProductParams
  {
    return new ProductParams([
      'proName' => $productData['itemName'] ?? '',
      'proprice' => $productData['price'] ?? 0.0,
      'proDes' => $productData['itemDesc'] ?? '',
      'numberOfPlacements' => $productData['numberOfPlacements'] ?? 0,
      'type' => $productData['type'] ?? '',
      'comboQualifierIds' => $productData['comboQualifierIds'] ?? [],
      'termId' => $termId,
      'proImg' => $productData['mediaItemId'] ?? '',
      'components' => $productData['components_data'] ?? [],
      'siteid' => $productData['siteId'] ?? '',
      'itemid' => $productData['MenuItemId'] ?? '',
      'mediaItemId' => $productData['mediaItemId'] ?? null,
      'displayOrder' => $productData['displayOrder'] ?? 0,
      'servingOptions' => $productData['servingOptionsData'] ?? [],
      'status' => $productData['status'] ?? null,
      'available' => $productData['available'] ?? null,
      'linkToCategory' => $productData['linkTo'] ?? null,
      'activeStart' => $productData['activeStart'] ?? null,
      'activeStop' => $productData['activeStop'] ?? null,
    ]);
  }

    /**
     * Whether a WOAPI menu item node is active for the site.
     *
     * Any falsy IsActive value (false, 0) marks the item inactive — matching
     * the truthy check getItemDetails() applies to the same flag. A null item
     * or a missing/null flag counts as active so a payload-shape change can
     * never mass-hide items.
     *
     * @param object|null $item Menu item node from the site menu payload.
     */
    public static function isMenuItemActive(?object $item): bool
    {
        if ( ! isset( $item->IsActive ) ) {
            return true;
        }
        return (bool) $item->IsActive;
    }

    /**
     * Parse a raw `/menuitems` `StartDate`/`EndDate` value into an absolute UTC
     * unix timestamp for `_active_start_{siteId}`/`_active_stop_{siteId}`
     * (product-active-date-window capability).
     *
     * Confirmed live (2026-07-13): this is the same CBS/WOAPI backend as the
     * available-slots API, which is already documented elsewhere in this
     * plugin (see MainController::getMenuIdForDateTime()'s date_parse() call,
     * SiteClock::slotHasPassed(), TimeSlotService::getFormattedTimeSlots())
     * as tagging the SITE's local wall-clock time with a `+00:00`/`Z`-looking
     * suffix that is NOT genuine UTC — e.g. `"...T09:18:00+00:00"` means 09:18
     * local, not 09:18 UTC. The offset/zone suffix is therefore always
     * IGNORED here, exactly like every other CBS date field in this plugin —
     * never trusted as a real UTC instant, even when present. The literal
     * wall-clock digits are read via date_parse() and converted using the
     * SITE's own configured timezone via {@see SiteClock::epochForSiteLocal()}.
     *
     * Fails open (null) on anything unparseable — per Decision 1's
     * missing-meta-means-unconstrained semantics, a malformed value must
     * never be silently treated as "expired" or "not yet started".
     *
     * @param mixed  $rawValue Raw `StartDate`/`EndDate` value from `/menuitems`.
     * @param string $siteId   Site id this value was fetched for.
     */
    public static function parseActiveDate( $rawValue, string $siteId ): ?int
    {
        if ( ! is_string( $rawValue ) && ! is_int( $rawValue ) ) {
            return null;
        }
        $rawValue = trim( (string) $rawValue );
        if ( '' === $rawValue ) {
            return null;
        }

        // Defensive: a bare unix timestamp, in case WOAPI ever sends one directly.
        // Unambiguous either way — there is no offset to (mis)interpret.
        if ( ctype_digit( $rawValue ) && strlen( $rawValue ) >= 9 ) {
            return (int) $rawValue;
        }

        $parsed = date_parse( $rawValue );
        if (
            ! $parsed || $parsed['error_count'] > 0
            || false === $parsed['year'] || false === $parsed['month'] || false === $parsed['day']
        ) {
            return null;
        }

        // date_parse() only checks token syntax, not calendar validity — e.g.
        // "2026-02-31" comes back with error_count 0 and day=31. Left unchecked,
        // DateTime (below, via epochForSiteLocal()) silently rolls an impossible
        // date over to a nearby valid one (Feb 31 -> Mar 3) instead of failing
        // open, which would misrepresent a bad ECM value as a real boundary.
        if ( ! checkdate( (int) $parsed['month'], (int) $parsed['day'], (int) $parsed['year'] ) ) {
            return null;
        }

        $localWallClock = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $parsed['year'],
            $parsed['month'],
            $parsed['day'],
            false === $parsed['hour'] ? 0 : (int) $parsed['hour'],
            false === $parsed['minute'] ? 0 : (int) $parsed['minute'],
            false === $parsed['second'] ? 0 : (int) $parsed['second']
        );

        return SiteClock::epochForSiteLocal( $siteId, $localWallClock );
    }

    /**
     * Adapts the payload coming from category definitions in WOAPI to menu items.
     *
     * @param mixed $payload The payload data from WOAPI category definitions.
     * @return array The adapted menu items array.
     */
    public function toMenuItemsObject($payload): \stdClass
    {
        $items = [];

        $walk = function ($node) use (&$walk, &$items) {
            if (is_array($node) || is_object($node)) {
                $arr = (array) $node;

                if (isset($arr['MenuItem']) && (is_array($arr['MenuItem']) || is_object($arr['MenuItem']))) {
                    $mi = (array) $arr['MenuItem'];
                    $items[] = (object) [
                        'DisplayOrder'     => $mi['DisplayOrder']     ?? null,
                        'MenuItemId'       => $mi['MenuItemId']       ?? null,
                        'PricingOverrides' => $mi['PricingOverrides'] ?? null,
                    ];
                }

                foreach ($arr as $child) {
                    $walk($child);
                }
            }
        };

        $walk($payload);

        return (object) $items;
    }
}
