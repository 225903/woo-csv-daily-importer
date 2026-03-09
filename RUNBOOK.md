# RUNBOOK.md

## 1. Daily Operations

1. Ensure CSV is uploaded to:
   - `wp-content/uploads/wp-woo-import/inbox/`
2. Confirm plugin settings path points to the expected CSV file.
3. Check latest run status in WooCommerce -> CSV Daily Importer.

## 2. Manual Run

- Open admin page
- Click **Run Import Now**
- Verify latest run counters

## 3. Rollback Procedure

1. Find target `Run ID` in Recent Runs
2. Enter run id in Rollback form
3. Confirm rollback action
4. Re-check product records and run notes

## 4. Failure Triage

### A. CSV not found / unreadable
- Verify absolute path
- Verify file permissions
- Verify file exists on server

### B. Repeated failed items
- Inspect failed row messages in `wcdi_run_items`
- Validate required columns and values
- Fix CSV and rerun

### C. Cron not triggering
- Verify WP-Cron enabled
- Add system cron ping to `wp-cron.php`

### D. Duplicate import suspicion
- Confirm SKU uniqueness in CSV
- Check lock and run timestamps
- Confirm row hash behavior

## 5. Database Inspection Queries

```sql
-- latest runs
SELECT * FROM wp_wcdi_runs ORDER BY id DESC LIMIT 20;

-- failed items for one run
SELECT * FROM wp_wcdi_run_items WHERE run_id = 123 AND status = 'failed';
```

## 6. Backup Recommendations

- Daily DB backup before import window
- Keep at least 7 snapshots/restore points
- Test restore periodically in staging

## 7. Operational Guardrails

- Keep batch size <= 100
- Do not disable lock logic
- Prefer staging validation before production rollout
- Preserve archive/failed files for auditability
