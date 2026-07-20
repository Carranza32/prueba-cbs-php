<?php

namespace CBSNorthStar\Init\Loaders;



class CouponsLoader
{
    private static $instance = null;

    public static function create(): ?CouponsLoader
    {
        if (self::$instance === null) {
            self::$instance = new CouponsLoader();
        }

        return self::$instance;
    }
    
    public function registerScripts()
    {
        add_action('wp', [$this, 'boot']);
        add_action('woocommerce_checkout_update_order_review', [$this, 'updateCheckout']);
        add_filter('woocommerce_update_order_review_fragments', [$this, 'updateFragment']);
    }

    public function boot() {
        if (!function_exists('carbon_get_theme_option')) {
            return;
        }

        $enabled = (bool) carbon_get_theme_option('olo_enable_coupons');
        if (!$enabled) {
            return;
        }

        $hook = $this->getHookFromSettings();
        add_action($hook, [$this, 'renderField'], 20);
    }
    private function getHookFromSettings(): string {
        $position = (string) carbon_get_theme_option('olo_coupons_position'); // '0','1','2','3'

        switch ($position) {
            case '0':

                return 'woocommerce_after_checkout_shipping_form';

            case '1':

                return 'woocommerce_before_order_notes';

            case '2':

                return 'woocommerce_after_order_notes';

            case '3':

                return 'woocommerce_checkout_before_customer_details';

            case '4':

                return 'woocommerce_review_order_before_payment';

            default:
                return 'woocommerce_after_checkout_shipping_form';
 
            }
        }

    public function renderField(): void
    {
        $siteId = $_COOKIE['siteid'] ?? '';
        $areaId = $_COOKIE['areaId'] ?? '';
        
        $coupons = !empty($_COOKIE['olo_coupon_codes']) ? explode(',', sanitize_text_field(wp_unslash($_COOKIE['olo_coupon_codes']))) : [];
        if (!$siteId || !$areaId) {
            echo '<p class="olo-coupons-missing">' . esc_html__('Coupons unavailable (missing site/area).', 'olo') . '</p>';
            return;
        }

        echo '<div class="olo-coupons-field"><h3>' . esc_html__('Coupons', 'olo') . '</h3>';
        echo '<div id="olo-applied-coupons-list">';

        if (!empty($coupons)) {
            echo '<ul class="olo-applied-coupons">';
            foreach ($coupons as $code) {
                echo '<li>' . esc_html($code) . '<button type="button"
                class="olo-remove-coupon"
                data-coupon="' . esc_attr($code) . '"> X </button></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        

        echo '<div class="olo-coupon-row">';
        woocommerce_form_field('olo_coupon_code', [
            'type'     => 'text',
            'label'    => __('Coupon code', 'olo'),
            'required' => false,
        ], WC()->checkout() ? WC()->checkout()->get_value('olo_coupon_code') : '');

        echo '<button type="button" class="button" id="olo-apply-coupon">'
            . esc_html__('Apply coupon', 'olo')
            . '</button>';
        echo '</div>';


        echo '</div>';
    }

    public function updateCheckout($post_data) {
        parse_str($post_data, $parsed);

        $newCode = isset($parsed['olo_coupon_code'])
            ? sanitize_text_field(wp_unslash($parsed['olo_coupon_code']))
            : '';

        $deleteCode = isset($parsed['olo_coupon_delete'])
            ? sanitize_text_field(wp_unslash($parsed['olo_coupon_delete']))
            : '';

        if ($newCode === '' && $deleteCode === '') {
            return;
        }

        $raw = !empty($_COOKIE['olo_coupon_codes']) ? sanitize_text_field(wp_unslash($_COOKIE['olo_coupon_codes'])) : '';
        $codes = $raw !== '' ? array_map('trim', explode(',', $raw)) : [];
        $codes = array_values(array_filter($codes));

        $codes = array_values(array_unique(array_map(function ($c) {
            return strtoupper(trim((string)$c));
        }, $codes)));


        if ($newCode !== '') {
            $newCode = strtoupper(trim($newCode));
            if ($newCode !== '') {
                if (!in_array($newCode, $codes, true)) {
                    $codes[] = $newCode;
                } else {
                    wc_add_notice(__('Coupon cannot be applied. This coupon has already been added.', 'olo'), 'error');
                }
            }
        }

        if ($deleteCode !== '') {
            $deleteCode = strtoupper(trim($deleteCode));

            $codes = array_values(array_filter($codes, function ($c) use ($deleteCode) {
                return strtoupper(trim((string)$c)) !== $deleteCode;
            }));
        }

        $path   = COOKIEPATH;
        $domain = COOKIE_DOMAIN;

        if (!empty($codes)) {
            $value = implode(',', $codes);

            // Session cookie: expires=0 so the browser clears it on close if the
            // order is never placed (OE-26209).
            setcookie('olo_coupon_codes', $value, [
                'expires'  => 0,
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => is_ssl(),
                'httponly' => false,
            ]);

            $_COOKIE['olo_coupon_codes'] = $value;
        } else {
            setcookie('olo_coupon_codes', '', [
                'expires'  => time() - 3600,
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => is_ssl(),
                'httponly' => false,
            ]);
            unset($_COOKIE['olo_coupon_codes']);
        }
    }


    public function updateFragment($fragments) {
        ob_start();
        ?>
        <div id="olo-applied-coupons-list">
            <?php
            $coupons = !empty($_COOKIE['olo_coupon_codes']) ? explode(',', sanitize_text_field( $_COOKIE['olo_coupon_codes'] ) ) : [];
            if (!empty($coupons)) {
                echo '<ul class="olo-applied-coupons">';
                foreach ($coupons as $code) {
                    echo '<li>' . esc_html($code) . '<button type="button"
                    class="olo-remove-coupon"
                    data-coupon="' . esc_attr($code) . '"> X </button></li>';
                }
                echo '</ul>';
            }
            ?>
        </div>
        <?php
        $fragments['#olo-applied-coupons-list'] = ob_get_clean();
        return $fragments;
    }
}
