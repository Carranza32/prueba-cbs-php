## Task 1 — `FreeEvery` Pricing Rule Implementation

Implemented the `FreeEvery` promotional rule within `ComponentFreeRules::isInstanceFree()` to support modular "nth item free" pricing (e.g., `FreeEvery = 3` makes every 3rd selected component instance free across the category).

* **Precedence & Interaction Semantics:** Follows a strict short-circuit evaluation order (`FreeUpTo` -> `DefaultComponentsAreFree` -> `FirstDefaultComponentsLevelsFree` -> `FreeAfter` -> `FreeEvery`). If an instance qualifies as free under an earlier threshold, evaluation halts immediately, preventing double-counting or stacked discounts.
* **Defensive Math:** Added strict boundary checks (`(int) $rule['FreeEvery'] > 0`) before modulo calculation (`$position % FreeEvery === 0`). Zero, negative, or malformed values act as a safe no-op without throwing `DivisionByZeroError` exceptions under PHP 8.x.
* **Unit Test Coverage:** Extended `ComponentFreeRulesTest` with cases covering exact multiples, non-multiple paid positions, zero/negative resilience, multi-quantity orders via `computeFreeInstanceKeys()`, and combined rule interaction (`FreeUpTo` + `FreeEvery`).
* **Frontend (`view.js`) Synchronization Strategy:** To maintain parity without runtime coupling, both codebases should validate against a shared JSON fixture of test vectors (input payloads vs. expected free keys). On the JS side (`src/product-detail/view.js`), frontend tests should assert:
  1. UI reactivity (cart totals updating dynamically when crossing the $N$th threshold).
  2. Visual state (rendering "Free" badges on the correct DOM elements).
  3. Array mutation handling (re-evaluating modulo positions correctly when an earlier item is removed from the cart).