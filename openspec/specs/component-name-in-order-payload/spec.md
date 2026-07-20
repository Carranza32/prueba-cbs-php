## Overview

The ECM order submission payload built by `OrderDto` includes, for each `orderItem`, a `components[]` array. Each entry now carries the component's display name (`componentName`) alongside `componentId`, sourced from the same `_components` product meta (`$componentsData`) already used for component price and serving-option lookups.

## Behavior

### Name resolution

`OrderDto::getComponents()` sets `componentName` inside the existing `if ($componentsData) { ... }` block, alongside `servingOptions`:

```php
$component["servingOptions"] = $this->getComponentServingOptions($componentsData, $componentId, $servingOptions);
$component["componentName"] = $this->getComponentName($componentsData, $componentId);
```

`getComponentName()` mirrors `getComponentPrice()`'s lookup shape: `$componentsData` is `categoryId => [ { componentId, componentName, ... }, ... ]`; the first item whose `componentId` matches wins, returning `$item['componentName'] ?? null`.

### Presence rules

- `$componentsData` empty (product has no components configured) → the `componentName` key is omitted entirely, same as `servingOptions`/`price`.
- `$componentsData` present but no item matches `componentId` → `componentName` is `null` (key still present), consistent with `getComponentPrice()`'s not-found behavior.
- Resolution is independent per expanded instance in the quantity loop (`while ($flag <= $quantity)`) — duplicate `componentId`s (e.g. quantity > 1) each resolve their own `componentName` via the same lookup.
- Left/right addon components use the `_left`/`_right`-suffix-stripped `componentId` for the lookup, same as price/serving-options.
- A free / rule-based-free component instance (price omitted per the OE-26645 free-pricing fix) still includes `componentName` — the free-instance check only gates `price`.

## Requirements

1. `getComponents()` MUST include `componentName` for every component entry when `$componentsData` is non-empty.
2. `componentName` lookup MUST match by `componentId` against `$componentsData`, mirroring `getComponentPrice()`/`getComponentServingOptions()`.
3. `componentName` MUST be omitted (not merely `null`) when `$componentsData` is empty.
4. `componentName` MUST be `null` (key present) when `$componentsData` is non-empty but no item matches.
5. `componentName` resolution MUST NOT be gated by `ComponentFreeRules`/free-instance checks.
6. Existing keys (`componentId`, `placementLocation`, `servingOptions`, `price`) and their values/behavior MUST be unchanged.

## Out of Scope

- Menu item (product) name fields — this covers component-level names only.
- i18n/formatting of the name beyond what is stored in `_components` meta (`componentName`, set by `SaveProduct::getcomponentDetail()`).
- Changes to serving option or price resolution logic.
