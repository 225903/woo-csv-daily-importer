# Woo CSV Daily Importer

A WordPress/WooCommerce plugin that imports product data from CSV daily.

## Features

- Daily scheduled import (WP-Cron)
- Batch limit per run (default 100, max 100)
- Idempotent import by SKU (create or update)
- Row-hash skip (no-change rows are skipped)
- Resume from last processed row (`file_hash + last_processed_row`)
- Retry on temporary failures
- Run/item logs in custom DB tables
- File disaster-flow: `inbox -> processing -> archive/failed`
- Rollback by `run_id` (delete created products, restore updated products)

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce active

## Installation

1. Copy plugin folder to `wp-content/plugins/woo-csv-daily-importer`
2. Activate plugin in WP Admin
3. Open **WooCommerce -> CSV Daily Importer**
4. Configure CSV path (absolute path)

Default path example:

`wp-content/uploads/wp-woo-import/inbox/products.csv`

## CSV Header

Required columns:

- `sku`
- `name`
- `regular_price`

Optional columns:

- `sale_price`
- `stock_quantity`
- `description`
- `short_description`
- `status` (`publish|draft|pending|private`)

Example:

```csv
sku,name,regular_price,sale_price,stock_quantity,description,short_description,status
SKU-001,Demo Product A,99,79,10,"Long description","Short description",publish
SKU-002,Demo Product B,59,,50,"Long description","Short description",draft
```

## Schedule

Plugin registers `wcdi_daily_import_event` once per day.

> Production tip: add a system cron pinging `wp-cron.php` to avoid low-traffic missed runs.

## Data Tables

- `{prefix}wcdi_runs`
- `{prefix}wcdi_run_items`

## Rollback

In admin page, input `Run ID` and click rollback.

- `create` records => hard delete created product
- `update` records => restore pre-update snapshot

## Limitations (current)

- Focuses on simple products only
- No variable-product/attribute image mapping yet
- Rollback targets fields currently captured in snapshot

## Development

Lint:

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```
