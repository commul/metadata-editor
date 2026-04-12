-- Indicator Data Structure Definition (DSD) table
-- Stores data structure columns for timeseries/indicator projects

CREATE TABLE `indicator_dsd` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sid` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `description` text,
  `data_type` enum('string','integer','float','double','date','boolean') NULL,
  `column_type` enum('dimension','time_period','measure','attribute','indicator_id','indicator_name','annotation','geography','observation_value','periodicity')  NULL,
  `time_period_format` varchar(30) DEFAULT NULL,
  `code_list` json DEFAULT NULL,
  `code_list_reference` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `sum_stats` json DEFAULT NULL COMMENT 'Column profile from DuckDB: row_count, non_null_count, null_count, distinct_count, freq (max 100); missing = NULL/trim empty',
  `codelist_type` enum('none','global','local') NOT NULL DEFAULT 'none',
  `global_codelist_id` bigint NULL DEFAULT NULL COMMENT 'Registry codelists.id when codelist_type=global',
  `local_codelist_id` bigint NULL DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `created` int DEFAULT NULL,
  `changed` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `changed_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sid_column_type` (`sid`, `column_type`),
  KEY `idx_sid_sort_order` (`sid`, `sort_order`),
  KEY `idx_local_codelist` (`local_codelist_id`),
  KEY `idx_indicator_dsd_global_codelist` (`global_codelist_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;
