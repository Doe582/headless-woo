<?php
/**
 * Plugin Name:       Headless Cart Manager
 * Plugin URI:        https://github.com/yourname/headless-cart-manager
 * Description:       Complete headless WooCommerce cart — JWT auth, persistent user carts, coupons, shipping, cart fees, batch API & checkout. Works on all devices.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           GPL-2.0+
 * Text Domain:       hcm
 *
 * WC requires at least: 6.0
 * WC tested up to:      9.5
 */

defined('ABSPATH') || exit;

// ── Constants ─────────────────────────────────────────────────────────────────
define('HCM_VER',  '1.0.0');
define('HCM_DIR',  plugin_dir_path(__FILE__));
define('HCM_URL',  plugin_dir_url(__FILE__));
define('HCM_NS',   'hcm/v1');

// ── Activation: create DB tables ──────────────────────────────────────────────
register_activation_hook(__FILE__, static function () {
    require_once HCM_DIR . 'includes/class-hcm-install.php';
    HCM_Install::activate();
});

// ── Bootstrap after all plugins loaded ────────────────────────────────────────
add_action('plugins_loaded', static function () {

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>'
               . esc_html__('Headless Cart Manager requires WooCommerce to be installed and active.', 'hcm')
               . '</p></div>';
        });
        return;
    }

    // Load all classes
    foreach ([
        'class-hcm-install',
        'class-hcm-jwt',
        'class-hcm-rate-limit',
        'class-hcm-cart',
        'class-hcm-coupon',
        'class-hcm-shipping',
        'class-hcm-fees',
        'class-hcm-checkout',
        'class-hcm-batch',
        'class-hcm-sync',
    ] as $file) {
        require_once HCM_DIR . 'includes/' . $file . '.php';
    }

    // Hook JWT into WordPress authentication
    add_filter('determine_current_user',     ['HCM_JWT', 'authenticate'], 20);
    add_filter('rest_authentication_errors', ['HCM_JWT', 'auth_errors']);

    // Initialize Sync hooks
    HCM_Sync::init();

    // Register all REST API routes
    add_action('rest_api_init', static function () {
        HCM_JWT::register_routes();
        (new HCM_Cart())->register_routes();
        (new HCM_Coupon())->register_routes();
        (new HCM_Shipping())->register_routes();
        (new HCM_Fees())->register_routes();
        (new HCM_Checkout())->register_routes();
        (new HCM_Batch())->register_routes();
    });

    // CORS headers for headless (customize allowed origins via filter)
    add_action('rest_api_init', static function () {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', static function ($value) {
            $origin  = $_SERVER['HTTP_ORIGIN'] ?? '*';
            $allowed = apply_filters('hcm_allowed_origins', [$origin]);

            if (in_array($origin, $allowed, true) || in_array('*', $allowed, true)) {
                header('Access-Control-Allow-Origin: '  . esc_url_raw($origin));
                header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Cart-Key');
            }
            return $value;
        });
    }, 15);
});
