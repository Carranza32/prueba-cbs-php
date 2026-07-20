/**
 * @jest-environment node
 *
 * Regression coverage for OE-26649: component price-layer lookup must clamp to the
 * last configured layer once quantity exceeds the highest defined layer key, instead
 * of falling back to the flat/base component price.
 *
 * Runs under the plain Node environment (not jest-environment-jsdom) because this
 * file constructs its own JSDOM instance below — nesting jsdom inside Jest's jsdom
 * environment hits a TextEncoder polyfill gap in this project's jsdom/Node version
 * pairing (same pattern as tests/js/addItemComponent.test.js).
 *
 * olo_calculate_totals.js is a plain browser script (no module exports), loaded in
 * production as a <script> tag. It only registers top-level jQuery(document).on(...)
 * handlers and one jQuery(document).ready(...) block at load time (none of which run
 * eagerly), so attaching a real jQuery to the JSDOM window before running the source
 * through vm is sufficient — no rulesGlobal seeding needed since the function under
 * test (getComponentPriceByLayer) reads only its own parameter.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { JSDOM } = require('jsdom');

function createHarness() {
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/' });
    const { window } = dom;
    const $ = require('jquery')(window);
    window.jQuery = $;
    window.$ = $;

    const source = fs.readFileSync(
        path.join(__dirname, '../../assets/src/js/components/olo-theme/olo_calculate_totals.js'),
        'utf8'
    );

    vm.createContext(window);
    vm.runInContext(source, window);

    return { window, $ };
}

describe('getComponentPriceByLayer — OE-26649 clamp to last configured layer', () => {
    let window;

    beforeEach(() => {
        ({ window } = createHarness());
    });

    describe('ticket example {1:0.5, 2:1.25, 3:5, 4:6}', () => {
        const pricingLevels = {
            1: { price: 0.5 },
            2: { price: 1.25 },
            3: { price: 5 },
            4: { price: 6 },
        };

        test('qty 1 -> 0.5 (exact layer, R2)', () => {
            expect(window.getComponentPriceByLayer({ pricingLevels, quantity: 1 })).toBe(0.5);
        });

        test('qty 4 -> 6 (exact last layer, R2)', () => {
            expect(window.getComponentPriceByLayer({ pricingLevels, quantity: 4 })).toBe(6);
        });

        test('qty 5 -> 6 (clamp, R1)', () => {
            expect(window.getComponentPriceByLayer({ pricingLevels, quantity: 5 })).toBe(6);
        });

        test('qty 6 -> 6 (clamp, R1)', () => {
            expect(window.getComponentPriceByLayer({ pricingLevels, quantity: 6 })).toBe(6);
        });

        test('qty 100 -> 6 (clamp, R1)', () => {
            expect(window.getComponentPriceByLayer({ pricingLevels, quantity: 100 })).toBe(6);
        });
    });

    test('string-keyed levels {"1".."4"} qty 5 -> 6 (R5)', () => {
        const pricingLevels = {
            '1': { price: 0.5 },
            '2': { price: 1.25 },
            '3': { price: 5 },
            '4': { price: 6 },
        };
        expect(window.getComponentPriceByLayer({ pricingLevels, quantity: 5 })).toBe(6);
    });

    test('lowercase pricinglevels alias qty 6 -> 6 (R5)', () => {
        const levels = {
            1: { price: 0.5 },
            2: { price: 1.25 },
            3: { price: 5 },
            4: { price: 6 },
        };
        expect(window.getComponentPriceByLayer({ pricinglevels: levels, quantity: 6 })).toBe(6);
    });

    describe('empty pricingLevels fallbacks (R4)', () => {
        test('empty levels + componentPrice -> componentPrice', () => {
            expect(window.getComponentPriceByLayer({ pricingLevels: {}, componentPrice: 2, quantity: 5 })).toBe(2);
        });

        test('empty levels + price (no componentPrice) -> price', () => {
            expect(window.getComponentPriceByLayer({ pricingLevels: {}, price: 3, quantity: 5 })).toBe(3);
        });

        test('empty levels + no price fields -> 0', () => {
            expect(window.getComponentPriceByLayer({ pricingLevels: {}, quantity: 5 })).toBe(0);
        });
    });

    describe('sparse levels {1, 3} (R3)', () => {
        const pricingLevels = {
            1: { price: 0.5 },
            3: { price: 5 },
        };

        test('qty 2 (mid-range, missing layer, quantity < maxLayer) -> flat fallback unchanged', () => {
            expect(window.getComponentPriceByLayer({ pricingLevels, componentPrice: 9, quantity: 2 })).toBe(9);
        });

        test('qty 4 (past max layer 3) -> clamps to layer 3 price', () => {
            expect(window.getComponentPriceByLayer({ pricingLevels, quantity: 4 })).toBe(5);
        });
    });

    test('qty 0 + componentPrice -> componentPrice (pre-existing edge, unchanged)', () => {
        const pricingLevels = { 1: { price: 0.5 }, 2: { price: 1.25 } };
        expect(window.getComponentPriceByLayer({ pricingLevels, componentPrice: 2, quantity: 0 })).toBe(2);
    });

    test('non-numeric keys only -> graceful degradation to flat fallback (R5)', () => {
        const pricingLevels = { foo: { price: 9 } };
        expect(window.getComponentPriceByLayer({ pricingLevels, componentPrice: 4, quantity: 2 })).toBe(4);
    });
});
