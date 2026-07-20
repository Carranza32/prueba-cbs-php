<?php

namespace CBSNorthStar\Tests\Helpers;

use Brain\Monkey\Functions;
use CBSNorthStar\Helpers\QuickAddBatch;
use CBSNorthStar\Tests\TestCase;

/**
 * Unit tests for QuickAddBatch — the request-shape logic behind the quick-add
 * batch endpoint (AddToCartLoader::ajaxHandler):
 *
 *  - normalizeItems(): legacy single-item shape vs. the new batched `items`
 *    shape, both normalizing to the same item-list form.
 *  - exceedsMaxBatchSize(): the cap enforced before a batch is processed.
 */
final class QuickAddBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('absint')->alias(function ($value) {
            return abs((int) $value);
        });
        Functions\when('wp_unslash')->returnArg();
    }

    // ── normalizeItems: WordPress's add_magic_quotes() slashing ──────────

    public function testLegacyShapeUnslashesComponentFields(): void
    {
        Functions\when('wp_unslash')->alias(function ($value) {
            return is_string($value) ? stripslashes($value) : $value;
        });

        $post = [
            'product_id'    => '5',
            'quantity'      => '1',
            'selComponents' => '[{\"id\":1}]',
        ];

        $items = QuickAddBatch::normalizeItems($post);

        $this->assertSame('[{"id":1}]', $items[0]['selComponents']);
    }

    public function testBatchedShapeUnslashesOuterItemsStringOnly(): void
    {
        Functions\when('wp_unslash')->alias(function ($value) {
            return is_string($value) ? stripslashes($value) : $value;
        });

        // Simulate what $_POST['items'] looks like after WordPress slashes the
        // JSON string the client sent — one layer of backslash-escaping added
        // in front of every quote in the raw request body.
        $clientJson = json_encode([
            ['product_id' => 7, 'quantity' => 1, 'selComponents' => '[{"id":1}]'],
        ]);
        $post = ['items' => addslashes($clientJson)];

        $items = QuickAddBatch::normalizeItems($post);

        $this->assertCount(1, $items);
        $this->assertSame(7, $items[0]['product_id']);
        // The nested selComponents string was never independently slashed by
        // WordPress (it only saw the outer `items` string) — it must come out
        // exactly as the client sent it, with no further unslashing applied.
        $this->assertSame('[{"id":1}]', $items[0]['selComponents']);
    }

    // ── normalizeItems: legacy shape ─────────────────────────────────────

    public function testLegacyShapeNormalizesToOneItem(): void
    {
        $post = [
            'product_id'          => '123',
            'quantity'            => '2',
            'selComponents'       => '[{"id":1}]',
            'selComponentsQty'    => '[{"id":1,"qty":1}]',
            'selComponentsPrice'  => '1.5',
            'product_price_input' => '9.99',
        ];

        $items = QuickAddBatch::normalizeItems($post);

        $this->assertCount(1, $items);
        $this->assertSame(123, $items[0]['product_id']);
        $this->assertSame(2, $items[0]['quantity']);
        $this->assertSame(0, $items[0]['variation_id']);
        $this->assertSame('[{"id":1}]', $items[0]['selComponents']);
        $this->assertSame('[{"id":1,"qty":1}]', $items[0]['selComponentsQty']);
        $this->assertSame('1.5', $items[0]['selComponentsPrice']);
        $this->assertSame('9.99', $items[0]['product_price_input']);
    }

    public function testLegacyShapeWithoutProductIdYieldsNoItems(): void
    {
        $this->assertSame([], QuickAddBatch::normalizeItems(['quantity' => '1']));
    }

    public function testLegacyQuantityDefaultsToOneWhenMissingOrZero(): void
    {
        $items = QuickAddBatch::normalizeItems(['product_id' => '5', 'quantity' => '0']);
        $this->assertSame(1, $items[0]['quantity']);
    }

    // ── normalizeItems: batched shape ────────────────────────────────────

    public function testBatchedShapeNormalizesEachItem(): void
    {
        $post = [
            'items' => json_encode([
                ['product_id' => 10, 'quantity' => 1, 'selComponents' => '[]'],
                ['product_id' => 20, 'quantity' => 3, 'selComponents' => '[{"id":9}]'],
            ]),
        ];

        $items = QuickAddBatch::normalizeItems($post);

        $this->assertCount(2, $items);
        $this->assertSame(10, $items[0]['product_id']);
        $this->assertSame(1, $items[0]['quantity']);
        $this->assertSame(20, $items[1]['product_id']);
        $this->assertSame(3, $items[1]['quantity']);
        $this->assertSame('[{"id":9}]', $items[1]['selComponents']);
    }

    public function testBatchedShapeSkipsEntriesMissingProductId(): void
    {
        $post = [
            'items' => json_encode([
                ['quantity' => 1],
                ['product_id' => 30, 'quantity' => 1],
            ]),
        ];

        $items = QuickAddBatch::normalizeItems($post);

        $this->assertCount(1, $items);
        $this->assertSame(30, $items[0]['product_id']);
    }

    public function testBatchedShapeIgnoresMalformedJson(): void
    {
        $this->assertSame([], QuickAddBatch::normalizeItems(['items' => 'not json']));
    }

    public function testBatchedShapeTakesPrecedenceOverLegacyTopLevelFields(): void
    {
        // A well-formed `items` array should be used even if legacy top-level
        // fields are also present (defensive — shouldn't happen from the real
        // client, but the batched shape must win deterministically).
        $post = [
            'product_id' => '999',
            'items'      => json_encode([
                ['product_id' => 1, 'quantity' => 1],
            ]),
        ];

        $items = QuickAddBatch::normalizeItems($post);

        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]['product_id']);
    }

    // ── exceedsMaxBatchSize ───────────────────────────────────────────────

    public function testExceedsMaxBatchSizeDefault(): void
    {
        $items = array_fill(0, QuickAddBatch::DEFAULT_MAX_BATCH_SIZE, ['product_id' => 1]);
        $this->assertFalse(QuickAddBatch::exceedsMaxBatchSize($items));

        $items[] = ['product_id' => 1];
        $this->assertTrue(QuickAddBatch::exceedsMaxBatchSize($items));
    }

    public function testExceedsMaxBatchSizeCustomLimit(): void
    {
        $items = array_fill(0, 3, ['product_id' => 1]);
        $this->assertTrue(QuickAddBatch::exceedsMaxBatchSize($items, 2));
        $this->assertFalse(QuickAddBatch::exceedsMaxBatchSize($items, 3));
    }
}
