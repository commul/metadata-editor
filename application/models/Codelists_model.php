<?php

/**
 * 
 * Global Codelists Model
 * 
 * Manages global codelists with support for codes and multilingual labels
 * 
 */
class Codelists_model extends CI_Model {

    private $table_codelists = 'codelists';
    private $table_items = 'codelist_items';
    private $table_item_labels = 'codelist_items_labels';
    /** Header translations: multilingual name/description per codelist (FK codelist_id → codelists.id) */
    private $table_codelist_translations = 'codelist_labels';

    private $codelist_fields = array(
        'agency',
        'codelist_id',
        'version',
        'name',
        'description',
        'uri',
        'created_at',
        'changed_at',
        'created_by',
        'changed_by'
    );

    private $item_fields = array(
        'codelist_id',
        'code',
        'parent_id',
        'sort_order'
    );

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('date');
    }

    /**
     * 
     * Get all codelists with optional filters
     * 
     * @param array $filters - Optional filters (agency, search)
     * @param int $offset - Offset for pagination (default: 0)
     * @param int $limit - Limit for pagination (default: null, returns all)
     * @param string $order_by - Order by field (default: 'created_at')
     * @param string $order_dir - Order direction (default: 'DESC')
     * @return array List of codelists
     * 
     */
    public function get_all($filters = array(), $offset = 0, $limit = null, $order_by = 'created_at', $order_dir = 'DESC')
    {
        $this->db->select('*');
        $this->db->from($this->table_codelists);

        // Apply filters
        if (isset($filters['agency']) && !empty($filters['agency'])) {
            $this->db->where('agency', $filters['agency']);
        }
        if (isset($filters['search']) && !empty($filters['search'])) {
            $this->db->group_start();
            $this->db->like('name', $filters['search']);
            $this->db->or_like('codelist_id', $filters['search']);
            $this->db->or_like('description', $filters['search']);
            $this->db->group_end();
        }

        // Ordering
        $this->db->order_by($order_by, $order_dir);

        // Pagination
        if ($limit !== null && $limit > 0) {
            $this->db->limit($limit, $offset);
        }

        return $this->db->get()->result_array();
    }

    /**
     * 
     * Get a single codelist by ID
     * 
     * @param int $id - Codelist ID
     * @return array|false Codelist or false if not found
     * 
     */
    public function get_by_id($id)
    {
        $this->db->where('id', $id);
        $codelist = $this->db->get($this->table_codelists)->row_array();

        if (!$codelist) {
            return false;
        }

        return $codelist;
    }

    /**
     * 
     * Get a codelist by agency, codelist_id, and version
     * 
     * @param string $agency - Agency identifier
     * @param string $codelist_id - Codelist identifier
     * @param string $version - Version string
     * @return array|false Codelist or false if not found
     * 
     */
    public function get_by_identity($agency, $codelist_id, $version)
    {
        $this->db->where('agency', $agency);
        $this->db->where('codelist_id', $codelist_id);
        $this->db->where('version', $version);
        $codelist = $this->db->get($this->table_codelists)->row_array();

        if (!$codelist) {
            return false;
        }

        return $codelist;
    }

    /**
     * 
     * Create a new codelist
     * 
     * @param array $data - Codelist data
     * @return int|false Inserted codelist ID or false on failure
     * 
     */
    public function create($data)
    {
        // Remove deprecated field name if present
        if (isset($data['access_mode'])) {
            unset($data['access_mode']);
        }
        
        // Filter to allowed fields
        $insert_data = array();
        foreach ($this->codelist_fields as $field) {
            if (isset($data[$field])) {
                $insert_data[$field] = $data[$field];
            }
        }

        // Set timestamps
        if (!isset($insert_data['created_at'])) {
            $insert_data['created_at'] = date('Y-m-d H:i:s');
        }

        if ($this->db->insert($this->table_codelists, $insert_data)) {
            $id = $this->db->insert_id();
            $this->seed_default_codelist_translation($id, $insert_data);
            return $id;
        }

        return false;
    }

    /**
     * Insert default English row in codelist_labels so item labels work without an extra user step.
     *
     * @param int $codelist_pk
     * @param array $insert_data Row just inserted into codelists
     */
    private function seed_default_codelist_translation($codelist_pk, $insert_data)
    {
        $name = isset($insert_data['name']) ? trim((string) $insert_data['name']) : '';
        if ($name === '') {
            $name = '-';
        }
        if (function_exists('mb_substr')) {
            $label = mb_substr($name, 0, 500);
        } else {
            $label = substr($name, 0, 500);
        }

        $desc = null;
        if (isset($insert_data['description']) && $insert_data['description'] !== '' && $insert_data['description'] !== null) {
            $desc = $insert_data['description'];
        }

        if (!$this->set_codelist_translation($codelist_pk, 'en', $label, $desc)) {
            log_message('error', 'Codelists_model: failed to seed en translation for codelist id ' . $codelist_pk);
        }
    }

    /**
     * 
     * Update an existing codelist
     * 
     * @param int $id - Codelist ID
     * @param array $data - Codelist data to update
     * @return bool Success status
     * 
     */
    public function update($id, $data)
    {
        // Remove deprecated field name if present
        if (isset($data['access_mode'])) {
            unset($data['access_mode']);
        }
        
        // Filter to allowed fields
        $update_data = array();
        foreach ($this->codelist_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
            }
        }

        if (empty($update_data)) {
            return false;
        }

        $this->db->where('id', $id);
        return $this->db->update($this->table_codelists, $update_data);
    }

    /**
     * 
     * Delete a codelist (cascades to codes and labels)
     * 
     * @param int $id - Codelist ID
     * @return bool Success status
     * 
     */
    public function delete($id)
    {
        $this->db->where('id', $id);
        return $this->db->delete($this->table_codelists);
    }

    /**
     * Translations for the codelist header (codelist_labels table).
     *
     * @param int $codelist_pk Primary key of codelists row
     * @return array
     */
    public function get_codelist_translations($codelist_pk)
    {
        $this->db->where('codelist_id', $codelist_pk);
        $this->db->order_by('language', 'ASC');
        return $this->db->get($this->table_codelist_translations)->result_array();
    }

    /**
     * @param int $translation_row_id
     * @return array|false
     */
    public function get_codelist_translation_by_id($translation_row_id)
    {
        $this->db->where('id', $translation_row_id);
        return $this->db->get($this->table_codelist_translations)->row_array();
    }

    /**
     * Upsert one header translation row (unique per codelist + language).
     *
     * @param int $codelist_pk
     * @return int|false Label row id
     */
    public function set_codelist_translation($codelist_pk, $language, $label, $description = null)
    {
        $this->db->where('codelist_id', $codelist_pk);
        $this->db->where('language', $language);
        $existing = $this->db->get($this->table_codelist_translations)->row_array();

        $row = array(
            'codelist_id' => $codelist_pk,
            'language' => $language,
            'label' => $label,
            'description' => $description
        );

        if ($existing) {
            $this->db->where('id', $existing['id']);
            if ($this->db->update($this->table_codelist_translations, $row)) {
                return (int) $existing['id'];
            }
            return false;
        }

        if ($this->db->insert($this->table_codelist_translations, $row)) {
            return (int) $this->db->insert_id();
        }

        return false;
    }

    /**
     * @param int $translation_row_id
     * @return bool
     */
    public function delete_codelist_translation($translation_row_id)
    {
        $this->db->where('id', $translation_row_id);
        return $this->db->delete($this->table_codelist_translations);
    }

    /**
     * Whether a language code is configured on the codelist (item labels must use only these).
     *
     * @param int $codelist_pk
     * @param string $language
     * @return bool
     */
    public function codelist_has_language($codelist_pk, $language)
    {
        $this->db->from($this->table_codelist_translations);
        $this->db->where('codelist_id', $codelist_pk);
        $this->db->where('language', $language);
        return $this->db->count_all_results() > 0;
    }

    /**
     * Get codes for a codelist with optional search and pagination.
     *
     * New optional params are appended so all existing callers remain unaffected.
     *
     * @param int         $codelist_id
     * @param string|null $language       Filter labels to this language (null = all)
     * @param bool        $include_labels Attach label rows to each code
     * @param string|null $search         LIKE filter on code value or any label text
     * @param int         $offset         Pagination offset (default 0)
     * @param int|null    $limit          Page size (null = no limit)
     * @return array
     */
    public function get_codes($codelist_id, $language = null, $include_labels = true, $search = null, $offset = 0, $limit = null)
    {
        $this->db->select('cc.*');
        $this->db->from($this->table_items . ' cc');
        $this->db->where('cc.codelist_id', $codelist_id);

        if ($search !== null && $search !== '') {
            $safe = $this->db->escape_like_str($search);
            $this->db->group_start();
            $this->db->like('cc.code', $search);
            // Match codes whose label text (any language) contains the search term.
            $this->db->or_where("cc.id IN (SELECT codelist_item_id FROM {$this->table_item_labels} WHERE label LIKE '%{$safe}%')", null, false);
            $this->db->group_end();
        }

        $this->db->order_by('cc.sort_order', 'ASC');
        $this->db->order_by('cc.code', 'ASC');

        if ($limit !== null && $limit > 0) {
            $this->db->limit((int) $limit, (int) $offset);
        }

        $codes = $this->db->get()->result_array();

        if ($include_labels && !empty($codes)) {
            $code_ids = array_column($codes, 'id');

            $this->db->select('*');
            $this->db->from($this->table_item_labels);
            $this->db->where_in('codelist_item_id', $code_ids);

            if ($language !== null) {
                $this->db->where('language', $language);
            }

            $labels = $this->db->get()->result_array();

            $labels_by_code = array();
            foreach ($labels as $label) {
                $labels_by_code[$label['codelist_item_id']][] = $label;
            }

            foreach ($codes as &$code) {
                $code['labels'] = isset($labels_by_code[$code['id']]) ? $labels_by_code[$code['id']] : array();
            }
        }

        return $codes;
    }

    /**
     * Count codes for a codelist, applying the same optional search filter as get_codes().
     *
     * @param int         $codelist_id
     * @param string|null $search
     * @return int
     */
    public function count_codes($codelist_id, $search = null)
    {
        $this->db->from($this->table_items . ' cc');
        $this->db->where('cc.codelist_id', $codelist_id);

        if ($search !== null && $search !== '') {
            $safe = $this->db->escape_like_str($search);
            $this->db->group_start();
            $this->db->like('cc.code', $search);
            $this->db->or_where("cc.id IN (SELECT codelist_item_id FROM {$this->table_item_labels} WHERE label LIKE '%{$safe}%')", null, false);
            $this->db->group_end();
        }

        return (int) $this->db->count_all_results();
    }

    /**
     * 
     * Get a single code by ID
     * 
     * @param int $code_id - Code ID
     * @param string $language - Optional language for labels
     * @return array|false Code with labels or false if not found
     * 
     */
    public function get_code_by_id($code_id, $language = null)
    {
        $this->db->where('id', $code_id);
        $code = $this->db->get($this->table_items)->row_array();

        if (!$code) {
            return false;
        }

        // Get labels
        $this->db->where('codelist_item_id', $code_id);
        if ($language !== null) {
            $this->db->where('language', $language);
        }
        $code['labels'] = $this->db->get($this->table_item_labels)->result_array();

        return $code;
    }

    /**
     * 
     * Add a code to a codelist
     * 
     * @param int $codelist_id - Codelist ID
     * @param array $data - Code data
     * @return int|false Inserted code ID or false on failure
     * 
     */
    public function add_code($codelist_id, $data)
    {
        // Filter to allowed fields
        $insert_data = array('codelist_id' => $codelist_id);
        foreach ($this->item_fields as $field) {
            if (isset($data[$field]) && $field !== 'codelist_id') {
                $insert_data[$field] = $data[$field];
            }
        }

        if ($this->db->insert($this->table_items, $insert_data)) {
            return $this->db->insert_id();
        }

        return false;
    }

    /**
     * 
     * Update an existing code
     * 
     * @param int $code_id - Code ID
     * @param array $data - Code data to update
     * @return bool Success status
     * 
     */
    public function update_code($code_id, $data)
    {
        // Filter to allowed fields
        $update_data = array();
        foreach ($this->item_fields as $field) {
            if (isset($data[$field]) && $field !== 'codelist_id') {
                $update_data[$field] = $data[$field];
            }
        }

        if (empty($update_data)) {
            return false;
        }

        $this->db->where('id', $code_id);
        return $this->db->update($this->table_items, $update_data);
    }

    /**
     * 
     * Delete a code (cascades to labels)
     * 
     * @param int $code_id - Code ID
     * @return bool Success status
     * 
     */
    public function delete_code($code_id)
    {
        // Get codelist_id before deletion
        $code = $this->get_code_by_id($code_id);
        if (!$code) {
            return false;
        }
        $codelist_id = $code['codelist_id'];

        $this->db->where('id', $code_id);
        $result = $this->db->delete($this->table_items);

        return $result;
    }

    /**
     * 
     * Add or update a label for a code
     * 
     * @param int $code_id - Code ID
     * @param string $language - Language code
     * @param string $label - Label text
     * @param string $description - Optional description
     * @return int|false Label ID or false on failure
     * 
     */
    public function set_code_label($code_id, $language, $label, $description = null)
    {
        // Check if label exists
        $this->db->where('codelist_item_id', $code_id);
        $this->db->where('language', $language);
        $existing = $this->db->get($this->table_item_labels)->row_array();

        $label_data = array(
            'codelist_item_id' => $code_id,
            'language' => $language,
            'label' => $label,
            'description' => $description
        );

        if ($existing) {
            // Update existing
            $this->db->where('id', $existing['id']);
            if ($this->db->update($this->table_item_labels, $label_data)) {
                return $existing['id'];
            }
        } else {
            // Insert new
            if ($this->db->insert($this->table_item_labels, $label_data)) {
                return $this->db->insert_id();
            }
        }

        return false;
    }

    /**
     * 
     * Delete a label for a code
     * 
     * @param int $label_id - Label ID
     * @return bool Success status
     * 
     */
    public function delete_code_label($label_id)
    {
        $this->db->where('id', $label_id);
        return $this->db->delete($this->table_item_labels);
    }

    /**
     * 
     * Get codelist count with optional filters
     * 
     * @param array $filters - Optional filters
     * @return int Count of codelists
     * 
     */
    public function count($filters = array())
    {
        $this->db->from($this->table_codelists);

        // Apply same filters as get_all
        if (isset($filters['agency']) && !empty($filters['agency'])) {
            $this->db->where('agency', $filters['agency']);
        }
        if (isset($filters['search']) && !empty($filters['search'])) {
            $this->db->group_start();
            $this->db->like('name', $filters['search']);
            $this->db->or_like('codelist_id', $filters['search']);
            $this->db->or_like('description', $filters['search']);
            $this->db->group_end();
        }

        return $this->db->count_all_results();
    }

    /**
     * 
     * Get hierarchical structure of codes (parent-child relationships)
     * 
     * @param int $codelist_id - Codelist ID
     * @param string $language - Optional language for labels
     * @return array Hierarchical structure with children arrays
     * 
     */
    public function get_hierarchical_structure($codelist_id, $language = null)
    {
        $codes = $this->get_codes($codelist_id, $language, true);
        if (empty($codes)) {
            return array();
        }

        $nodes = array();
        foreach ($codes as $code) {
            $code['children'] = array();
            $nodes[$code['id']] = $code;
        }

        $tree = array();
        foreach ($nodes as $id => &$node) {
            $pid = !empty($node['parent_id']) ? (int) $node['parent_id'] : 0;
            if ($pid && isset($nodes[$pid])) {
                $nodes[$pid]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);

        return $tree;
    }

    /**
     * Import one codelist from SdmxCodelistImporter parse row (non-SDMX callers should not use).
     *
     * @param array $row Parsed codelist (agency, codelist_id, version, name, description, uri, names, descriptions, codes[])
     * @param array $options dry_run (bool), replace_existing (bool), created_by (int|null)
     * @return array ok, action (created|skipped|dry_run|error), message?, id?, codes_imported?, warnings[]
     */
    public function import_sdmx_codelist(array $row, array $options = array())
    {
        $warnings = array();
        $dry = !empty($options['dry_run']);
        $replace = !empty($options['replace_existing']);
        $created_by = isset($options['created_by']) ? $options['created_by'] : null;

        $agency = isset($row['agency']) ? trim((string) $row['agency']) : '';
        $cid = isset($row['codelist_id']) ? trim((string) $row['codelist_id']) : '';
        $ver = isset($row['version']) ? trim((string) $row['version']) : '';
        if ($cid === '') {
            return array(
                'ok' => false,
                'action' => 'error',
                'message' => 'Missing codelist_id',
                'warnings' => $warnings,
            );
        }

        $existing = $this->get_by_identity($agency, $cid, $ver);
        if ($existing) {
            if (!$replace) {
                return array(
                    'ok' => true,
                    'action' => 'skipped',
                    'message' => 'Already exists (pass replace=1 to overwrite)',
                    'agency' => $agency,
                    'codelist_id' => $cid,
                    'version' => $ver,
                    'warnings' => $warnings,
                );
            }
            if (!$dry) {
                $this->delete((int) $existing['id']);
            }
        }

        $codes = isset($row['codes']) && is_array($row['codes']) ? $row['codes'] : array();
        if ($dry) {
            return array(
                'ok' => true,
                'action' => 'dry_run',
                'agency' => $agency,
                'codelist_id' => $cid,
                'version' => $ver,
                'codes_count' => count($codes),
                'warnings' => $warnings,
            );
        }

        $this->db->trans_start();

        $insert = array(
            'agency' => $agency,
            'codelist_id' => $cid,
            'version' => $ver,
            'name' => isset($row['name']) ? $this->_sdmx_truncate((string) $row['name'], 255) : $this->_sdmx_truncate($cid, 255),
            'description' => isset($row['description']) && $row['description'] !== '' ? $row['description'] : null,
            'uri' => isset($row['uri']) && $row['uri'] !== '' ? $this->_sdmx_truncate((string) $row['uri'], 500) : null,
            'created_by' => $created_by,
            'changed_by' => $created_by,
        );

        $pk = $this->create($insert);
        if (!$pk) {
            $this->db->trans_rollback();
            return array(
                'ok' => false,
                'action' => 'error',
                'message' => 'Failed to create codelist',
                'warnings' => $warnings,
            );
        }

        $this->_sdmx_sync_codelist_header_languages((int) $pk, $row, $codes, $warnings);

        $map = $this->_sdmx_import_codes_and_labels((int) $pk, $codes, $warnings);

        $this->db->trans_complete();
        if ($this->db->trans_status() === false) {
            return array(
                'ok' => false,
                'action' => 'error',
                'message' => 'Transaction failed',
                'warnings' => $warnings,
            );
        }

        return array(
            'ok' => true,
            'action' => 'created',
            'id' => (int) $pk,
            'agency' => $agency,
            'codelist_id' => $cid,
            'version' => $ver,
            'codes_imported' => count($map),
            'warnings' => $warnings,
        );
    }

    /**
     * @param int   $codelist_pk
     * @param array $row
     * @param array $codes
     * @param array $warnings
     */
    private function _sdmx_sync_codelist_header_languages($codelist_pk, array $row, array $codes, array &$warnings)
    {
        $names = isset($row['names']) && is_array($row['names']) ? $row['names'] : array();
        $descs = isset($row['descriptions']) && is_array($row['descriptions']) ? $row['descriptions'] : array();
        $langs = $this->_sdmx_collect_iso_langs($names, $descs);
        foreach ($codes as $c) {
            $cn = isset($c['names']) && is_array($c['names']) ? $c['names'] : array();
            $cd = isset($c['descriptions']) && is_array($c['descriptions']) ? $c['descriptions'] : array();
            $langs = array_unique(array_merge($langs, $this->_sdmx_collect_iso_langs($cn, $cd)));
        }

        $fallbackName = isset($row['name']) ? trim((string) $row['name']) : '';
        if ($fallbackName === '') {
            $fallbackName = isset($row['codelist_id']) ? $row['codelist_id'] : '-';
        }

        foreach ($langs as $lang) {
            $label = isset($names[$lang]) ? trim((string) $names[$lang]) : '';
            if ($label === '') {
                $label = $this->_sdmx_first_label_for_lang($codes, $lang);
            }
            if ($label === '') {
                $label = $fallbackName;
            }
            $label = $this->_sdmx_truncate($label, 500);
            $desc = isset($descs[$lang]) ? $descs[$lang] : null;
            if ($desc !== null && $desc !== '') {
                $desc = (string) $desc;
            } else {
                $desc = null;
            }
            if (!$this->set_codelist_translation($codelist_pk, $lang, $label, $desc)) {
                $warnings[] = 'Failed to set codelist header language ' . $lang;
            }
        }
    }

    /**
     * @param array $names
     * @param array $descriptions
     * @return string[]
     */
    private function _sdmx_collect_iso_langs(array $names, array $descriptions)
    {
        $this->load->config('iso_languages');
        $iso = $this->config->item('iso_languages');
        if (!is_array($iso)) {
            return array();
        }
        $out = array();
        foreach (array_keys($names) as $l) {
            if ($l !== 'und' && array_key_exists($l, $iso)) {
                $out[] = $l;
            }
        }
        foreach (array_keys($descriptions) as $l) {
            if ($l !== 'und' && array_key_exists($l, $iso) && !in_array($l, $out, true)) {
                $out[] = $l;
            }
        }
        return $out;
    }

    /**
     * @param array  $codes
     * @param string $lang
     * @return string
     */
    private function _sdmx_first_label_for_lang(array $codes, $lang)
    {
        foreach ($codes as $c) {
            $names = isset($c['names']) && is_array($c['names']) ? $c['names'] : array();
            if (isset($names[$lang]) && trim((string) $names[$lang]) !== '') {
                return trim((string) $names[$lang]);
            }
        }
        return '';
    }

    /**
     * @param int   $codelist_pk
     * @param array $codes
     * @param array $warnings
     * @return array<string,int> code => item id
     */
    private function _sdmx_import_codes_and_labels($codelist_pk, array $codes, array &$warnings)
    {
        $map = array();
        foreach ($codes as $c) {
            $codeStr = isset($c['code']) ? trim((string) $c['code']) : '';
            if ($codeStr === '') {
                continue;
            }
            $codeStr = $this->_sdmx_truncate($codeStr, 150);
            $so = null;
            if (isset($c['sort_order']) && $c['sort_order'] !== '' && $c['sort_order'] !== null) {
                $so = (int) $c['sort_order'];
            }
            $id = $this->add_code($codelist_pk, array(
                'code' => $codeStr,
                'parent_id' => null,
                'sort_order' => $so,
            ));
            if (!$id) {
                $warnings[] = 'Skipped duplicate or invalid code: ' . $codeStr;
                continue;
            }
            $map[$codeStr] = (int) $id;
        }

        foreach ($codes as $c) {
            $codeStr = isset($c['code']) ? trim((string) $c['code']) : '';
            if ($codeStr === '') {
                continue;
            }
            $codeStr = $this->_sdmx_truncate($codeStr, 150);
            if (!isset($map[$codeStr])) {
                continue;
            }
            $parentCode = isset($c['parent_code']) ? trim((string) $c['parent_code']) : '';
            if ($parentCode === '') {
                continue;
            }
            if (!isset($map[$parentCode])) {
                $warnings[] = 'Unknown parent code "' . $parentCode . '" for "' . $codeStr . '"';
                continue;
            }
            $this->update_code($map[$codeStr], array('parent_id' => $map[$parentCode]));
        }

        $this->load->config('iso_languages');
        $iso = $this->config->item('iso_languages');

        foreach ($codes as $c) {
            $codeStr = isset($c['code']) ? trim((string) $c['code']) : '';
            if ($codeStr === '') {
                continue;
            }
            $codeStr = $this->_sdmx_truncate($codeStr, 150);
            if (!isset($map[$codeStr])) {
                continue;
            }
            $itemId = $map[$codeStr];
            $names = isset($c['names']) && is_array($c['names']) ? $c['names'] : array();
            $descs = isset($c['descriptions']) && is_array($c['descriptions']) ? $c['descriptions'] : array();
            $langs = array_unique(array_merge(array_keys($names), array_keys($descs)));
            foreach ($langs as $lang) {
                if (!is_array($iso) || !array_key_exists($lang, $iso)) {
                    continue;
                }
                if (!$this->codelist_has_language($codelist_pk, $lang)) {
                    continue;
                }
                $lab = isset($names[$lang]) ? trim((string) $names[$lang]) : '';
                if ($lab === '') {
                    $lab = $codeStr;
                }
                $lab = $this->_sdmx_truncate($lab, 500);
                $d = isset($descs[$lang]) && $descs[$lang] !== '' ? (string) $descs[$lang] : null;
                $this->set_code_label($itemId, $lang, $lab, $d);
            }
        }

        foreach ($map as $codeStr => $itemId) {
            $this->db->where('codelist_item_id', (int) $itemId);
            $n = $this->db->count_all_results($this->table_item_labels);
            if ($n === 0 && $this->codelist_has_language($codelist_pk, 'en')) {
                $this->set_code_label((int) $itemId, 'en', $codeStr, null);
            }
        }

        return $map;
    }

    /**
     * @param string $s
     * @param int    $len
     * @return string
     */
    private function _sdmx_truncate($s, $len)
    {
        $s = (string) $s;
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $len, 'UTF-8');
        }
        return substr($s, 0, $len);
    }
}
