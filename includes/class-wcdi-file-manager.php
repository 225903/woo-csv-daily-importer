<?php

if (!defined('ABSPATH')) {
    exit;
}

class WCDI_File_Manager {
    public static function ensure_dirs(): array {
        $base = trailingslashit(wp_upload_dir()['basedir']) . 'wp-woo-import';
        $dirs = [
            'base' => $base,
            'inbox' => $base . '/inbox',
            'processing' => $base . '/processing',
            'archive' => $base . '/archive',
            'failed' => $base . '/failed',
        ];

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
        }

        return $dirs;
    }

    public static function stage_processing_copy(string $sourcePath, int $runId): ?string {
        $dirs = self::ensure_dirs();
        $ext = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $name = pathinfo($sourcePath, PATHINFO_FILENAME);
        $staged = sprintf('%s/%s-run-%d-%s.%s', $dirs['processing'], $name, $runId, gmdate('YmdHis'), $ext ?: 'csv');

        if (!@copy($sourcePath, $staged)) {
            return null;
        }

        return $staged;
    }

    public static function move_to_archive(string $stagedPath): void {
        $dirs = self::ensure_dirs();
        $dest = $dirs['archive'] . '/' . basename($stagedPath);
        @rename($stagedPath, $dest);
    }

    public static function move_to_failed(string $stagedPath): void {
        $dirs = self::ensure_dirs();
        $dest = $dirs['failed'] . '/' . basename($stagedPath);
        @rename($stagedPath, $dest);
    }
}
