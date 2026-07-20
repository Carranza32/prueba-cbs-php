<?php
namespace CBSNorthStar\Views;

use CBSNorthStar\Models\Cart;

class CartBlock {
    public function __construct() {
        add_filter('cbs_cart_content', [$this, 'renderCartContent']);
    }

    private static $instance = null;

    public static function create(): ?CartBlock
    {
      if (self::$instance === null) {
        self::$instance = new CartBlock();
      }
  
      return self::$instance;
    }
    /**
     * Load the cart and render its content.
     *
     * @return string The rendered HTML for the cart block.
     */
    public function renderCartContent() {
        // Load cart data
        $cart = (new Cart())->load();

        // Start output buffering
        ob_start();

        ?>
        <div class="slider-container" data-testid="cart-slider-container">
            <div id="cart-block-cbs" class="cart-block-container" data-testid="cart-block-wrapper">
                <?php
                // product-active-date-window / cart-item-active-date-enforcement: this
                // custom block never called WooCommerce's own notice renderer, so an
                // error raised by cbsCheckCartActiveDateWindow() (or any other
                // wc_add_notice(..., 'error') call) would otherwise never be visible
                // on the cart page — only at checkout. wc_print_notices() also clears
                // the queue once printed, matching WooCommerce's own template behavior.
                if ( function_exists( 'wc_print_notices' ) ) {
                    echo '<div class="cart-block-notices" data-testid="cart-block-notices">';
                    wc_print_notices();
                    echo '</div>';
                }
                ?>
                <div class="cart-block-header" data-testid="cart-header">
                    <div class="cart-number-container" data-testid="cart-summary">
                        <div id="cart-icon" class="cart-icon" data-testid="cart-icon"></div>
                        <div id="cart-label" class="cart-label" data-testid="cart-label">
                            My Order <span class="cart-number" data-testid="cart-count"><?php echo esc_html($cart['count']); ?></span>
                            <div id="dropdown-arrow" class="dropdown-arrow" data-testid="cart-dropdown-arrow"></div>
                        </div>
                    </div>
                    <div class="cart-order-type" data-testid="cart-order-type-wrapper">
                        <div class="cart-order-type-label" data-testid="cart-order-type-toggle-wrapper">
                            <input type="checkbox" id="toggle" class="toggleCheckbox" data-testid="cart-order-type-toggle-input" <?php if (isset($_COOKIE['OrderType']) && $_COOKIE['OrderType'] == '2') echo 'checked'; ?>>
                            <label for="toggle" class="toggleContainer" data-testid="cart-order-type-toggle-label">
                                <div data-testid="cart-order-type-dine">Dine </div>
                                <div data-testid="cart-order-type-togo">To go</div>
                            </label>
                        </div>
                    </div>
                </div>
                <div id="cart-list" class="cart-list-container" data-testid="cart-list-container">
                    <div class="cart-list" data-testid="cart-items-list">
                        <?php $this->renderCartItems($cart['items']); ?>
                    </div>
                    <?php
                    global $cartBlockOptionKey;
                    global $cacheKey;
                    $cached_html = get_transient($cacheKey);

                    if (!$cartBlockOptionKey) {
                        return '<div>No attributes available (missing option key).</div>';
                    }
                    $cartBlockAttributes = get_option($cartBlockOptionKey);

                    if (!empty($cartBlockAttributes)) {
                        if (!is_numeric($cartBlockAttributes)) {
                            error_log('Cart block: Invalid ID format in stored attributes');
                            return;
                        }
                        $shortcodeTag = "woocommerce_prl_recommendations";
                        //render only if shortcode exists
                        if (shortcode_exists($shortcodeTag)) {
                            if ($cached_html !== false) {
                                echo $cached_html;
                            }else{
                                echo do_shortcode("[".$shortcodeTag." id='".esc_attr($cartBlockAttributes)."']");
                            }
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php

        // Return the buffered content
        return ob_get_clean();
    }

    /**
     * Render the individual cart items.
     *
     * @param array $cart_items The array of cart items.
     */
    private function renderCartItems($cart_items) {
        foreach ($cart_items as $product) {
            $variations = $this->getVariationList($product['variation']);
            $variationsData = implode(' ', $product['variation']);
            $taxonomy = '';

            ?>
            <div class="cart-item" data-testid="cart-item" data-testid-product="cart-item-<?php echo esc_attr($product['product_id']); ?>">
                <div class="cart-item-description" data-testid="cart-item-description">
                    <div class="cart-item-quantity" data-testid="cart-item-quantity">X<?php echo esc_html($product['quantity']); ?></div>
                    <div class="cart-item-name" data-testid="cart-item-name-wrapper">
                        <p class="item-title" data-testid="cart-item-name"><?php echo esc_html($product['name']); ?></p>
                        <div class="item-components" data-testid="cart-item-components">
                            <?php foreach($product['product_serving_options'] as $servingOption){ ?>
                                    <?php if(count($product['product_serving_options']) > 1 &&  (reset($product['product_serving_options'])['optionId'] != $servingOption['optionId'])) { ?>
                                        <span class="serving-option-separator"> , </span>
                                    <?php } ?>
                                <?php echo $servingOption['optionName']; ?>
                            <?php } ?>
                            <?php echo $variations; ?>
                            <?php echo !empty($product['product_component']) ? $product['product_component'] : ''; ?>
                        </div>
                    </div>
                </div>
                <div class="cart-item-price" data-testid="cart-item-price-wrapper">
                    <p class="item-price" data-testid="cart-item-price">$<?php echo number_format($product['line_total'], 2); ?></p>
                    <?php $this->renderItemActions($product, $variationsData, $taxonomy); ?>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Get the variations list as an HTML string.
     *
     * @param array $variations The variations array.
     * @return string The HTML string of variations.
     */
    private function getVariationList($variations) {
        $variation_list = '';
        foreach ($variations as $key => $value) {
            $taxonomy = str_replace('attribute_', '', $key);
            $variation_list .= '<li>' . esc_html($value) . '</li>';
        }
        return $variation_list;
    }

    /**
     * Render the actions (edit/delete) for a cart item.
     *
     * @param array $product The product data.
     * @param string $variationsData The serialized variations data.
     * @param string $taxonomy The taxonomy data.
     */
    private function renderItemActions($product, $variationsData, $taxonomy) {
        $item = [
            'quantity' => $product['quantity'],
            'cartItemKey' => $product['key'],
            'product_id' => $product['product_id'],
            'selectedComponents' => $product['product_component_id'],
            'selectedServingOptions' => $product['product_serving_options'],
            'variationId' => $product['variation_id'],
            'variationsData' => $variationsData,
            'taxonomy' => $taxonomy,
        ];

        $itemData = htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8');
        ?>
        <div id="edit-icon" class="edit-icon" product-id="<?php echo esc_attr($product['product_id']); ?>" data-item="<?php echo esc_attr($itemData); ?>" data-testid="cart-item-edit-btn"></div>
        <a id="delete-icon" product-id="<?php echo esc_attr($product['key']); ?>" class="delete-icon" data-testid="cart-item-delete-btn"></a>
        <?php
    }
}