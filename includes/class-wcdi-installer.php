<?php

if (!defined('ABSPATH')) {
    exit;
}

class WCDI_Installer {
    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $runsTable = $wpdb->prefix . 'wcdi_runs';
        $itemsTable = $wpdb->prefix . 'wcdi_run_items';

        $sqlRuns = "CREATE TABLE {$runsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            file_name VARCHAR(255) NOT NULL,
            file_hash VARCHAR(64) NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            status VARCHAR(20) NOT NULL,
            total_rows INT UNSIGNED NOT NULL DEFAULT 0,
            processed_rows INT UNSIGNED NOT NULL DEFAULT 0,
            success_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
            notes TEXT NULL,
            PRIMARY KEY (id),
            KEY file_hash (file_hash),
            KEY status (status)
        ) {$charsetCollate};";

        $sqlItems = "CREATE TABLE {$itemsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            row_number INT UNSIGNED NOT NULL,
            sku VARCHAR(191) NULL,
            action VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            message TEXT NULL,
            rollback_payload LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY run_id (run_id),
            KEY row_number (row_number),
            KEY sku (sku)
        ) {$charsetCollate};";

        dbDelta($sqlRuns);
        dbDelta($sqlItems);

        add_option('wcdi_batch_limit', 100);
        add_option('wcdi_retry_limit', 3);
        WCDI_File_Manager::ensure_dirs();

        add_option('wcdi_csv_path', wp_upload_dir()['basedir'] . '/wp-woo-import/inbox/products.csv');
        add_option('wcdi_state', [
            'file_hash' => '',
            'last_processed_row' => 0,
        ]);
        add_option('wcdi_notify_enabled', 1);
        add_option('wcdi_notify_mode', 'failed_only');
        add_option('wcdi_notify_email', get_option('admin_email'));
    }
}
