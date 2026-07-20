/**
 * @jest-environment node
 *
 * Regression coverage for the sticky footer quantity/price fix: clicking the
 * theme's footer quantity buttons must leave the footer showing the FULL cart
 * charge — (base + components + serving options) × qty — not the theme's
 * base-only unitPrice × qty write. The plugin listens with DELEGATED handlers
 * (document level), which the DOM guarantees run after the theme's
 * direct-bound button handler for the same click, so the plugin always gets
 * the final write.
 *
 * Runs under the plain Node environment and constructs its own JSDOM instance
 * (same pattern and rationale as tests/js/getComponentPriceByLayer.test.js).
 *
 * olo_calculate_totals.js calls out to globals owned by sibling bundle files
 * (calculateTotal / mergeComponents / getComponentDisplayPrice from rules.js,
 * globalSelectedComponents from olo_render.js) and to the wp_localize_script
 * global olo_vars_object. Those are stubbed on the window: component pricing
 * math has its own suites; this one pins the footer display contract.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { JSDOM } = require('jsdom');

const PAGE_WITH_FOOTER = `<!doctype html><html><body>
    <div class="sticky-footer-product-bar">
        <button class="js-qty-minus"></button>
        <div class="quantity-number">1</div>
        <button class="js-qty-plus"></button>
        <div class="price" data-unit-price="2" data-currency="$">$2.00</div>
    </div>
    <input id="product_base_input" value="2">
    <input class="qty" type="number" value="1" min="1">
    <div class="productcomponentprice price-section" style="display:none">
        <input id="selComponentsPrice"><span id="customprice"></span>
        <div id="servingoptionprice-row" style="display:none"><span id="servingoptionprice"></span></div>
    </div>
    <span id="pro_price"></span>
    <input id="product_price_input">
    <span id="custom_subtotal"></span>
    <div id="servingoptions_section"></div>
</body></html>`;

const PAGE_WITHOUT_FOOTER = `<!doctype html><html><body>
    <input class="qty" type="number" value="1" min="1">
</body></html>`;

function createHarness(html, { componentsTotal = 0 } = {}) {
    const dom = new JSDOM(html, { url: 'https://example.test/' });
    const { window } = dom;
    const $ = require('jquery')(window);
    window.jQuery = $;
    window.$ = $;

    // Globals owned by sibling bundle files / wp_localize_script.
    window.globalSelectedComponents = {};
    window.calculateTotal = () => window.__componentsTotal;
    window.mergeComponents = (components) => components;
    window.getComponentDisplayPrice = () => 0;
    window.olo_vars_object = {
        symbol_product: '$',
        stickyFooterPriceSelector: '.sticky-footer-product-bar .price',
    };
    window.__componentsTotal = componentsTotal;

    const source = fs.readFileSync(
        path.join(__dirname, '../../assets/src/js/components/olo-theme/olo_calculate_totals.js'),
        'utf8'
    );

    vm.createContext(window);
    vm.runInContext(source, window);

    return { window, $ };
}

/**
 * Replicate the OLO theme's inline footer script (staticFooterQuantity() in
 * theme-cbs-olo-myrestaurant/inc/products.php): direct-bound button handlers
 * that sync input.qty + .quantity-number and write the BASE-ONLY price. This
 * is the competing writer the plugin's delegated handlers must overwrite.
 */
function bindThemeFooterHandlers(window, onPriceWrite) {
    const document = window.document;
    const priceEl  = document.querySelector('.sticky-footer-product-bar .price');
    const qtyDisp  = document.querySelector('.sticky-footer-product-bar .quantity-number');
    const qtyInput = document.querySelector('input.qty');
    const minusBtn = document.querySelector('.sticky-footer-product-bar .js-qty-minus');
    const plusBtn  = document.querySelector('.sticky-footer-product-bar .js-qty-plus');

    const currency  = priceEl.dataset.currency || '$';
    const unitPrice = parseFloat(priceEl.dataset.unitPrice) || 0;

    function updatePrice(qty) {
        const value = currency + (unitPrice * qty).toFixed(2);
        priceEl.textContent = value;
        if (onPriceWrite) {
            onPriceWrite('theme', value);
        }
    }

    minusBtn.addEventListener('click', function () {
        const min = parseInt(qtyInput.min, 10) || 1;
        let val = parseInt(qtyInput.value, 10) || 1;
        if (val > min) {
            val--;
            qtyInput.value = val;
            qtyDisp.textContent = val;
            updatePrice(val);
        }
    });

    plusBtn.addEventListener('click', function () {
        let val = parseInt(qtyInput.value, 10) || 1;
        val++;
        qtyInput.value = val;
        qtyDisp.textContent = val;
        updatePrice(val);
    });
}

function click(window, selector) {
    window.document
        .querySelector(selector)
        .dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
}

function footerPrice(window) {
    return window.document.querySelector('.sticky-footer-product-bar .price').textContent;
}

describe('sticky footer price after quantity button clicks', () => {
    test('base $2 + component $1: plus -> $6.00, plus -> $9.00, minus -> $6.00', () => {
        const { window } = createHarness(PAGE_WITH_FOOTER, { componentsTotal: 1 });
        bindThemeFooterHandlers(window);

        click(window, '.js-qty-plus');
        expect(footerPrice(window)).toBe('$6.00'); // (2 + 1) × 2

        click(window, '.js-qty-plus');
        expect(footerPrice(window)).toBe('$9.00'); // (2 + 1) × 3

        click(window, '.js-qty-minus');
        expect(footerPrice(window)).toBe('$6.00'); // (2 + 1) × 2
    });

    test('theme handler writes base-only price first; delegated plugin handler overwrites it', () => {
        const { window } = createHarness(PAGE_WITH_FOOTER, { componentsTotal: 1 });

        // Record every footer price write in dispatch order: the theme
        // simulation reports its own writes, and the plugin's write is
        // captured by wrapping jQuery.fn.text (calculateTotalProduct's only
        // write path to the footer). MutationObserver can't be used here —
        // its callbacks are delivered asynchronously, after these synchronous
        // assertions run.
        const writes = [];
        const originalText = window.jQuery.fn.text;
        window.jQuery.fn.text = function (...args) {
            if (args.length > 0 && this.is('.sticky-footer-product-bar .price')) {
                writes.push(['plugin', args[0]]);
            }
            return originalText.apply(this, args);
        };

        bindThemeFooterHandlers(window, (source, value) => writes.push([source, value]));
        click(window, '.js-qty-plus');
        window.jQuery.fn.text = originalText;

        expect(writes).toEqual([
            ['theme', '$4.00'],   // theme fires first: 2 × 2, components dropped
            ['plugin', '$6.00'],  // delegated plugin handler wins the final write
        ]);
        expect(footerPrice(window)).toBe('$6.00');
    });

    test('serving options are part of the multiplied total', () => {
        const { window, $ } = createHarness(PAGE_WITH_FOOTER, { componentsTotal: 1 });
        $('#servingoptions_section').append(
            '<input type="checkbox" data-price="0.75" data-optionname="opt" data-optionid="o1" data-categoryid="c1" data-isdefault="0" data-siteid="s1" checked>'
        );
        bindThemeFooterHandlers(window);

        click(window, '.js-qty-plus');
        expect(footerPrice(window)).toBe('$7.50'); // (2 + 1 + 0.75) × 2
    });

    test('no components selected: plus -> base × qty, price section stays hidden', () => {
        const { window } = createHarness(PAGE_WITH_FOOTER, { componentsTotal: 0 });
        bindThemeFooterHandlers(window);

        click(window, '.js-qty-plus');
        expect(footerPrice(window)).toBe('$4.00'); // 2 × 2
        expect(
            window.document.querySelector('.productcomponentprice').style.display
        ).toBe('none');
    });
});

describe('manual input.qty edits', () => {
    test('change on input.qty syncs the footer counter and recalculates the full total', () => {
        const { window } = createHarness(PAGE_WITH_FOOTER, { componentsTotal: 1 });

        const qtyInput = window.document.querySelector('input.qty');
        qtyInput.value = 3;
        qtyInput.dispatchEvent(new window.Event('change', { bubbles: true }));

        expect(
            window.document.querySelector('div.quantity-number').textContent
        ).toBe('3');
        expect(footerPrice(window)).toBe('$9.00'); // (2 + 1) × 3
    });
});

describe('pages without the sticky footer bar', () => {
    test('input.qty change is inert: no recalculation, no errors logged', () => {
        const { window } = createHarness(PAGE_WITHOUT_FOOTER, { componentsTotal: 1 });
        const errorSpy = jest.spyOn(window.console, 'error').mockImplementation(() => {});

        const qtyInput = window.document.querySelector('input.qty');
        qtyInput.value = 5;
        qtyInput.dispatchEvent(new window.Event('change', { bubbles: true }));

        expect(errorSpy).not.toHaveBeenCalled(); // guard returns before getProductQuantity() can log
        errorSpy.mockRestore();
    });
});
