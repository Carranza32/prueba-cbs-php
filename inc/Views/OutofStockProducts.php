<?php
namespace CBSNorthStar\Views;

    class OutofStockProducts {
        
        public function __construct() {
            add_filter('woocommerce_get_availability', [$this, 'custom_availability_message'], 10, 2);
        }

        public function custom_availability_message($availability, $product) {
            if (!$product->is_in_stock()) {
                $availability['availability'] = $this->generate_message();
            }
            return $availability;
        }

        private function generate_message() {
            ob_start();
            ?>
                <div class='button alt out-of-stock'> <i class='fa-solid fa-retweet'></i> Pick a replacement
                    <button class="select-replacement" >Select</button>
                </div>
                <div class='out-stock-modal-container'>
                    <div class='modal'>
                        <div class="header">
                        <i class="fa-solid fa-x"></i>
                        </div>
                        <?php echo do_shortcode(get_option('outstock_product',"no selected"));  ?>
                    </div>
                </div>
            <?php
            return ob_get_clean();
        }
    }