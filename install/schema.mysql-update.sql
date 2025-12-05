ALTER TABLE `editor_data_files` MODIFY COLUMN data_checks TEXT DEFAULT NULL;
ALTER TABLE `editor_data_files` MODIFY COLUMN missing_data TEXT DEFAULT NULL;
ALTER TABLE `editor_data_files` MODIFY COLUMN notes TEXT DEFAULT NULL;

ALTER TABLE `editor_data_files` MODIFY COLUMN metadata TEXT DEFAULT NULL;


-- Add pid and wgt columns to editor_collections
ALTER TABLE `editor_collections` add pid int DEFAULT NULL;
ALTER TABLE `editor_collections` add wgt int DEFAULT NULL;
ALTER TABLE `editor_collections` ADD UNIQUE index `idx_title_pid` (`title`,`pid`);


-- Add fulltext index to editor_projects
ALTER TABLE `editor_projects` 
ADD FULLTEXT INDEX `ft_projects` (`title`) ;


ALTER TABLE `audit_logs` 
ADD COLUMN `metadata` JSON NULL;

ALTER TABLE `audit_logs` 
CHANGE COLUMN `description` `action_type` VARCHAR(10) NOT NULL ;


CREATE TABLE `editor_template_acl` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_id` int NOT NULL,
  `permissions` varchar(100) DEFAULT NULL,
  `user_id` int NOT NULL,
  `created` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;



ALTER TABLE `editor_templates` 
ADD COLUMN `created_by` INT;

ALTER TABLE `editor_templates` 
ADD COLUMN `changed_by` INT;

ALTER TABLE `editor_templates`
ADD COLUMN `owner_id` INT;

ALTER TABLE `editor_templates`
ADD COLUMN `is_private` INT NULL;

ALTER TABLE `editor_templates`
ADD COLUMN `is_published` INT NULL;

ALTER TABLE `editor_templates`
ADD COLUMN `template_type` VARCHAR(20) NOT NULL DEFAULT 'custom' AFTER `lang`;

ALTER TABLE `editor_templates`
ADD COLUMN `is_deleted` INT NULL AFTER `is_published`,
ADD COLUMN `deleted_by` INT NULL AFTER `is_deleted`,
ADD COLUMN `deleted_at` INT NULL AFTER `deleted_by`;

UPDATE `editor_templates`
SET `template_type` = CASE
    WHEN `uid` LIKE '%__core' THEN 'generated'
    ELSE 'custom'
END;


update editor_templates set created_by=1 where created_by is null;
update editor_templates set owner_id=created_by where owner_id is null;


drop table `edit_history`;
CREATE TABLE `edit_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `obj_type` varchar(15) NOT NULL,
  `obj_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `action_type` varchar(10) NOT NULL,
  `created` INT DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;


ALTER TABLE `editor_data_files` 
ADD COLUMN `store_data` INT NULL;


ALTER TABLE `editor_data_files` 
ADD COLUMN `created` INT NULL AFTER `store_data`,
ADD COLUMN `changed` INT NULL AFTER `created`,
ADD COLUMN `created_by` INT NULL AFTER `changed`,
ADD COLUMN `changed_by` INT NULL AFTER `created_by`;


-- 2025/02/11
-- admin metadata types

drop table `metadata_schemas`;  
drop TABLE `metadata_types`;
drop TABLE `metadata_types_acl`;
drop TABLE `metadata_types_data`;



CREATE TABLE `admin_metadata` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_id` int DEFAULT NULL,
  `sid` int DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `changed_by` int DEFAULT NULL,
  `created` int DEFAULT NULL,
  `changed` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meta_unq` (`template_id`,`sid`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

CREATE TABLE `admin_metadata_acl` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_id` int NOT NULL,
  `permissions` varchar(100) DEFAULT NULL,
  `user_id` int NOT NULL,
  `created` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

-- 2025/02/15
CREATE TABLE `admin_metadata_projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sid` int DEFAULT NULL,
  `template_id` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) DEFAULT CHARSET=utf8mb4;

-- 2025/03/31
ALTER TABLE `audit_logs` 
CHANGE COLUMN `obj_type` `obj_type` VARCHAR(25) NOT NULL ;

ALTER TABLE `audit_logs` 
CHANGE COLUMN `action_type` `action_type` VARCHAR(25) NOT NULL ;

ALTER TABLE `editor_projects` 
ADD COLUMN `attributes` JSON NULL;

ALTER TABLE `editor_variables`
ADD COLUMN `is_key` INT NULL;


-- 2025/06/07
ALTER TABLE `audit_logs`
ADD COLUMN `obj_ref_id` INT NULL;


-- 2025/06/19
-- project versions

ALTER TABLE `editor_projects` 
ADD COLUMN `pid` INT NULL AFTER `study_idno`,
ADD COLUMN `is_locked` INT NULL AFTER `pid`,
ADD COLUMN `version_created` INT NULL AFTER `is_locked`,
ADD COLUMN `version_created_by` INT NULL AFTER `version_created`,
ADD COLUMN `version_notes` VARCHAR(500) NULL AFTER `version_created_by`,
ADD COLUMN `version_number` VARCHAR(15) NULL AFTER `study_idno`,
ADD UNIQUE INDEX `unq_idno` (`idno` ASC, `version_number` ASC);

-- need this to optimize the search for variables
CREATE INDEX idx_sid_fid_name ON editor_variables (sid, fid, name);


-- 2025/08/24
-- collections ACL

CREATE TABLE `editor_collection_acl` (
  `id` int NOT NULL AUTO_INCREMENT,
  `collection_id` int NOT NULL,
  `permissions` varchar(100) DEFAULT NULL,
  `user_id` int NOT NULL,
  `created` int DEFAULT NULL,
  `changed` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;


-- rename editor_collection_access to editor_collection_project_acl
ALTER TABLE `editor_collection_access` RENAME TO `editor_collection_project_acl`;


-- 2025/09/10
-- Add interval_type field to editor_variables table
ALTER TABLE `editor_variables` ADD COLUMN `interval_type` varchar(20) DEFAULT NULL;


-- 2025/09/17
-- audit_logs indexes
ALTER TABLE `audit_logs` ADD INDEX `idx_audit_logs_created` (`created` DESC);

-- Index on user_id for filtering
ALTER TABLE `audit_logs` ADD INDEX `idx_audit_logs_user_id` (`user_id`);

-- Index on obj_type for filtering
ALTER TABLE `audit_logs` ADD INDEX `idx_audit_logs_obj_type` (`obj_type`);

-- Index on action_type for filtering
ALTER TABLE `audit_logs` ADD INDEX `idx_audit_logs_action_type` (`action_type`);

-- Composite index for common query patterns (user_id + created)
ALTER TABLE `audit_logs` ADD INDEX `idx_audit_logs_user_created` (`user_id`, `created` DESC);

-- Composite index for object type queries (obj_type + created)
ALTER TABLE `audit_logs` ADD INDEX `idx_audit_logs_obj_type_created` (`obj_type`, `created` DESC);

-- Composite index for action type queries (action_type + created)
ALTER TABLE `audit_logs` ADD INDEX `idx_audit_logs_action_created` (`action_type`, `created` DESC);


-- collection indexes
CREATE INDEX idx_eca_user_collection ON editor_collection_acl(user_id, collection_id);
CREATE INDEX idx_ecpa_user_collection ON editor_collection_project_acl(user_id, collection_id);
CREATE INDEX idx_collections_created_by ON editor_collections(created_by);
CREATE INDEX idx_collection_id ON editor_collection_projects(collection_id);

-- 2025/11/10
-- metadata schemas

CREATE TABLE `metadata_schemas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(100) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `agency` varchar(100) DEFAULT NULL,
  `description` text,
  `is_core` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','draft') DEFAULT 'active',
  `storage_path` varchar(255) NOT NULL,
  `filename` varchar(255) NOT NULL DEFAULT 'main.json',
  `schema_files` json DEFAULT NULL,
  `metadata_options` json DEFAULT NULL,
  `alias` varchar(100) DEFAULT NULL,
  `created` int unsigned NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `updated` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_unique` (`uid`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;



INSERT INTO metadata_schemas
  (uid,title,agency,description,is_core,status,storage_path,filename,
   schema_files,metadata_options,alias,created)
VALUES
  ('microdata','Microdata (DDI 2.5)','IHSN','Microdata schema based on DDI CodeBook 2.5',
   1,'active','', 'microdata-schema.json',
   '["ddi-schema.json", "datacite-schema.json", "provenance-schema.json", "datafile-schema.json", "variable-schema.json", "variable-group-schema.json"]',
   '{"core_fields":{"idno":"study_desc.title_statement.idno","title":"study_desc.title_statement.title"},"derived_fields":{"countries":"study_desc.study_info.nation[*].name","year_start":"study_desc.study_info.coll_dates[0].start","year_end":"study_desc.study_info.coll_dates[0].end"}}',
   'survey',
   UNIX_TIMESTAMP()),
  ('document','Document','IHSN','Document schema based on Dublin Core',
   1,'active','', 'document-schema.json',
   '["provenance-schema.json"]',
   '{"core_fields":{"idno":"document_description.title_statement.idno","title":"document_description.title_statement.title"}}',
   '',
   UNIX_TIMESTAMP()),
  ('table','Statistical Table','IHSN','Statistical table schema based on Dublin Core',
   1,'active','', 'table-schema.json',
   '["provenance-schema.json"]',
   '{"core_fields":{"idno":"table_description.title_statement.idno","title":"table_description.title_statement.title"}}',
   '',
   UNIX_TIMESTAMP()),
  ('script','Script / Project','IHSN','Script/Project schema based on Dublin Core',
   1,'active','', 'script-schema.json',
   '["datacite-schema.json","provenance-schema.json"]',
   '{"core_fields":{"idno":"project_desc.title_statement.idno","title":"project_desc.title_statement.title"}}',
   '',
   UNIX_TIMESTAMP()),
  ('video','Video','IHSN','Video schema based on Dublin Core',
   1,'active','', 'video-schema.json',
   '[]',
   '{"core_fields":{"idno":"video_description.idno","title":"video_description.title"}}',
   '',
   UNIX_TIMESTAMP()),
  ('indicator','Indicator','IHSN','Indicator schema',
   1,'active','', 'timeseries-schema.json',
   '["datacite-schema.json","provenance-schema.json"]',
   '{"core_fields":{"idno":"series_description.idno","title":"series_description.name"},"derived_fields":{"year_start":"series_description.time_periods[0].start","year_end":"series_description.time_periods[0].end"}}',
   'timeseries',
   UNIX_TIMESTAMP()),
  ('indicator-db','Indicator Database','IHSN','Indicator database schema',
   1,'active','', 'timeseries-db-schema.json',
   '["provenance-schema.json"]',
   '{"core_fields":{"idno":"database_description.title_statement.idno","title":"database_description.title_statement.title"}}',
   'timeseries-db',
   UNIX_TIMESTAMP()),
  ('geospatial','Geospatial','IHSN','Geospatial schema based on ISO 19139',
   1,'active','', 'geospatial-schema.json',
   '["provenance-schema.json"]',
   '{"core_fields":{"idno":"description.idno","title":"description.identificationInfo.citation.title"}}',
    '',
   UNIX_TIMESTAMP()),
  ('image','Image','IHSN','Image schema based on DCMI and IPTC',
   1,'active','', 'image-schema.json',
   '["dcmi-schema.json","iptc-pmd-schema.json","iptc-phovidmdshared-schema.json"]',
   '{"core_fields":{"idno":"image_description.idno","title":"image_description.dcmi.title"}}',
   '',
   UNIX_TIMESTAMP()),
  ('custom','Custom','IHSN','Catch-all schema for custom content',
   1,'active','', 'custom-schema.json',
   '[]',
   '{"core_fields":{"idno":"/identification/idno","title":"/identification/title"}}',
   '',
   UNIX_TIMESTAMP());


-- update editor_projects data type column

-- replace 'survey' with 'microdata'
UPDATE `editor_projects` SET `type` = 'microdata' WHERE `type` = 'survey';

-- replace 'timeseries' with 'indicator'
UPDATE `editor_projects` SET `type` = 'indicator' WHERE `type` = 'timeseries';

-- replace 'timeseries-db' with 'indicator-db'
UPDATE `editor_projects` SET `type` = 'indicator-db' WHERE `type` = 'timeseries-db';
