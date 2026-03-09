<?php

if (!defined('ABSPATH')) {
    exit;
}

class WCDI_Runner {
    public static function run(): void {
        if (get_transient('wcdi_import_lock')) {
            return;
        }

        set_transient('wcdi_import_lock', 1, 30 * MINUTE_IN_SECONDS);

        try {
            self::run_internal();
        } finally {
            delete_transient('wcdi_import_lock');
        }
    }

    public static function rollback_run(int $runId): array {
        global $wpdb;

        $runsTable = $wpdb->prefix . 'wcdi_runs';
        $itemsTable = $wpdb->prefix . 'wcdi_run_items';

        $run = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$runsTable} WHERE id = %d", $runId));
        if (!$run) {
            return ['ok' => false, 'message' => 'Run not found'];
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$itemsTable} WHERE run_id = %d AND status = 'success' ORDER BY id DESC",
            $runId
        ));

        $rolledBack = 0;
        $failed = 0;

        foreach ($items as $item) {
            $payload = json_decode((string) $item->rollback_payload, true);
            if (!is_array($payload) || empty($payload['type'])) {
                continue;
            }

            try {
                if ($payload['type'] === 'create') {
                    $productId = (int) ($payload['product_id'] ?? 0);
                    if ($productId > 0) {
                        wp_delete_post($productId, true);
                    }
                } elseif ($payload['type'] === 'update') {
                    $productId = (int) ($payload['product_id'] ?? 0);
                    $product = $productId > 0 ? wc_get_product($productId) : null;
                    if (!$product) {
                        throw new RuntimeException('Product not found for rollback');
                    }

                    $product->set_name((string) ($payload['name'] ?? ''));
                    $product->set_regular_price((string) ($payload['regular_price'] ?? ''));
                    $sale = (string) ($payload['sale_price'] ?? '');
                    $product->set_sale_price($sale === '' ? '' : $sale);
                    $manageStock = (bool) ($payload['manage_stock'] ?? false);
                    $product->set_manage_stock($manageStock);
                    $product->set_stock_quantity($manageStock ? (int) ($payload['stock_quantity'] ?? 0) : null);
                    $status = (string) ($payload['status'] ?? 'publish');
                    $product->set_status($status);
                    $product->set_description((string) ($payload['description'] ?? ''));
                    $product->set_short_description((string) ($payload['short_description'] ?? ''));
                    $savedId = $product->save();

                    if (isset($payload['row_hash'])) {
                        update_post_meta($savedId, '_wcdi_row_hash', (string) $payload['row_hash']);
                    }
                }

                $rolledBack++;
            } catch (Throwable $e) {
                $failed++;
            }
        }

        $note = sprintf('rollback: rolled_back=%d failed=%d', $rolledBack, $failed);
        $existingNotes = trim((string) ($run->notes ?? ''));
        $wpdb->update($runsTable, [
            'notes' => trim($existingNotes . "\n" . $note),
        ], ['id' => $runId]);

        return [
            'ok' => true,
            'message' => sprintf('Rollback finished. rolled_back=%d, failed=%d', $rolledBack, $failed),
        ];
    }

    private static function run_internal(): void {
        global $wpdb;

        $csvPath = (string) get_option('wcdi_csv_path', '');
        $batchLimit = max(1, min(100, (int) get_option('wcdi_batch_limit', 100)));
        $retryLimit = max(0, min(5, (int) get_option('wcdi_retry_limit', 3)));

        if (!$csvPath || !file_exists($csvPath) || !is_readable($csvPath)) {
            error_log('[WCDI] CSV path invalid or unreadable: ' . $csvPath);
            return;
        }

        $fileHash = hash_file('sha256', $csvPath) ?: '';
        $state = get_option('wcdi_state', ['file_hash' => '', 'last_processed_row' => 0]);
        $startRow = 1;

        if (($state['file_hash'] ?? '') === $fileHash) {
            $startRow = ((int) ($state['last_processed_row'] ?? 0)) + 1;
        }

        $runsTable = $wpdb->prefix . 'wcdi_runs';
        $itemsTable = $wpdb->prefix . 'wcdi_run_items';

        $wpdb->insert($runsTable, [
            'file_name' => basename($csvPath),
            'file_hash' => $fileHash,
            'started_at' => current_time('mysql'),
            'status' => 'running',
        ]);

        $runId = (int) $wpdb->insert_id;
        $stagedPath = WCDI_File_Manager::stage_processing_copy($csvPath, $runId);

        if (!$stagedPath || !file_exists($stagedPath)) {
            self::finish_run($runsTable, $runId, 'failed', ['notes' => 'Failed to stage processing copy']);
            return;
        }

        $fh = fopen($stagedPath, 'rb');
        if (!$fh) {
            WCDI_File_Manager::move_to_failed($stagedPath);
            self::finish_run($runsTable, $runId, 'failed', ['notes' => 'Unable to open staged file']);
            return;
        }

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            WCDI_File_Manager::move_to_failed($stagedPath);
            self::finish_run($runsTable, $runId, 'failed', ['notes' => 'Missing header']);
            return;
        }

        $stats = [
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
        ];

        $rowNumber = 1;
        $processedInThisRun = 0;

        while (($row = fgetcsv($fh)) !== false) {
            $rowNumber++;
            $stats['total_rows']++;

            if ($rowNumber < $startRow) {
                continue;
            }

            if ($processedInThisRun >= $batchLimit) {
                break;
            }

            $mapped = self::map_row($header, $row);
            $result = self::import_row($mapped, $retryLimit);

            $wpdb->insert($itemsTable, [
                'run_id' => $runId,
                'row_number' => $rowNumber,
                'sku' => $mapped['sku'] ?? '',
                'action' => $result['action'],
                'status' => $result['status'],
                'product_id' => $result['product_id'] ?: null,
                'message' => $result['message'],
                'rollback_payload' => wp_json_encode($result['rollback_payload'] ?? []),
                'created_at' => current_time('mysql'),
            ]);

            $stats['processed_rows']++;
            $processedInThisRun++;

            if ($result['status'] === 'success') {
                $stats['success_count']++;
            } elseif ($result['status'] === 'skipped') {
                $stats['skipped_count']++;
            } else {
                $stats['failed_count']++;
            }

            update_option('wcdi_state', [
                'file_hash' => $fileHash,
                'last_processed_row' => $rowNumber,
            ], false);
        }

        fclose($fh);
        WCDI_File_Manager::move_to_archive($stagedPath);
        self::finish_run($runsTable, $runId, 'finished', $stats);
    }

    private static function finish_run(string $runsTable, int $runId, string $status, array $stats): void {
        global $wpdb;

        $update = array_merge($stats, [
            'status' => $status,
            'finished_at' => current_time('mysql'),
        ]);

        $wpdb->update($runsTable, $update, ['id' => $runId]);
        self::send_notification($runId);
    }

    private static function send_notification(int $runId): void {
        $enabled = (int) get_option('wcdi_notify_enabled', 1) === 1;
        if (!$enabled) {
            return;
        }

        global $wpdb;
        $runsTable = $wpdb->prefix . 'wcdi_runs';
        $run = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$runsTable} WHERE id = %d", $runId));
        if (!$run) {
            return;
        }

        $mode = (string) get_option('wcdi_notify_mode', 'failed_only');
        $failedCount = (int) ($run->failed_count ?? 0);
        if ($mode === 'failed_only' && $failedCount <= 0) {
            return;
        }

        $to = sanitize_email((string) get_option('wcdi_notify_email', get_option('admin_email')));
        if (!$to || !is_email($to)) {
            return;
        }

        $subject = sprintf('[WCDI] Import Run #%d - %s', (int) $run->id, (string) $run->status);
        $message = implode("\n", [
            'Woo CSV Daily Importer run summary',
            '--------------------------------',
            'Run ID: ' . (int) $run->id,
            'File: ' . (string) $run->file_name,
            'Status: ' . (string) $run->status,
            'Processed: ' . (int) $run->processed_rows,
            'Success: ' . (int) $run->success_count,
            'Failed: ' . (int) $run->failed_count,
            'Skipped: ' . (int) $run->skipped_count,
            'Started: ' . (string) $run->started_at,
            'Finished: ' . (string) $run->finished_at,
            'Notes: ' . (string) ($run->notes ?? ''),
        ]);

        wp_mail($to, $subject, $message);
    }

    private static function map_row(array $header, array $row): array {
        $assoc = [];
        foreach ($header as $index => $column) {
            $key = strtolower(trim((string) $column));
            $assoc[$key] = isset($row[$index]) ? trim((string) $row[$index]) : '';
        }

        return [
            'sku' => $assoc['sku'] ?? '',
            'name' => $assoc['name'] ?? '',
            'regular_price' => $assoc['regular_price'] ?? '',
            'sale_price' => $assoc['sale_price'] ?? '',
            'stock_quantity' => $assoc['stock_quantity'] ?? '',
            'description' => $assoc['description'] ?? '',
            'short_description' => $assoc['short_description'] ?? '',
            'status' => $assoc['status'] ?? 'publish',
            'row_hash' => hash('sha256', wp_json_encode($assoc)),
        ];
    }

    private static function import_row(array $data, int $retryLimit): array {
        $sku = $data['sku'] ?? '';
        $name = $data['name'] ?? '';
        $regularPrice = $data['regular_price'] ?? '';

        if ($sku === '' || $name === '' || $regularPrice === '') {
            return [
                'action' => 'validate',
                'status' => 'failed',
                'product_id' => 0,
                'message' => 'Missing required field(s): sku/name/regular_price',
                'rollback_payload' => [],
            ];
        }

        $attempt = 0;
        do {
            $attempt++;
            try {
                $productId = wc_get_product_id_by_sku($sku);
                $action = $productId ? 'update' : 'create';

                $product = $productId ? wc_get_product($productId) : new WC_Product_Simple();
                if (!$product) {
                    throw new RuntimeException('Unable to initialize product object');
                }

                $existingHash = $productId ? (string) get_post_meta($productId, '_wcdi_row_hash', true) : '';
                if ($existingHash === $data['row_hash']) {
                    return [
                        'action' => 'skip',
                        'status' => 'skipped',
                        'product_id' => (int) $productId,
                        'message' => 'No changes detected by row hash',
                        'rollback_payload' => [],
                    ];
                }

                $rollbackPayload = [];
                if ($action === 'update') {
                    $rollbackPayload = [
                        'type' => 'update',
                        'product_id' => (int) $productId,
                        'name' => $product->get_name(),
                        'regular_price' => (string) $product->get_regular_price(),
                        'sale_price' => (string) $product->get_sale_price(),
                        'manage_stock' => (bool) $product->get_manage_stock(),
                        'stock_quantity' => (int) $product->get_stock_quantity(),
                        'status' => (string) $product->get_status(),
                        'description' => (string) $product->get_description(),
                        'short_description' => (string) $product->get_short_description(),
                        'row_hash' => $existingHash,
                    ];
                }

                $product->set_sku($sku);
                $product->set_name($data['name']);
                $product->set_regular_price((string) $data['regular_price']);

                if ($data['sale_price'] !== '') {
                    $product->set_sale_price((string) $data['sale_price']);
                } else {
                    $product->set_sale_price('');
                }

                if ($data['stock_quantity'] !== '') {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity((int) $data['stock_quantity']);
                }

                $allowedStatus = ['publish', 'draft', 'pending', 'private'];
                $status = in_array($data['status'], $allowedStatus, true) ? $data['status'] : 'publish';
                $product->set_status($status);

                if ($data['description'] !== '') {
                    $product->set_description($data['description']);
                }

                if ($data['short_description'] !== '') {
                    $product->set_short_description($data['short_description']);
                }

                $savedId = $product->save();
                update_post_meta($savedId, '_wcdi_row_hash', $data['row_hash']);

                if ($action === 'create') {
                    $rollbackPayload = [
                        'type' => 'create',
                        'product_id' => (int) $savedId,
                    ];
                }

                return [
                    'action' => $action,
                    'status' => 'success',
                    'product_id' => (int) $savedId,
                    'message' => sprintf('%s success', $action),
                    'rollback_payload' => $rollbackPayload,
                ];
            } catch (Throwable $e) {
                if ($attempt <= $retryLimit) {
                    usleep((int) (100000 * $attempt));
                    continue;
                }

                return [
                    'action' => 'import',
                    'status' => 'failed',
                    'product_id' => 0,
                    'message' => $e->getMessage(),
                    'rollback_payload' => [],
                ];
            }
        } while ($attempt <= $retryLimit);

        return [
            'action' => 'import',
            'status' => 'failed',
            'product_id' => 0,
            'message' => 'Unknown import error',
            'rollback_payload' => [],
        ];
    }
}
