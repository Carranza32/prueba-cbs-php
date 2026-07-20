<?php

namespace CBSNorthStar\Init\Loaders;

use CBSNorthStar\Helpers\BuildNumberHelper;
use CBSNorthStar\Helpers\QuickAddBatch;
use CBSNorthStar\Logger\CBSLogger;

use function wc_get_notices;


class AddToCartLoader implements JavaScriptLoaderContract
{

  private static $instance = null;

  public static function create(): ?AddToCartLoader
  {
    if (self::$instance === null) {
      self::$instance = new AddToCartLoader();
    }

    return self::$instance;
  }
  public function registerScripts()
  {
    wp_enqueue_script(
      'kiosk-custom-rules',
      plugins_url('/assets/src/js/components/rules.js', CBS_PLUGIN_FILE),
      ['jquery', 'wc-cart-fragments', 'kiosk-custom'],
      BuildNumberHelper::getBuildNumber(),
      true
  );
      add_action('wp_ajax_add_to_cart_action_cbs', array($this, 'ajaxHandler'));
      add_action('wp_ajax_nopriv_add_to_cart_action_cbs', array($this, 'ajaxHandler'));
      add_action('wp_ajax_quantity_change', array($this, 'quantityChange'));
      add_action('wp_ajax_nopriv_quantity_change', array($this, 'quantityChange'));
  }

  public function ajaxHandler(): void
  {
    if (isset($_POST['action']) && $_POST['action'] === 'add_to_cart_action_cbs') {

      $items = QuickAddBatch::normalizeItems($_POST);
      if (empty($items)) {
          wp_send_json_error(['error' => true, 'message' => 'No items to add']);
          return;
      }

      if (QuickAddBatch::exceedsMaxBatchSize($items)) {
          wp_send_json_error([
              'error'   => true,
              'message' => 'Too many items in one request.',
          ]);
          return;
      }

      $returnAddedMessage = isset($_POST['returnAddedMessage']) ? filter_var($_POST['returnAddedMessage'], FILTER_VALIDATE_BOOLEAN) : false;

      // Backstop for what client-side request coalescing can't see (e.g. a second
      // browser tab on the same session firing a genuinely concurrent batch) — see
      // cbsAcquireCartSessionLock() for why this is a backstop and not the primary fix.
      $sessionId = WC()->session ? (string) WC()->session->get_customer_id() : '';
      if (!cbsAcquireCartSessionLock($sessionId)) {
          error_log('[ADD-TO-CART] Could not acquire session lock — concurrent request still in progress');
          wp_send_json_error([
              'error'     => true,
              'retryable' => true,
              'message'   => 'Please try again.',
          ]);
          return;
      }

      // wp_send_json_success()/wp_send_json_error() call wp_die(), which exits the
      // script on an AJAX request — a `finally` around them would never actually
      // run since exit() skips it. So the batch is processed and its outcome
      // captured in $result WITHOUT sending a response inside the lock; the lock
      // is released by a real `finally` around that computation, and only THEN
      // (lock already free for the next waiter) do we send the response and die.
      try {
          $result = $this->processBatch($items, $sessionId);
      } finally {
          cbsReleaseCartSessionLock($sessionId);
      }

      if ($result['success']) {
          wp_send_json_success($result['data']);
      } else {
          wp_send_json_error($result['data']);
      }
    }
  }

  /**
   * Add every item in the batch to the cart, validate the batch once, and
   * report the outcome — called with the session lock held (see ajaxHandler()).
   * Never calls wp_send_json_*() itself so the caller can release the lock
   * first.
   *
   * @param array<int, array<string, mixed>> $items
   * @param string $sessionId
   * @return array{success: bool, data: array<string, mixed>}
   */
  private function processBatch(array $items, string $sessionId): array
  {
      $returnAddedMessage = isset($_POST['returnAddedMessage']) ? filter_var($_POST['returnAddedMessage'], FILTER_VALIDATE_BOOLEAN) : false;

      $clearCartBefore = ( !empty($_COOKIE['olo_clear_cart']) && $_COOKIE['olo_clear_cart'] === '1' )
          || ( WC()->session && WC()->session->get('olo_clear_cart') === '1' );

      error_log( '[ADD-TO-CART] Request received | items=' . count($items)
          . ' olo_clear_cart=' . ( $clearCartBefore ? 'yes' : 'no' )
          . ' cart_before=' . WC()->cart->get_cart_contents_count()
          . ' siteid=' . ( !empty( $_COOKIE['siteid'] ) ? $_COOKIE['siteid'] : 'missing' )
      );

      // inside get_total() after the item was already added.
      if ( $clearCartBefore ) {
          error_log( '[ADD-TO-CART] olo_clear_cart was set — emptying cart before add' );
          WC()->cart->empty_cart();
          WC()->session->set('olo_clear_cart', null);
          setcookie('olo_clear_cart', '', time() - 3600, '/');
          unset($_COOKIE['olo_clear_cart']);
      }

      // Snapshot quantities BEFORE this batch's adds so a rollback can tell a line
      // this batch created from scratch (remove it entirely) apart from a line this
      // batch merely merged more quantity into (add_to_cart() returns the SAME
      // cart_item_key when this item's product+components match an existing line) —
      // the latter must only lose what this batch added, not the customer's
      // pre-existing quantity on that same line. See cbsValidateAddedItems().
      $preExistingQuantities = wp_list_pluck(WC()->cart->get_cart(), 'quantity');

      $cartItemKeys     = [];
      $addedProductIds  = [];
      $addedQuantities  = [];
      $failedProductIds = [];

      // WC_Cart's own constructor hooks calculate_totals() to the woocommerce_add_to_cart
      // action (priority 20) — so without this, every add_to_cart() call below would
      // trigger a full recalculation (and therefore its own WOAPI /checks/validate/ call
      // via cbs_custom_tax_surcharge) on its own, defeating the entire point of batching:
      // N items would still cost N live API calls, just from a different call site than
      // before. Unhooked for the loop, restored right after regardless of outcome so any
      // later single-item add in this same request behaves normally.
      remove_action('woocommerce_add_to_cart', array(WC()->cart, 'calculate_totals'), 20);

      try {
          foreach ($items as $item) {
              $productId   = $item['product_id'];
              $quantity    = $item['quantity'];
              $variationId = $item['variation_id'];

              $productStatus = wp_cache_get("product_status_{$productId}");
              if (!$productStatus) {
                  $productStatus = get_post_status($productId);
                  wp_cache_set("product_status_{$productId}", $productStatus);
              }

              $passedValidation = apply_filters('woocommerce_add_to_cart_validation', true, $productId, $quantity);

              // productComponentAddCartItem() (inc/custom-woocommerce.php) reads
              // component data via filter_input(INPUT_POST, ...), which snapshots the
              // request body once and won't see a later $_POST mutation — so this
              // per-iteration global is how each looped add_to_cart() call gets its
              // own item's components instead of every item picking up the same one.
              $GLOBALS['cbsQuickAddBatchItemOverride'] = [
                  'product_price_input' => $item['product_price_input'],
                  'selComponents'       => $item['selComponents'],
                  'selComponentsPrice'  => $item['selComponentsPrice'],
                  'selComponentsQty'    => $item['selComponentsQty'],
              ];

              $cartItemKey = ( $passedValidation && 'publish' === $productStatus )
                  ? WC()->cart->add_to_cart($productId, $quantity, $variationId)
                  : false;

              unset($GLOBALS['cbsQuickAddBatchItemOverride']);

              if ( $cartItemKey ) {
                  $cartItemKeys[]  = $cartItemKey;
                  $addedProductIds[] = $productId;
                  $addedQuantities[$productId] = ($addedQuantities[$productId] ?? 0) + $quantity;
              } else {
                  $failedProductIds[] = $productId;
                  error_log( '[ADD-TO-CART] Item failed to add | product_id=' . $productId
                      . ' passed_validation=' . ( $passedValidation ? 'yes' : 'no' )
                      . ' status=' . $productStatus
                  );
              }
          }
      } finally {
          add_action('woocommerce_add_to_cart', array(WC()->cart, 'calculate_totals'), 20, 0);
      }

      if ( empty($cartItemKeys) ) {
          return [
              'success' => false,
              'data'    => [
                  'error'              => true,
                  'failedProductIds'   => $failedProductIds,
                  'failedProductNames' => $this->resolveProductNames($failedProductIds),
                  'product_url'        => apply_filters(
                      'woocommerce_cart_redirect_after_error',
                      get_permalink($items[0]['product_id']), $items[0]['product_id']
                  ),
              ],
          ];
      }

      // Validate the whole batch in one calculate_totals() call so a WOAPI
      // /validate failure removes ONLY the items THIS batch added and leaves any
      // pre-existing items in the cart (OE-25549, generalized to a batch).
      $validation = cbsValidateAddedItems($cartItemKeys, $preExistingQuantities);
      if ( ! $validation['ok'] ) {
          $rolledBackProductIds = array_values(array_unique(array_merge($failedProductIds, $addedProductIds)));
          CBSLogger::orders()->debug( '[ADD-TO-CART] Validate failed — batch removed', [
              'product_ids' => $addedProductIds,
              'cart_after'  => WC()->cart->get_cart_contents_count(),
          ] );
          return [
              'success' => false,
              'data'    => [
                  'error'              => true,
                  'validateFail'       => true,
                  'message'            => $validation['message'],
                  'failedProductIds'   => $rolledBackProductIds,
                  'failedProductNames' => $this->resolveProductNames($rolledBackProductIds),
                  'count'              => WC()->cart->get_cart_contents_count(),
                  'total'              => WC()->cart->get_total(),
              ],
          ];
      }

      $count = WC()->cart->get_cart_contents_count();

      CBSLogger::orders()->debug( '[ADD-TO-CART] Success', [
          'cart_count'        => $count,
          'added_product_ids' => $addedProductIds,
      ] );

      foreach (array_unique($addedProductIds) as $productId) {
          do_action('woocommerce_ajax_added_to_cart', $productId);
      }
      $addedMessage = wc_add_to_cart_message($addedQuantities, true, $returnAddedMessage);
      $total = WC()->cart->get_total();

      // Persist the session now so the cbsValidateSnapshot written during
      // cbsValidateAddedItems() survives to the follow-up fragment/render
      // request (same reasoning as quantityChange()).
      WC()->cart->set_session();
      WC()->cart->maybe_set_cart_cookies();
      if ( WC()->session ) {
          WC()->session->save_data();
      }

      return [
          'success' => true,
          'data'    => [
              'message'            => 'Products added to cart',
              'total'              => $total,
              'count'              => $count,
              'addedMessage'       => $addedMessage,
              'addedProductIds'    => array_values(array_unique($addedProductIds)),
              'failedProductIds'   => $failedProductIds,
              'failedProductNames' => $this->resolveProductNames($failedProductIds),
          ],
      ];
  }

  /**
   * Resolve product IDs already known to have failed (per-item add_to_cart()
   * failure, or a whole batch rolled back after a validation failure) to their
   * display names, so the client can name them in customer-facing messaging
   * instead of only showing a generic message. Returned as a list of pairs, not
   * an ID-keyed object — an associative array with small sequential-from-zero
   * integer keys would silently re-encode as a JSON array via json_encode(),
   * which the client could not reliably distinguish from this shape.
   *
   * @param array<int, int> $productIds
   * @return array<int, array{product_id: int, name: string}>
   */
  private function resolveProductNames(array $productIds): array
  {
      $names = [];
      foreach (array_values(array_unique($productIds)) as $productId) {
          $title = get_the_title($productId);
          $names[] = [
              'product_id' => (int) $productId,
              'name'       => $title !== '' ? $title : (string) $productId,
          ];
      }
      return $names;
  }
  public function quantityChange(){
    if ( is_null( WC()->cart ) ) {
        WC()->frontend_includes();
        wc_load_cart();
    }

    if ( ! WC()->session ) {
        WC()->session = new \WC_Session_Handler();
        WC()->session->init();
    }

    // Serialize this quantity change against any other cart-mutation request for the
    // same session so one save_data() cannot blind-overwrite another request's change
    // (OE-26589). Same per-session lock the add-to-cart batch endpoint uses.
    $sessionId = (string) WC()->session->get_customer_id();
    if ( '' === $sessionId || ! cbsAcquireCartSessionLock($sessionId) ) {
        wp_send_json_error(array('error' => true, 'retryable' => true, 'message' => 'Please try again.'));
        return;
    }

    // wp_send_json_*() calls wp_die() (exit), which would skip a finally — so compute the
    // response while holding the lock, release it in finally, then emit it below.
    $response = null;

    try {
        // A request that had to wait on the lock still holds the cart hydrated at
        // wp_loaded; re-read the committed session so we mutate current state.
        cbsRehydrateCartFromSession($sessionId);

        $cartItemKey = sanitize_text_field($_POST['cart_item_key'] ?? '');
        $quantity = absint($_POST['quantity'] ?? 0);

        // Clamp to the product's max purchase quantity (available stock). The cart
        // qty control renders a max="<stock>" attribute, but neither the client
        // handlers nor WC()->cart->set_quantity() enforce it on this AJAX path, so a
        // manual entry / rapid +clicks could push past stock (e.g. 1000 vs 999).
        // Cap it here silently (OE-26589). get_max_purchase_quantity() returns -1
        // when unlimited (backorders / stock not managed), so only clamp when > 0.
        $existingItem = WC()->cart->get_cart_item( $cartItemKey );
        if ( ! empty( $existingItem ) && ! empty( $existingItem['data'] ) && is_object( $existingItem['data'] ) ) {
            $maxQty = $existingItem['data']->get_max_purchase_quantity();
            if ( $maxQty > 0 && $quantity > $maxQty ) {
                $quantity = $maxQty;
            }
        }

        // Never call set_quantity() on a missing key. WC_Cart::set_quantity() assigns
        // $cart_contents[$key]['quantity'] directly with no isset() guard, so a stale
        // key injects a phantom item (quantity only, no product data) that corrupts
        // calculate_totals() and is then persisted by save_data() — resurrecting a
        // ghost row. The post-lock re-hydrate (OE-26589) reads the committed session,
        // so a concurrent delete can legitimately leave this key gone; bail cleanly.
        $setResult = empty( $existingItem )
            ? false
            : WC()->cart->set_quantity( $cartItemKey, $quantity, false );
        if ( $setResult === false ) {
            if ( empty( $existingItem ) ) {
                $response = array('success' => false, 'data' => 'Item no longer in cart');
            } else {
                error_log("Failed to update quantity for item: $cartItemKey");
                $response = array('success' => false, 'data' => 'Failed to update quantity');
            }
        } else {
            // Recalculate here so the /checks/validate/ call happens inside this
            // request and cbsValidateSnapshot is keyed to the new cart hash. The
            // follow-up get_refreshed_fragments AJAX then hits the snapshot cache and
            // returns instantly instead of racing a second API call to completion.
            WC()->cart->calculate_totals();
            WC()->cart->set_session();
            WC()->cart->maybe_set_cart_cookies();

            // Force the WC session to persist NOW. set_session() only updates the
            // in-memory store; the actual write to the session table normally happens
            // on the `shutdown` hook AFTER wp_send_json_success has already flushed
            // the response. Without this explicit save_data() the client triggers
            // wc_fragment_refresh while the new cart is still uncommitted, so the
            // follow-up get_refreshed_fragments AJAX reads the previous quantity.
            if ( WC()->session ) {
                WC()->session->save_data();
            }

            // Render the cart-collaterals block here and ship it back inline so the
            // client can replace the DOM directly. Bypasses get_refreshed_fragments,
            // which has been observed to return empty / time out on this project.
            // is_cart() is forced truthy so WC core keeps the shipping calculator and
            // totals rows in the rebuild (same trick the fragments filter uses).
            //
            // Guard with TWO buffers, mirroring DeleteProductsLoader::ajaxHandler():
            // 1) inner render buffer for the collaterals HTML;
            // 2) outer guard buffer to catch accidental flushes from hooked callbacks
            //    (e.g. ob_end_flush()), so leaked output can't corrupt the JSON body.
            // A hooked callback can also throw; catch it so a render failure degrades
            // to empty collaterals + a valid JSON response instead of an uncaught fatal
            // (the quantity mutation is already committed above).
            $collateralsHtml = '';
            $guardLeakOutput = '';

            $bufferLevelBeforeGuard = ob_get_level();
            $guardBufferLevel = $bufferLevelBeforeGuard + 1;
            ob_start();

            $bufferLevelBeforeRender = ob_get_level();
            $renderBufferLevel = $bufferLevelBeforeRender + 1;
            add_filter( 'woocommerce_is_cart', '__return_true' );
            ob_start();
            try {
                do_action( 'woocommerce_cart_collaterals' );

                // Some hooked callbacks may open nested buffers and fail to close
                // them; unwind extras so we consume only this render scope's output.
                while ( ob_get_level() > $renderBufferLevel ) {
                    ob_end_clean();
                }

                if ( ob_get_level() === $renderBufferLevel ) {
                    $collateralsHtml = '<div class="cart-collaterals">' . ob_get_clean() . '</div>';
                }
            } catch (\Throwable $e) {
                CBSLogger::cart()->error('Failed rendering cart collaterals during quantity change AJAX', [
                    'cart_item_key' => $cartItemKey,
                    'message' => $e->getMessage(),
                ]);
            } finally {
                while ( ob_get_level() > $bufferLevelBeforeRender ) {
                    ob_end_clean();
                }
                remove_filter( 'woocommerce_is_cart', '__return_true' );

                // Discard any direct output leaked into the guard scope.
                while ( ob_get_level() > $guardBufferLevel ) {
                    ob_end_clean();
                }
                if ( ob_get_level() === $guardBufferLevel ) {
                    $guardLeakOutput = (string) ob_get_clean();
                }
            }

            if ( '' !== trim( $guardLeakOutput ) ) {
                CBSLogger::cart()->warning('Discarded leaked collaterals output during quantity change AJAX', [
                    'cart_item_key' => $cartItemKey,
                    'bytes' => strlen( $guardLeakOutput ),
                ]);
            }

            CBSLogger::cart()->info('Quantity updated successfully', ['cart_item_key' => $cartItemKey]);

            // The mobile cart template renders its own per-row "total" cell
            // (line_total, matching what get_cart_item() reflects after
            // calculate_totals() above) server-side at page load; it isn't part of
            // .cart-collaterals, so nothing else refreshes it after an AJAX
            // quantity change. Ship the recalculated value back so the client can
            // patch that cell directly instead of leaving it stale until reload.
            $itemTotalHtml = '';
            $updatedCartItem = WC()->cart->get_cart_item( $cartItemKey );
            if ( $updatedCartItem ) {
                $itemTotalHtml = '$' . number_format( (float) $updatedCartItem['line_total'], 2, '.', '' );
            }

            $noticeHtml = '';
            $errorNotices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : [];
            if ( ! empty( $errorNotices ) ) {
                $noticeItems = array_map(
                    static function ( $entry ): string {
                        $notice = is_array( $entry ) && isset( $entry['notice'] ) ? (string) $entry['notice'] : (string) $entry;
                        return '<li>' . wc_kses_notice( $notice ) . '</li>';
                    },
                    $errorNotices
                );

                $noticeHtml = '<div class="woocommerce-notices-wrapper"><ul class="woocommerce-error" role="alert">'
                    . implode( '', $noticeItems )
                    . '</ul></div>';

                wc_clear_notices();
            }

            $response = array(
                'success' => true,
                'data' => array(
                    'message'      => 'Success updating cart',
                    'cartTotalHtml' => WC()->cart->get_total(),
                    'collateralsHtml' => $collateralsHtml,
                    'itemTotalHtml' => $itemTotalHtml,
                    'noticeHtml'   => $noticeHtml,
                    // Echo the (possibly clamped) quantity so the client can correct the
                    // input if it was capped to stock. And surface whether the tax/order
                    // validation failed this pass so the client can keep the Pay button
                    // disabled until totals are valid again (OE-26589).
                    'clampedQty'    => $quantity,
                    'taxValidationFailed' => ( WC()->session ? (bool) WC()->session->get('taxValidationFailed') : false ),
                ),
            );
        }
    } finally {
        cbsReleaseCartSessionLock($sessionId);
    }

    if ($response['success']) {
        wp_send_json_success($response['data']);
    } else {
        wp_send_json_error($response['data']);
    }
  }
}
