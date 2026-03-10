<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once WCDI_PLUGIN_DIR . 'includes/class-wcdi-installer.php';
require_once WCDI_PLUGIN_DIR . 'includes/class-wcdi-runner.php';
require_once WCDI_PLUGIN_DIR . 'includes/class-wcdi-file-manager.php';
require_once WCDI_PLUGIN_DIR . 'includes/class-wcdi-admin.php';

class WCDI_Plugin {
    private static ?WCDI_Plugin $instance = null;

    public static function instance(): WCDI_Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'bootstrap']);
    }

    public function bootstrap(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_filter('cron_schedules', [$this, 'register_cron_schedule']);
        add_action('init', [$this, 'register_cron']);
        add_action('wcdi_daily_import_event', ['WCDI_Runner', 'run']);

        WCDI_Admin::init();
    }

    public function register_cron_schedule(array $schedules): array {
        $schedules['wcdi_every_24h'] = [
            'interval' => DAY_IN_SECONDS,
            'display' => __('Every 24 Hours (WCDI)', 'woo-csv-daily-importer'),
        ];

        return $schedules;
    }

    public function register_cron(): void {
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event('wcdi_daily_import_event') : null;

        if ($event && ($event->schedule ?? '') !== 'wcdi_every_24h') {
            wp_clear_scheduled_hook('wcdi_daily_import_event');
            $event = null;
        }

        if (!$event && !wp_next_scheduled('wcdi_daily_import_event')) {
            wp_schedule_event(time() + 300, 'wcdi_every_24h', 'wcdi_daily_import_event');
        }
    }

    public static function activate(): void {
        WCDI_Installer::install();

        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['wcdi_every_24h'] = [
                'interval' => DAY_IN_SECONDS,
                'display' => __('Every 24 Hours (WCDI)', 'woo-csv-daily-importer'),
            ];
            return $schedules;
        });

        wp_clear_scheduled_hook('wcdi_daily_import_event');
        wp_schedule_event(time() + 300, 'wcdi_every_24h', 'wcdi_daily_import_event');
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('wcdi_daily_import_event');
        delete_transient('wcdi_import_lock');
    }
}
