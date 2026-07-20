/**
 * @jest-environment node
 *
 * Regression coverage for OE-26717: in the web-menu Customize modal, removing a component must
 * re-evaluate the price label of EVERY surviving sibling in the category, not just the removed
 * one. FreeUpTo is positional (first N in selection order are free), so removing an earlier
 * component shifts every later component's position down — a component that was charged at
 * position 3 becomes free at position 2. Before the fix, only the removed component's label was
 * repainted, so the surviving component kept its stale price even though the total was already
 * correct.
 *
 * Runs under the plain Node environment (not jest-environment-jsdom) because this file constructs
 * its own JSDOM instance below — the same approach as calculatePriceBaseOnRules.test.js.
 *
 * rules.js and olo_calculate_totals.js are plain browser scripts (no module exports) that ship
 * together in olo_components.bundle.js. We run both real sources through vm in a jsdom-backed
 * context, seeding the module-level globals that olo_render.js declares in production
 * (globalSelectedComponents, updateComponetPriceUI) via a small prelude.
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
    const totalsSource = fs.readFileSync(
        path.join(__dirname, '../../assets/src/js/components/olo-theme/olo_calculate_totals.js'),
        'utf8'
    );

    // `rulesGlobal`, `globalSelectedComponents` and `updateComponetPriceUI` are declared in
    // olo_render.js in production. Provide them here, plus test-only accessors, so both real
    // sources run unmodified. updateComponetPriceUI mirrors olo_render.js's behavior: a 0/NaN
    // price clears the label (empty string), otherwise the numeric price is shown.
    const prelude = `
        let rulesGlobal = [];
        let globalSelectedComponents = {};
        let __painted = {};
        function __setGlobalSelectedComponents(value) { globalSelectedComponents = value; }
        function __getPainted() { return __painted; }
        function __resetPainted() { __painted = {}; }
        function updateComponetPriceUI({ componentid, originalPrice }) {
            __painted[componentid] = (originalPrice === 0 || isNaN(originalPrice)) ? '' : originalPrice;
        }
    `;

    vm.createContext(window);
    vm.runInContext(prelude + rulesSource + totalsSource, window);

    return { window };
}

// Builds one selected-component instance matching the shape calculatePriceOfEachComponent expects
// (same helper contract as calculatePriceBaseOnRules.test.js's buildInstance).
function buildInstance(componentid, isdefault, originalPrice, rules, category) {
    return {
        componentid,
        category,
        isdefault,
        originalPrice,
        rules,
        quantity: 1,
        servingoptions: {},
        selectedservingoptions: []
    };
}

describe('OE-26717 — FreeUpTo label refresh on component removal', () => {
    let window;
    const rule = { FreeUpTo: 2 };

    beforeEach(() => {
        ({ window } = createHarness());
    });

    describe('getComponentDisplayPrice reflects positional FreeUpTo after a splice', () => {
        test('3rd component is charged; removing an earlier one frees the survivor', () => {
            const category = {
                cat1: [
                    buildInstance('A', true, 2, rule, 'cat1'),
                    buildInstance('B', true, 2, rule, 'cat1'),
                    buildInstance('C', false, 2, rule, 'cat1')
                ]
            };
            window.__setGlobalSelectedComponents(category);

            // Position 3 (C) is outside the FreeUpTo=2 window → charged.
            expect(window.getComponentDisplayPrice('cat1', 'C')).toBe(2);

            // Remove B (an earlier component); C shifts to position 2 → now free.
            category.cat1.splice(1, 1);
            expect(window.getComponentDisplayPrice('cat1', 'C')).toBe(0);
        });

        test('removing the charged component leaves the two survivors free', () => {
            const category = {
                cat1: [
                    buildInstance('A', true, 2, rule, 'cat1'),
                    buildInstance('B', true, 2, rule, 'cat1'),
                    buildInstance('C', false, 2, rule, 'cat1')
                ]
            };
            window.__setGlobalSelectedComponents(category);

            category.cat1.pop(); // remove C (position 3)
            expect(window.getComponentDisplayPrice('cat1', 'A')).toBe(0);
            expect(window.getComponentDisplayPrice('cat1', 'B')).toBe(0);
        });
    });

    describe('refreshCategoryComponentPrices repaints every sibling', () => {
        test('surviving component label clears to free after an earlier removal', () => {
            const category = {
                cat1: [
                    buildInstance('A', true, 2, rule, 'cat1'),
                    buildInstance('B', true, 2, rule, 'cat1'),
                    buildInstance('C', false, 2, rule, 'cat1')
                ]
            };
            window.__setGlobalSelectedComponents(category);

            // Initial paint: A, B free; C priced.
            window.refreshCategoryComponentPrices('cat1');
            expect(window.__getPainted()).toEqual({ A: '', B: '', C: 2 });

            // Remove B, then repaint the whole category (what the removeitems handler now does).
            window.__resetPainted();
            category.cat1.splice(1, 1);
            window.refreshCategoryComponentPrices('cat1');

            // The bug: before the fix, C's label was never touched on removal and stayed at 2.
            // Now C is repainted from its recomputed (free) price and clears.
            expect(window.__getPainted()).toEqual({ A: '', C: '' });
        });

        test('no-rule category shows each component its raw price after removal', () => {
            const noRule = {};
            const category = {
                cat1: [
                    buildInstance('A', false, 2, noRule, 'cat1'),
                    buildInstance('B', false, 3, noRule, 'cat1')
                ]
            };
            window.__setGlobalSelectedComponents(category);

            category.cat1.pop(); // remove B
            window.refreshCategoryComponentPrices('cat1');
            expect(window.__getPainted()).toEqual({ A: 2 });
        });
    });
});
