<?php
/**
 * Plugin Name: WooCommerce Multi-Pack Wholesale Manager
 * Description: Adds configurable wholesale pack tables to WooCommerce product pages with BOGO and fixed-price logic.
 * Version: 1.0.0
 * Author: GitHub Copilot
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: plugin-multi-packs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('WC_MULTI_PACKS_FILE', __FILE__);
define('WC_MULTI_PACKS_PATH', plugin_dir_path(__FILE__));
define('WC_MULTI_PACKS_URL', plugin_dir_url(__FILE__));

require_once WC_MULTI_PACKS_PATH . 'includes/class-wc-multi-packs-plugin.php';

WC_Multi_Packs_Plugin::instance();
