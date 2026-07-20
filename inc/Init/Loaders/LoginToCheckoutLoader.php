<?php

namespace CBSNorthStar\Init\Loaders;

/**
 * Handles AJAX login from the checkout popup dialog.
 *
 * @package CBSNorthStar\Init\Loaders
 */
class LoginToCheckoutLoader implements JavaScriptLoaderContract
{
    private static ?LoginToCheckoutLoader $instance = null;

    public static function create(): ?LoginToCheckoutLoader
    {
        if (self::$instance === null) {
            self::$instance = new LoginToCheckoutLoader();
        }

        return self::$instance;
    }

    public function registerScripts(): void
    {
        add_action('wp_ajax_ajax_login', [$this, 'ajaxHandler']);
        add_action('wp_ajax_nopriv_ajax_login', [$this, 'ajaxHandler']);
    }

    /**
     * Handle the AJAX login request.
     *
     * Sets authentication cookies properly so WooCommerce can populate
     * customer data on the checkout page after redirect.
     *
     * @return void
     */
    public function ajaxHandler(): void
    {
        check_ajax_referer('ajax-login-nonce', 'security');

        $username = isset($_POST['username']) ? sanitize_user($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $remember = isset($_POST['remember']) && $_POST['remember'] === 'true';

        if (empty($username) || empty($password)) {
            wp_send_json_error([
                'loggedin' => false,
                'message'  => __('Please enter username and password.', 'olo'),
            ]);
        }

        // Authenticate without setting cookies (we'll do it manually for better control)
        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            wp_send_json_error([
                'loggedin' => false,
                'message'  => __('Wrong username or password.', 'olo'),
            ]);
        }

        // Clear any output buffers to ensure cookies can be set
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        wp_set_current_user($user->ID, $user->user_login);

        wp_set_auth_cookie($user->ID, $remember, is_ssl());

        do_action('wp_login', $user->user_login, $user);

        // Handle WooCommerce session transition from guest to logged-in
        if (function_exists('WC') && WC()->session) {
            $cart_contents = WC()->session->get('cart', []);
            
            WC()->session->destroy_session();
            
            WC()->session->set_customer_session_cookie(true);
            
            if (!empty($cart_contents)) {
                WC()->session->set('cart', $cart_contents);
            }

            // Update customer ID in session
            if (WC()->customer) {
                WC()->customer->set_id($user->ID);
            }
        }

        wp_send_json_success([
            'loggedin' => true,
            'message'  => __('Login successful', 'olo'),
        ]);
    }
}
