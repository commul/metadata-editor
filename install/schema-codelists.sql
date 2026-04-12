-- Global codelists (simplified codelists row; SDMX/lifecycle/source fields deferred for later)
CREATE TABLE codelists (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  agency VARCHAR(50) NOT NULL,
  codelist_id VARCHAR(150) NOT NULL,
  version VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  uri VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  changed_at TIMESTAMP NULL,
  created_by INT NULL,
  changed_by INT NULL,
  UNIQUE KEY uq_codelist_identity (agency, codelist_id, version),
  KEY idx_agency (agency),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Multilingual labels for codelists
CREATE TABLE codelist_labels (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  codelist_id BIGINT NOT NULL,
  language VARCHAR(10) NOT NULL,  -- e.g. en, fr, es, ar
  label VARCHAR(500) NOT NULL,
  description TEXT,
  FOREIGN KEY (codelist_id) REFERENCES codelists(id) ON DELETE CASCADE,
  UNIQUE KEY uq_codelist_language (codelist_id, language),
  KEY idx_language (language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE codelist_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  codelist_id BIGINT NOT NULL,
  code VARCHAR(150) NOT NULL,
  parent_id BIGINT NULL,

  sort_order INT NULL,

  FOREIGN KEY (codelist_id) REFERENCES codelists(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES codelist_items(id) ON DELETE SET NULL,

  UNIQUE KEY uq_item_per_list (codelist_id, code),
  KEY idx_parent_id (parent_id),
  KEY idx_sort (codelist_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE codelist_items_labels (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  codelist_item_id BIGINT NOT NULL,
  language VARCHAR(10) NOT NULL,  -- e.g. en, fr, es, ar
  label VARCHAR(500) NOT NULL,
  description TEXT,
  FOREIGN KEY (codelist_item_id) REFERENCES codelist_items(id) ON DELETE CASCADE,
  UNIQUE KEY uq_codelist_item_language (codelist_item_id, language),
  KEY idx_language (language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
