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
- Email notification to site admin/custom email (always or failed-only)

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

This plugin supports Woo-style headers (case-insensitive, spaces/underscores tolerated).

Recommended columns:

- `Type` (currently supports `simple`)
- `SKU` (required)
- `Name` (required)
- `Published` (1/0/yes/no -> publish/draft)
- `Regular price` (required)
- `Images` (comma-separated URLs; first one used as featured image)
- `Categories` (comma-separated; use `Parent > Child` for hierarchy)

Also supported (optional): `sale_price`, `stock_quantity`, `description`, `short_description`, `status`.

Example:

```csv
Type,SKU,Name,Published,Regular price,Images,Categories
simple,1590347139,Cartoon Style Personalized Statue For Musician,1,29,https://www.custom3dfigure.com/wp-content/uploads/2026/02/1590347139-cartoon_style_personalized_statue_for_musician.jpg,"Figure List, Occupations > Musician"
simple,1590725433,Lifelike 3d Printed Doll For Chef,1,29,https://www.custom3dfigure.com/wp-content/uploads/2026/02/1590725433-lifelike_3d_printed_doll_for_chef.jpg,"Figure List, Occupations > Chef"
```

## Schedule

Plugin registers `wcdi_daily_import_event` once per day.

> Production tip: add a system cron pinging `wp-cron.php` to avoid low-traffic missed runs.

## Data Tables

- `{prefix}wcdi_runs`
- `{prefix}wcdi_run_items`

## Email Notification

Configure in **WooCommerce -> CSV Daily Importer**:

- Enable/disable notification
- Mode: `failed_only` or `always`
- Target email (default: WordPress `admin_email`)
- On failures, email includes top 20 failed rows with reasons

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
