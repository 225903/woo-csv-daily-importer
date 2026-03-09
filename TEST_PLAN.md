# TEST_PLAN.md

## 1. Scope

Validate installation, import correctness, idempotency, resilience, rollback, and observability.

## 2. Test Environment

- WordPress 6.x
- WooCommerce active
- PHP 8.0+
- Writable uploads directory
- Plugin activated

## 3. Test Data Sets

- `valid-200.csv` (200 valid rows)
- `invalid-required.csv` (missing sku/name/regular_price rows)
- `mixed.csv` (valid + invalid + changed + unchanged)
- `same-file-rerun.csv` (for idempotency)

## 4. Test Cases

### TC-01 Install & activation
- Activate plugin
- Expect tables `{prefix}wcdi_runs` and `{prefix}wcdi_run_items` created
- Expect default options created

### TC-02 Settings save
- Save CSV path, batch limit, retry limit
- Expect persisted values and sanitized limits

### TC-03 Manual import happy path
- Use `valid-200.csv`
- Click "Run Import Now"
- Expect only first 100 rows processed
- Expect `success_count` > 0 and no fatal error

### TC-04 Daily schedule exists
- Verify `wcdi_daily_import_event` is scheduled

### TC-05 SKU idempotency
- Re-run same CSV
- Expect no duplicate products for same SKU
- Existing products updated or skipped

### TC-06 Row-hash skip
- Re-run unchanged file
- Expect most rows as `skipped`

### TC-07 Resume behavior
- Simulate interruption mid-run
- Re-run with same file
- Expect continue from `last_processed_row + 1`

### TC-08 New file reset
- Replace CSV with different content/hash
- Expect import starts from beginning

### TC-09 Validation failures
- Use rows missing required columns
- Expect item status `failed` with validation message
- Expect rest of batch continues

### TC-10 Retry behavior
- Inject temporary error path
- Expect retry up to configured limit, then fail

### TC-11 File disaster flow
- Run import
- Expect staged copy in `processing`
- On success -> file moves to `archive`
- On hard failure (e.g. unreadable staged copy) -> move to `failed`

### TC-12 Locking/concurrency
- Trigger concurrent runs
- Expect second run exits due to lock

### TC-13 Rollback create
- Run import creating new product
- Rollback run id
- Expect created product deleted

### TC-14 Rollback update
- Run import updating existing product
- Rollback run id
- Expect product fields restored to previous snapshot

### TC-15 Admin observability
- Check recent runs table values
- Verify processed/success/failed/skipped accuracy

## 5. Acceptance Criteria

- Import runs daily and manually without fatal errors
- Each run processes max 100 rows
- Resume works for same file hash
- No duplicate SKU products after repeated runs
- Failed rows do not block the whole batch
- Rollback can revert the chosen run
- Run and item logs are queryable and readable

## 6. Non-Functional Checks

- 100-row run finishes in acceptable time for target infra
- Memory usage stable for CSV > 1000 rows
- No PHP warnings/notices in production log under normal operation
