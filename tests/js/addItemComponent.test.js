/**
 * @jest-environment node
 *
 * Regression coverage for the cart-edit "can't reselect a default component"
 * bug (openspec change: fix-cart-edit-default-component-reselect).
 *
 * Runs under the plain Node environment (not jest-environment-jsdom) because
 * this file constructs its own JSDOM instance below — nesting jsdom inside
 * Jest's jsdom environment hits a TextEncoder polyfill gap in this project's
 * jsdom/Node version pairing.
 *
 * rules.js is a plain browser script (no module exports), loaded in production
 * as a <script> tag alongside olo_render.js — which declares the module-level
 * `rulesGlobal` that addItemComponent() reads. We replicate that by running the
 * real source through vm in a jsdom-backed context, seeding a minimal
 * `rulesGlobal` the same way olo_render.js would.
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

function buildComponentElement(window, $, { active, quantity, maxUnique, ruleId, catId }) {
    const html = `
        <div id="comp-1" data-rule="${ruleId}" data-catid="${catId}" data-quantity="${quantity}"
             data-maxunique="${maxUnique}" data-price="0" data-action=""
             class="${active ? 'active' : ''} component-item-${catId}">
            <div class="item-main-container">
                <div class="qty-controls-container">
                    <div class="component-qty">${quantity}</div>
                </div>
            </div>
            <div class="position-container"></div>
        </div>
    `;
    $(window.document.body).append(html);
    return $('#comp-1');
}

function clickEventFor(el) {
    return { target: el.get(0) };
}

describe('addItemComponent — selection state resolves from the active class', () => {
    let window, $;

    beforeEach(() => {
        ({ window, $ } = createHarness());
    });

    test('selecting a non-active component with a stale nonzero data-quantity activates it as a fresh selection (MaxUnique > 1)', () => {
        window.__setRuleForTest('rule-1', { MaxUnique: 3, MaxAllowed: 5, MinRequired: 0 });

        // Mirrors the pre-fix render bug: not active, but data-quantity left at a
        // stale nonzero value instead of 0.
        const el = buildComponentElement(window, $, { active: false, quantity: 1, maxUnique: 3, ruleId: 'rule-1', catId: 'cat-1' });

        window.addItemComponent(el.get(0), false, clickEventFor(el));

        expect(el.hasClass('active')).toBe(true);
        expect(el.data('quantity')).toBe(1);
    });

    test('re-clicking an already active steppable component increments normally (MaxUnique > 1)', () => {
        window.__setRuleForTest('rule-1', { MaxUnique: 3, MaxAllowed: 5, MinRequired: 0 });

        const el = buildComponentElement(window, $, { active: true, quantity: 1, maxUnique: 3, ruleId: 'rule-1', catId: 'cat-1' });

        window.addItemComponent(el.get(0), false, clickEventFor(el));

        expect(el.hasClass('active')).toBe(true);
        expect(el.data('quantity')).toBe(2);
        expect(el.find('.component-qty').text()).toBe('2');
    });

    test('a toggle-style component (MaxUnique === 1) activates on the first click when not active, even with a stale stored quantity', () => {
        window.__setRuleForTest('rule-1', { MaxUnique: 1, MaxAllowed: 1, MinRequired: 0 });

        // Mirrors the pre-fix render bug for a default toggle component that was
        // deselected and saved in a previous edit: not active, stale quantity of 1.
        const el = buildComponentElement(window, $, { active: false, quantity: 1, maxUnique: 1, ruleId: 'rule-1', catId: 'cat-2' });

        window.addItemComponent(el.get(0), false, clickEventFor(el));

        expect(el.hasClass('active')).toBe(true);
        expect(el.data('quantity')).toBe(1);
    });
});
