<?php

if (!defined('ABSPATH')) {
    exit;
}

class WCDI_Admin {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function menu(): void {
        add_submenu_page(
            'woocommerce',
            'CSV Daily Importer',
            'CSV Daily Importer',
            'manage_woocommerce',
            'wcdi-settings',
            [self::class, 'render']
        );
    }

    public static function register_settings(): void {
        register_setting('wcdi_settings_group', 'wcdi_csv_path', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('wcdi_settings_group', 'wcdi_batch_limit', [
            'type' => 'integer',
            'sanitize_callback' => static fn($v) => max(1, min(100, (int) $v)),
            'default' => 100,
        ]);

        register_setting('wcdi_settings_group', 'wcdi_retry_limit', [
            'type' => 'integer',
            'sanitize_callback' => static fn($v) => max(0, min(5, (int) $v)),
            'default' => 3,
        ]);

        register_setting('wcdi_settings_group', 'wcdi_notify_enabled', [
            'type' => 'integer',
            'sanitize_callback' => static fn($v) => (int) !empty($v),
            'default' => 1,
        ]);

        register_setting('wcdi_settings_group', 'wcdi_notify_mode', [
            'type' => 'string',
            'sanitize_callback' => static fn($v) => in_array($v, ['always', 'failed_only'], true) ? $v : 'failed_only',
            'default' => 'failed_only',
        ]);

        register_setting('wcdi_settings_group', 'wcdi_notify_email', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => get_option('admin_email'),
        ]);
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (isset($_POST['wcdi_run_now']) && check_admin_referer('wcdi_run_now_action')) {
            WCDI_Runner::run();
            echo '<div class="notice notice-success"><p>Import executed. Check logs below.</p></div>';
        }

        if (isset($_POST['wcdi_rollback_run']) && check_admin_referer('wcdi_rollback_run_action')) {
            $runId = isset($_POST['wcdi_rollback_run_id']) ? (int) $_POST['wcdi_rollback_run_id'] : 0;
            if ($runId > 0) {
                $result = WCDI_Runner::rollback_run($runId);
                $noticeClass = !empty($result['ok']) ? 'notice-success' : 'notice-error';
                echo '<div class="notice ' . esc_attr($noticeClass) . '"><p>' . esc_html((string) ($result['message'] ?? 'Rollback finished.')) . '</p></div>';
            }
        }

        global $wpdb;
        $runsTable = $wpdb->prefix . 'wcdi_runs';
        $runs = $wpdb->get_results("SELECT * FROM {$runsTable} ORDER BY id DESC LIMIT 10");

        ?>
        <div class="wrap">
            <h1>Woo CSV Daily Importer</h1>
            <form method="post" action="options.php">
                <?php settings_fields('wcdi_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wcdi_csv_path">CSV Absolute Path</label></th>
                        <td><input type="text" id="wcdi_csv_path" name="wcdi_csv_path" value="<?php echo esc_attr(get_option('wcdi_csv_path', '')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcdi_batch_limit">Batch Limit (max 100)</label></th>
                        <td><input type="number" id="wcdi_batch_limit" name="wcdi_batch_limit" value="<?php echo esc_attr((string) get_option('wcdi_batch_limit', 100)); ?>" min="1" max="100" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcdi_retry_limit">Retry Limit</label></th>
                        <td><input type="number" id="wcdi_retry_limit" name="wcdi_retry_limit" value="<?php echo esc_attr((string) get_option('wcdi_retry_limit', 3)); ?>" min="0" max="5" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcdi_notify_enabled">Email Notify</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wcdi_notify_enabled" name="wcdi_notify_enabled" value="1" <?php checked((int) get_option('wcdi_notify_enabled', 1), 1); ?> />
                                Enable email notifications
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcdi_notify_mode">Notify Mode</label></th>
                        <td>
                            <select id="wcdi_notify_mode" name="wcdi_notify_mode">
                                <option value="failed_only" <?php selected((string) get_option('wcdi_notify_mode', 'failed_only'), 'failed_only'); ?>>Only when there are failures</option>
                                <option value="always" <?php selected((string) get_option('wcdi_notify_mode', 'failed_only'), 'always'); ?>>Always after each run</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcdi_notify_email">Notify Email</label></th>
                        <td><input type="email" id="wcdi_notify_email" name="wcdi_notify_email" value="<?php echo esc_attr((string) get_option('wcdi_notify_email', get_option('admin_email'))); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <form method="post">
                <?php wp_nonce_field('wcdi_run_now_action'); ?>
                <p><button class="button button-primary" name="wcdi_run_now" value="1" type="submit">Run Import Now</button></p>
            </form>

            <h2>Rollback</h2>
            <form method="post">
                <?php wp_nonce_field('wcdi_rollback_run_action'); ?>
                <p>
                    <label for="wcdi_rollback_run_id">Run ID</label>
                    <input type="number" id="wcdi_rollback_run_id" name="wcdi_rollback_run_id" min="1" required />
                    <button class="button" name="wcdi_rollback_run" value="1" type="submit" onclick="return confirm('Rollback will attempt to revert all successful items from this run. Continue?');">Rollback This Run</button>
                </p>
            </form>

            <h2>Recent Runs</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th><th>File</th><th>Status</th><th>Processed</th><th>Success</th><th>Failed</th><th>Skipped</th><th>Started</th><th>Finished</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($runs): foreach ($runs as $run): ?>
                    <tr>
                        <td><?php echo (int) $run->id; ?></td>
                        <td><?php echo esc_html($run->file_name); ?></td>
                        <td><?php echo esc_html($run->status); ?></td>
                        <td><?php echo (int) $run->processed_rows; ?></td>
                        <td><?php echo (int) $run->success_count; ?></td>
                        <td><?php echo (int) $run->failed_count; ?></td>
                        <td><?php echo (int) $run->skipped_count; ?></td>
                        <td><?php echo esc_html($run->started_at); ?></td>
                        <td><?php echo esc_html((string) $run->finished_at); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9">No runs yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
