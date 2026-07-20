/**
 * @jest-environment node
 *
 * Regression coverage for the components pricing-rules bug fix (OE-26648):
 * `DefaultComponentsAreFree` now makes a default component free at ANY
 * quantity/position (previously only the first instance), and is checked
 * BEFORE `FreeUpTo`. `FreeUpTo` remains purely positional — it frees the
 * first N components in the category regardless of whether they are default.
 *
 * Runs under the plain Node environment (not jest-environment-jsdom) because
 * this file constructs its own JSDOM instance below — nesting jsdom inside
 * Jest's jsdom environment hits a TextEncoder polyfill gap in this project's
 * jsdom/Node version pairing.
 *
 * rules.js is a plain browser script (no module exports), loaded in production
 * as a <script> tag alongside olo_render.js — which declares the module-level
 * `rulesGlobal` that other functions in rules.js read. We replicate that by
 * running the real source through vm in a jsdom-backed context, seeding a
 * minimal `rulesGlobal` the same way olo_render.js would.
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

    const rulesSource = fs.readFileSync(
        path.join(__dirname, '../../assets/src/js/components/rules.js'),
        'utf8'
    );

    // `rulesGlobal` is declared in olo_render.js in production; expose a test-only
    // setter that closes over the same variable so tests can seed it directly.
    const harnessSource = `
        let rulesGlobal = [];
        function __setRuleForTest(id, rule) { rulesGlobal[id] = rule; }
        ${rulesSource}
    `;

    vm.createContext(window);
    vm.runInContext(harnessSource, window);

    return { window, $ };
}

// Builds one selected-component instance for calculatePriceOfEachComponent.
// `servingoptions` must be `{}` (not undefined) — calculateServingOptionsPrice
// calls Object.values(component.servingoptions) unconditionally.
function buildInstance(componentid, isdefault, originalPrice, rules) {
    return {
        componentid,
        isdefault,
        originalPrice,
        rules,
        quantity: 1,
        servingoptions: {},
        selectedservingoptions: []
    };
}

describe('calculatePriceBaseOnRules (OE-26648)', () => {
    let window;

    beforeEach(() => {
        ({ window } = createHarness());
    });

    describe('DefaultComponentsAreFree', () => {
        test('frees a default component at any quantity/position', () => {
            const rule = { DefaultComponentsAreFree: true };

            expect(window.calculatePriceBaseOnRules({ quantity: 1, price: 3, rule, isDefault: true })).toBe(0);
            expect(window.calculatePriceBaseOnRules({ quantity: 2, price: 3, rule, isDefault: true })).toBe(0);
            expect(window.calculatePriceBaseOnRules({ quantity: 5, price: 3, rule, isDefault: true })).toBe(0);
        });

        test('does not affect a non-default component when no other rules apply', () => {
            const rule = { DefaultComponentsAreFree: true };

            expect(window.calculatePriceBaseOnRules({ quantity: 1, price: 3, rule, isDefault: false })).toBe(3);
        });

        test('a numeric truthy value (not just boolean true) also frees the default component', () => {
            const rule = { DefaultComponentsAreFree: 1 };

            expect(window.calculatePriceBaseOnRules({ quantity: 4, price: 3, rule, isDefault: true })).toBe(0);
        });
    });

    describe('FreeUpTo', () => {
        test('is positional — frees the first N components regardless of quantity threshold placement', () => {
            const rule = { FreeUpTo: 2 };

            expect(window.calculatePriceBaseOnRules({ quantity: 1, price: 3, rule })).toBe(0);
            expect(window.calculatePriceBaseOnRules({ quantity: 2, price: 3, rule })).toBe(0);
            expect(window.calculatePriceBaseOnRules({ quantity: 3, price: 3, rule })).toBe(3);
        });

        test('applies regardless of isDefault', () => {
            const rule = { FreeUpTo: 2 };

            expect(window.calculatePriceBaseOnRules({ quantity: 2, price: 3, rule, isDefault: true })).toBe(0);
            expect(window.calculatePriceBaseOnRules({ quantity: 2, price: 3, rule, isDefault: false })).toBe(0);
        });
    });

    describe('FirstDefaultComponentsLevelsFree', () => {
        test('frees only defaults, up to the configured ordinal count among defaults', () => {
            const rule = { FirstDefaultComponentsLevelsFree: 2 };

            expect(window.calculatePriceBaseOnRules({ quantity: 1, price: 3, rule, isDefault: true, defaultOrderCount: 1 })).toBe(0);
            expect(window.calculatePriceBaseOnRules({ quantity: 1, price: 3, rule, isDefault: true, defaultOrderCount: 2 })).toBe(0);
            expect(window.calculatePriceBaseOnRules({ quantity: 1, price: 3, rule, isDefault: true, defaultOrderCount: 3 })).toBe(3);
        });

        test('does not apply to non-default components', () => {
            const rule = { FirstDefaultComponentsLevelsFree: 2 };

            expect(window.calculatePriceBaseOnRules({ quantity: 1, price: 3, rule, isDefault: false, defaultOrderCount: 1 })).toBe(3);
        });
    });

    describe('FreeAfter', () => {
        test('frees components positioned after the configured quantity', () => {
            const rule = { FreeAfter: 2 };

            expect(window.calculatePriceBaseOnRules({ quantity: 3, price: 3, rule })).toBe(0);
        });

        test('charges components at or before the configured quantity', () => {
            const rule = { FreeAfter: 2 };

            expect(window.calculatePriceBaseOnRules({ quantity: 1, price: 3, rule })).toBe(3);
        });
    });

    describe('no applicable rules', () => {
        test('returns the original price when rule is an empty object', () => {
            expect(window.calculatePriceBaseOnRules({ quantity: 1, price: 3, rule: {} })).toBe(3);
        });

        test('returns the original price when rule is undefined', () => {
            expect(window.calculatePriceBaseOnRules({ quantity: 1, price: 3, rule: undefined })).toBe(3);
        });
    });
});

describe('calculatePriceOfEachComponent (OE-26648 scenarios)', () => {
    let window;

    beforeEach(() => {
        ({ window } = createHarness());
    });

    test('[D,D,X] with FreeUpTo=2 + DefaultComponentsAreFree: only X falls outside the free window', () => {
        const rule = { FreeUpTo: 2, DefaultComponentsAreFree: true };
        const category = {
            cat1: [
                buildInstance('D', true, 1, rule),
                buildInstance('D', true, 1, rule),
                buildInstance('X', false, 2, rule)
            ]
        };

        const result = window.calculatePriceOfEachComponent(category);

        expect(result.cat1.map(c => c.newPrice)).toEqual([0, 0, 2]);
    });

    test('[X,X,D,D] with FreeUpTo=2 + DefaultComponentsAreFree: X free positionally, D free as defaults', () => {
        const rule = { FreeUpTo: 2, DefaultComponentsAreFree: true };
        const category = {
            cat1: [
                buildInstance('X', false, 2, rule),
                buildInstance('X', false, 2, rule),
                buildInstance('D', true, 1, rule),
                buildInstance('D', true, 1, rule)
            ]
        };

        const result = window.calculatePriceOfEachComponent(category);

        expect(result.cat1.map(c => c.newPrice)).toEqual([0, 0, 0, 0]);
    });

    test('[D,D,D,X] with FreeUpTo=2 + DefaultComponentsAreFree: regression — the 3rd default is also free', () => {
        const rule = { FreeUpTo: 2, DefaultComponentsAreFree: true };
        const category = {
            cat1: [
                buildInstance('D', true, 1, rule),
                buildInstance('D', true, 1, rule),
                buildInstance('D', true, 1, rule),
                buildInstance('X', false, 2, rule)
            ]
        };

        const result = window.calculatePriceOfEachComponent(category);

        expect(result.cat1.map(c => c.newPrice)).toEqual([0, 0, 0, 2]);
    });

    test('[X,X,X] with only FreeUpTo=2: purely positional, 3rd instance is charged', () => {
        const rule = { FreeUpTo: 2 };
        const category = {
            cat1: [
                buildInstance('X', false, 2, rule),
                buildInstance('X', false, 2, rule),
                buildInstance('X', false, 2, rule)
            ]
        };

        const result = window.calculatePriceOfEachComponent(category);

        expect(result.cat1.map(c => c.newPrice)).toEqual([0, 0, 2]);
    });
});
