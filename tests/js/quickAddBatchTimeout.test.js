/**
 * @jest-environment node
 *
 * Regression coverage for the quick-add coalescing queue's in-flight timeout
 * (cbsSendQuickAddBatch in assets/src/js/components/rules.js). A request that
 * outlives CBS_QUICK_ADD_INFLIGHT_TIMEOUT_MS must be treated as failed exactly
 * once — reset the button, show one error toast, unblock the queue — and if
 * the real network response arrives later anyway, that late callback must be
 * ignored rather than reconciling a second time or racing a new batch.
 *
 * Runs under the plain Node environment and constructs its own JSDOM instance
 * (same pattern as tests/js/stickyFooterQtyPrice.test.js), with jQuery.ajax
 * replaced by a mock so the test controls exactly when (and whether) each
 * request "resolves".
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { JSDOM } = require('jsdom');

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function createHarness() {
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/' });
    const { window } = dom;
    const $ = require('jquery')(window);
    window.jQuery = $;
    window.$ = $;
    window.olo_vars_object = { ajax_url: 'https://example.test/wp-admin/admin-ajax.php' };

    const source = fs.readFileSync(
        path.join(__dirname, '../../assets/src/js/components/rules.js'),
        'utf8'
    );

    vm.createContext(window);
    vm.runInContext(source, window);

    // The constant is read fresh on every cbsSendQuickAddBatch() call (not
    // cached at load time), so shrinking it after load — but before the first
    // click — lets the test outlive it in milliseconds instead of 15s.
    window.CBS_QUICK_ADD_INFLIGHT_TIMEOUT_MS = 20;

    return { window, $ };
}

function mockAjax($) {
    const calls = [];
    $.ajax = jest.fn(function (options) {
        const xhr = { abort: jest.fn() };
        calls.push({ options, xhr });
        return xhr;
    });
    return calls;
}

function errorToastCount(window) {
    return window.document.querySelectorAll('.cbs-add-error-message').length;
}

describe('quick-add batch in-flight timeout', () => {
    test('a late response after timeout does not double-reconcile or overlap a new batch', async () => {
        const { window, $ } = createHarness();
        const calls = mockAjax($);
        // Mirrors the click handler (rules.js), which sets "Adding..." itself
        // before calling cbsQueueQuickAdd() — invoked directly here to isolate
        // the coalescing/timeout logic from the DOM click-delegation wiring.
        const $button = $('<button class="quick-add">Adding...</button>');

        window.cbsQueueQuickAdd({
            productId: 1,
            quantity: 1,
            selComponents: '[]',
            selComponentsQty: '[]',
            selComponentsPrice: 0,
            $button,
        });

        expect(calls).toHaveLength(1);
        expect($button.text()).toBe('Adding...');

        // Outlive the (shrunk) in-flight timeout without the ajax call ever
        // resolving — simulates a slow/hung request.
        await sleep(60);

        expect(calls[0].xhr.abort).toHaveBeenCalledTimes(1);
        expect($button.text()).toBe('Add'); // reset — not left on "Adding..." forever
        expect(errorToastCount(window)).toBe(1);
        expect(calls).toHaveLength(1); // queue unblocked; nothing pending to flush

        // The real response finally lands anyway, well after we gave up on it.
        calls[0].options.success({
            success: true,
            data: { total: '$99.00', count: 5, addedProductIds: [1], failedProductIds: [] },
        });

        expect($button.text()).toBe('Add'); // NOT flipped to "Added" by the late response
        expect(errorToastCount(window)).toBe(1); // no second toast
        expect(calls).toHaveLength(1); // late callback did not start an overlapping second batch
    });

    test('a normal response before the timeout still reconciles and settles exactly once', async () => {
        const { window, $ } = createHarness();
        const calls = mockAjax($);
        const $button = $('<button class="quick-add">Add</button>');

        window.cbsQueueQuickAdd({
            productId: 1,
            quantity: 1,
            selComponents: '[]',
            selComponentsQty: '[]',
            selComponentsPrice: 0,
            $button,
        });

        calls[0].options.success({
            success: true,
            data: { total: '$6.00', count: 1, addedMessage: '', addedProductIds: [1], failedProductIds: [] },
        });

        expect($button.text()).toBe('Added');
        expect(calls[0].xhr.abort).not.toHaveBeenCalled();

        // The timeout constant is tiny; clearTimeout() on a normal resolve
        // must have cancelled it — outliving it must not trigger a second,
        // spurious "gave up" reconciliation.
        await sleep(60);
        expect(errorToastCount(window)).toBe(0);
        expect(calls).toHaveLength(1);
    });
});
