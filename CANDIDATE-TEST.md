# Senior WordPress / WooCommerce Engineering Exercise

**Company:** CBS NorthStar
**Codebase:** Northstar Online Ordering — our production WooCommerce plugin
**Time budget:** ~4 hours (hard cap 5 — timebox honestly and note what you cut)
**Confidentiality:** This is proprietary production code provided under the NDA
you signed. Do not share, publish, or retain it after the process concludes.

---

## What this is

Instead of a toy project, you'll work inside the real plugin that powers
online ordering and kiosks for our restaurant customers, synchronizing menus
and submitting orders to the NorthStar POS via our WOAPI. It is a large,
long-lived production codebase (~10 years of history, multiple authors). Part
of what we're evaluating is how you operate in exactly that environment:
finding your way around, following existing conventions, and improving things
without breaking them.

You will **not** have access to a live WOAPI/POS backend. Every task below is
verifiable through the plugin's PHPUnit suite or through code reading — no
live API needed. If a change would normally require integration testing
against the POS, say so in your notes and test what you can at the unit level.

## Orientation map (to save you time)

| Area | Where |
|---|---|
| Plugin entry point | `northstaronlineordering.php` |
| WOAPI HTTP client | `inc/Helpers/WoapiRequest.php`, `inc/Woapi/Connection.php` |
| Order validate/submit/finalize | `inc/Woapi/OrderProcess.php` |
| Menu/product sync from ECM | `inc/SaveProduct.php`, `inc/Services/ProductManager.php` |
| Modifier ("component") pricing rules | `inc/Helpers/ComponentPricing.php`, `inc/Helpers/ComponentFreeRules.php` |
| WooCommerce hook wiring | `inc/woocommerce_hooks.php`, `inc/custom-woocommerce.php` |
| Existing unit tests | `tests/unit/` (PHPUnit 10 + Brain Monkey), `tests/js/` (Jest) |

---

## Task 0 — Baseline (~30 min)

Get the PHP unit test suite running and green:

```bash
composer install
bash bin/install-wp-tests.sh wordpress_test <db-user> <db-pass> localhost latest
composer test:unit
```

You need PHP 8.1+ and a local MySQL/MariaDB. Deliver: a note in your README
of any friction you hit and anything you had to fix or work around. (If the
suite genuinely cannot be made green on your machine, document precisely why
and continue — the remaining tasks are still gradeable.)

## Task 1 — New pricing rule: `FreeEvery` (~60 min)

Our ECM lets menu managers attach *rules* to component categories (toppings,
sides, etc.) that make some selected component instances free. The current
rules are implemented in `inc/Helpers/ComponentFreeRules.php` and covered by
`tests/unit/Helpers/ComponentFreeRulesTest.php`.

Product wants a new rule for a "buy two, get one free" style promotion:

> **`FreeEvery` (int N):** within a category, every Nth selected component
> *instance* is free, counting in selection order across the category —
> positions N, 2N, 3N, … With `FreeEvery = 3`, a customer selecting five
> topping instances pays for positions 1, 2, 4, 5 and gets position 3 free.

Requirements:

1. Implement `FreeEvery` in `ComponentFreeRules` following the existing code
   style and rule-evaluation pattern.
2. Define and document its precedence/interaction with the existing rules
   (`FreeUpTo`, `DefaultComponentsAreFree`, `FirstDefaultComponentsLevelsFree`,
   `FreeAfter`) — an instance already free under another rule must not be
   double-counted; state your chosen semantics in the docblock.
3. Extend the existing unit tests with cases covering: basic every-Nth,
   multi-quantity components, interaction with at least one existing rule,
   and `FreeEvery` values of 0/absent (must be a no-op).
4. The comment in the file notes this logic mirrors
   `src/product-detail/view.js`. **You do not need to implement the JS side**
   — but add a short note in your README describing how you would keep the
   two in sync, and what you'd test there.

## Task 2 — HPOS-compatible order meta (~45 min)

WooCommerce High-Performance Order Storage (HPOS) moves orders out of
`wp_posts`. Parts of this plugin still write order metadata with post
functions — see `inc/Woapi/OrderProcess.php` (`submitOrder()`,
`sendPaymentOnly()`, `finalizeOrder()`) and the order-completion fallback in
`inc/woocommerce_hooks.php` (search `cbs_orderid`).

1. Introduce a small, single-purpose class that owns writing/reading the CBS
   order meta keys (`cbs_orderid`, `cbs_siteid`, `cbs_checknumber`,
   `cbs_orderFinalized`) through the `WC_Order` CRUD API, and refactor the
   call sites above to use it. Behavior must be otherwise unchanged.
2. Declare HPOS compatibility for the plugin the way WooCommerce expects.
3. Add unit tests for the new class (the existing suite shows the
   Brain Monkey patterns used to stub WP/WC functions).
4. In your README: one paragraph on what *else* in the plugin would need
   auditing before HPOS could be enabled on a production store, based on what
   you saw while working. (You don't need to fix anything beyond the files
   above.)

## Task 3 — Transient-failure resilience on order submit (~60 min)

Restaurant networks are unreliable. Today, `OrderProcess::submitOrder()`
makes a single `postData()` call to
`/checks/{checkId}/submit`; if the WOAPI is briefly unreachable, the
submission fails, an email is sent, and the customer's order is stranded.

Implement bounded retry for the **submit step only**:

1. Retry only failures you can justify as transient (WP transport errors,
   selected HTTP statuses). Non-transient WOAPI business errors (validation
   failures, etc.) must not be retried. Be explicit in code about the
   classification.
2. Bounded attempts with backoff. Given this runs during a customer-facing
   checkout request, justify your attempt count and delays — or propose and
   implement a deferred mechanism if you believe inline retry is wrong.
   Either answer can be correct; the reasoning is what we grade.
3. **Double-submission is worse than failure** — a duplicated ticket goes to
   a kitchen. Analyze whether a retry after an ambiguous outcome (e.g.,
   timeout after the request may have reached the POS) can create a duplicate
   check, and make your implementation as safe as the current API contract
   allows. Note: every WOAPI request already carries a per-session
   `TransactionReference` header (`inc/Helpers/SessionReference.php`). State
   your assumptions about server-side behavior explicitly; where the contract
   is unknown, design defensively and say what you'd need to confirm with
   the API team.
4. Unit-test the retry logic by stubbing the transport (see how existing
   tests use Brain Monkey; `wp_remote_request` is the seam).

## Task 4 — Written: production-readiness review (~30 min)

Read the following, in whatever order you like:

- `northstaronlineordering.php` (top ~60 lines)
- `inc/Woapi/Connection.php`
- `inc/Helpers/WoapiRequest.php`
- `inc/Set_sessions_for_site.php`
- the `cbs_orderid` fallback block in `inc/woocommerce_hooks.php`

In your README, list the **five most significant security or robustness
issues** you find in those files, ranked by risk. For each: what it is, a
realistic exploitation or failure scenario in a restaurant/kiosk context, and
a one-paragraph fix. Depth beats breadth — five well-argued issues beat
fifteen one-liners. (For calibration: this is production code; the issues
are real, not planted.)

---

## Deliverables

1. A Git repo (or patch series) starting from the provided zip as the initial
   commit, with a meaningful commit history — we read it.
2. `CANDIDATE-README.md` at the repo root containing: setup notes (Task 0),
   your JS-sync note (Task 1), HPOS audit paragraph (Task 2), retry design
   rationale and assumptions (Task 3), and the review (Task 4).
3. All new/modified tests passing via `composer test:unit`.

## Ground rules

- Follow the conventions already in the codebase (namespacing, test style,
  logging via `CBSLogger`) even where you'd personally choose differently —
  note disagreements in the README instead.
- Do not reformat or refactor files beyond what your tasks require; keep
  diffs reviewable.
- AI assistants: You are welcome to use them. Regardless of policy, you will
  walk through your diff line-by-line in a follow-up interview and are
  expected to defend every change.
- Questions welcome at anthonyp@cbsnorthstar.com and wendy.hernandez@cbsnorthstar.com — asking good questions is a positive
  signal, not a negative one.
