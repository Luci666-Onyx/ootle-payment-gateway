<?php
/**
 * Plugin Name: Ootle Payment Gateway for WordPress
 * Description: WooCommerce payment gateway over the Tari Ootle Layer 2 network — TARI, NFT, and stablecoin checkout via the wallet-daemon JSON-RPC API, with a confidential/stealth settlement layer in active development. Built for the Tari October 2026 "Spooky Secrets" build contest.
 * Version: 1.0.0
 * Author: Tari Technology
 * License: MIT
 * Text Domain: ootle-gateway
 *
 * @package OotleGateway
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OOTLE_GATEWAY_VERSION', '1.0.0');
define('OOTLE_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OOTLE_API_BASE_URL', 'https://api.ootle.com/v1');

require_once __DIR__ . '/includes/class-ootle-order-meta.php';
require_once __DIR__ . '/includes/class-ootle-webhook-handler.php';

add_action('plugins_loaded', 'register_ootle_gateway');
add_action('wp_enqueue_scripts', 'ootle_gateway_enqueue_scripts');
add_action('admin_enqueue_scripts', 'ootle_gateway_admin_enqueue_scripts');

function register_ootle_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    require_once __DIR__ . '/includes/class-ootle-gateway.php';
    add_filter('woocommerce_payment_gateways', 'add_ootle_gateway');
}

function add_ootle_gateway($gateways) {
    $gateways[] = 'Ootle_WC_Gateway';
    return $gateways;
}

function ootle_gateway_enqueue_scripts() {
    // Placeholder for frontend scripts
}

function ootle_gateway_admin_enqueue_scripts($hook) {
    if ($hook !== 'woocommerce_page_wc-settings' && !str_contains($hook, 'wc-settings')) {
        return;
    }
}

register_activation_hook(__FILE__, function () {
    if (class_exists('WC_Payment_Gateway')) {
        flush_rewrite_rules();
    }
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules(false);
});