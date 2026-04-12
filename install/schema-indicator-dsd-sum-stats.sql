-- Per-column summary statistics for indicator DSD (populated by DuckDB / API later).
--
-- Apply to an existing database that already has `indicator_dsd`.
-- Skip if `sum_stats` already exists (e.g. fresh install from schema-indicator-dsd.sql
-- or schema.mysql.sql after this column was added).

ALTER TABLE `indicator_dsd`
  ADD COLUMN `sum_stats` json DEFAULT NULL
  COMMENT 'Column profile: field, row_count, non_null_count, null_count, distinct_count, freq_max, freq_truncated, freq[{value,count}]; null/blank/whitespace = missing'
  AFTER `metadata`;
