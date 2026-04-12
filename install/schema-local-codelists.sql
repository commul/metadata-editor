-- Local codelists (project-scoped vocabularies; items in local_codelist_items)
--
-- Apply to an existing database:
--   1. Run the CREATE TABLE statements below (if tables do not exist yet).
--   2. Run the ALTER TABLE indicator_dsd block only if those columns are not already present
--      (fresh installs that use install/schema-indicator-dsd.sql + install/schema.mysql.sql
--      already include the columns—skip the ALTER).
--
-- Note: There is no resource_type column. field_id identifies the owning structural row
-- in application logic (e.g. indicator_dsd.id). The same numeric id in different tables
-- could collide under the same sid; the app must use a single unambiguous scheme per project.

CREATE TABLE `local_codelists` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `sid` INT NOT NULL,
  `field_id` BIGINT NOT NULL,
  `name` VARCHAR(255) NULL,
  `description` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `changed_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT NULL,
  `changed_by` INT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_local_codelist_sid_field` (`sid`, `field_id`),
  KEY `idx_sid` (`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `local_codelist_items` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `local_codelist_id` BIGINT NOT NULL,
  `code` VARCHAR(150) NOT NULL,
  `label` TEXT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `changed_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT NULL,
  `changed_by` INT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_code_per_list` (`local_codelist_id`, `code`),
  KEY `idx_list_sort` (`local_codelist_id`, `sort_order`, `id`),
  CONSTRAINT `fk_local_codelist_items_list`
    FOREIGN KEY (`local_codelist_id`) REFERENCES `local_codelists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- indicator_dsd: global vs local codelist reference (run after local_codelists exists)
ALTER TABLE `indicator_dsd`
  ADD COLUMN `codelist_type` ENUM('none','global','local') NOT NULL DEFAULT 'none' AFTER `metadata`,
  ADD COLUMN `global_codelist_id` BIGINT NULL DEFAULT NULL COMMENT 'Registry codelists.id when codelist_type=global' AFTER `codelist_type`,
  ADD COLUMN `local_codelist_id` BIGINT NULL DEFAULT NULL AFTER `global_codelist_id`,
  ADD KEY `idx_indicator_dsd_global_codelist` (`global_codelist_id`),
  ADD KEY `idx_indicator_dsd_local_codelist` (`local_codelist_id`),
  ADD CONSTRAINT `fk_indicator_dsd_local_codelist`
    FOREIGN KEY (`local_codelist_id`) REFERENCES `local_codelists` (`id`) ON DELETE SET NULL;
