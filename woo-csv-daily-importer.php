<?php
/**
 * Plugin Name: Woo CSV Daily Importer
 * Description: Imports up to 100 WooCommerce products daily from CSV with resume, retries, locking, and run logs.
 * Version: 0.1.0
 * Author: OpenClaw Assistant
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: woo-csv-daily-importer
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WCDI_VERSION')) {
    define('WCDI_VERSION', '0.1.0');
}

if (!defined('WCDI_PLUGIN_FILE')) {
    define('WCDI_PLUGIN_FILE', __FILE__);
}

if (!defined('WCDI_PLUGIN_DIR')) {
    define('WCDI_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

require_once WCDI_PLUGIN_DIR . 'includes/class-wcdi-plugin.php';

WCDI_Plugin::instance();

register_activation_hook(__FILE__, ['WCDI_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WCDI_Plugin', 'deactivate']);
