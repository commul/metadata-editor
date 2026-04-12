-- Migration: Add indicator_dsd table for Data Structure Definition
-- Description: Creates table to store data structure columns for indicator/timeseries projects.
--              Run on existing databases that do not yet have this table.
--              For fresh installs, use install/schema.mysql.sql which includes all tables.
--
-- After running this script, apply incremental migrations in order:
--   1. install/schema-indicator-dsd-sum-stats.sql        (adds sum_stats column)
--   2. install/schema-local-codelists.sql                (adds local_codelists tables + codelist columns)
--   3. install/schema-indicator-dsd-global-codelist-id-migration.sql  (adds global_codelist_id if missing)

CREATE TABLE `indicator_dsd` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sid` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `description` text,
  `data_type` enum('string','integer','float','double','date','boolean') NULL,
  `column_type` enum('dimension','time_period','measure','attribute','indicator_id','indicator_name','annotation','geography','observation_value','periodicity') NULL,
  `time_period_format` varchar(30) DEFAULT NULL,
  `code_list` json DEFAULT NULL,
  `code_list_reference` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `created` int DEFAULT NULL,
  `changed` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `changed_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sid_column_type` (`sid`, `column_type`),
  KEY `idx_sid_sort_order` (`sid`, `sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;
