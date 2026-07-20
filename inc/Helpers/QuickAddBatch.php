<?php

namespace CBSNorthStar\Helpers;

/**
 * Request-shape logic for the quick-add batch endpoint (add_to_cart_action_cbs):
 * normalizes the legacy single-item POST shape and the new `items` array shape into
 * one common list, and enforces a max batch size. Deliberately dependency-free
 * (only reads the $post array passed in, never $_POST/WC() directly) so it is
 * unit-testable without booting WordPress — same rationale as CheckValidate.
 */
class QuickAddBatch
{
    public const DEFAULT_MAX_BATCH_SIZE = 20;

    /**
     * Normalize a raw POST body into a list of item arrays:
     * ['product_id'=>int,'quantity'=>int,'variation_id'=>int,
     *  'selComponents'=>?string,'selComponentsQty'=>?string,
     *  'selComponentsPrice'=>?string,'product_price_input'=>?string]
     *
     * Accepts either the batched shape (`items` as a JSON-encoded array of item
     * objects) or the legacy single-item shape (top-level product_id/quantity/etc,
     * no `items` key) — the legacy shape normalizes to a one-item list so a browser
     * tab still running JS from before this change keeps working unchanged.
     *
     * @param array $post
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeItems(array $post): array
    {
        if (!empty($post['items'])) {
            // WordPress slashes every string in $_POST (add_magic_quotes(), applied
            // before this ever runs) — the original single-item code read component
            // data via filter_input(INPUT_POST, ...) specifically to bypass that. This
            // reads $_POST directly, so the outer `items` JSON string needs one
            // wp_unslash() pass before it will decode; nothing inside it needs a
            // second pass — WP never saw the nested structure, only this one string.
            $raw = is_string($post['items']) ? wp_unslash($post['items']) : $post['items'];
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($decoded)) {
                return [];
            }

            $items = [];
            foreach ($decoded as $item) {
                if (!is_array($item) || empty($item['product_id'])) {
                    continue;
                }
                $items[] = self::normalizeItem($item, false);
            }
            return $items;
        }

        if (empty($post['product_id'])) {
            return [];
        }

        // Legacy top-level fields come straight from $_POST and need their own
        // unslash pass (there's no outer JSON wrapper to have carried it for them).
        return [self::normalizeItem($post, true)];
    }

    /**
     * True when the batch exceeds the configured maximum item count. The caller
     * decides what to do about it (reject with an error) — the client is expected
     * to hold any overflow for a follow-up batch rather than the server silently
     * dropping items.
     */
    public static function exceedsMaxBatchSize(array $items, int $maxBatchSize = self::DEFAULT_MAX_BATCH_SIZE): bool
    {
        return count($items) > $maxBatchSize;
    }

    /**
     * @param array $raw
     * @param bool  $unslash Whether these values came straight from $_POST (legacy
     *                       top-level fields) and so still need wp_unslash() —
     *                       false for items decoded out of the already-unslashed
     *                       `items` JSON string.
     * @return array<string, mixed>
     */
    private static function normalizeItem(array $raw, bool $unslash): array
    {
        $string = static function ($value) use ($unslash) {
            if (!is_string($value)) {
                return $value;
            }
            return $unslash ? wp_unslash($value) : $value;
        };

        return [
            'product_id'          => absint($raw['product_id'] ?? 0),
            'quantity'            => max(1, absint($raw['quantity'] ?? 1)),
            'variation_id'        => absint($raw['variation_id'] ?? 0),
            'selComponents'       => $string($raw['selComponents'] ?? null),
            'selComponentsQty'    => $string($raw['selComponentsQty'] ?? null),
            'selComponentsPrice'  => $string($raw['selComponentsPrice'] ?? null),
            'product_price_input' => $string($raw['product_price_input'] ?? null),
        ];
    }
}
