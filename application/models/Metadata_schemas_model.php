<?php

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Metadata schemas registry
 *
 * Handles CRUD operations for core and custom schemas that are stored
 * on disk with metadata persisted in the `metadata_schemas` table.
 */
class Metadata_schemas_model extends CI_Model
{
    private $table = 'metadata_schemas';

    private $fields = array(
        'uid',
        'title',
        'agency',
        'description',
        'is_core',
        'status',
        'storage_path',
        'filename',
        'schema_files',
        'metadata_options',
        'alias',
        'created',
        'created_by',
        'updated',
        'updated_by'
    );

    private $json_fields = array(
        'schema_files',
        'metadata_options'
    );

    private $core_base_path = null;
    private $custom_base_path = null;

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('file');
        $this->config->load('editor');
        $this->init_base_paths();
    }

    /**
     * Return all schemas with optional filters.
     *
     * $options = [
     *     'include_core' => true|false,
     *     'status'       => 'active'|'deprecated'|'draft',
     *     'search'       => 'text to search on uid/title/description'
     * ]
     */
    public function get_all($options = array())
    {
        if (isset($options['include_core']) && $options['include_core'] === false) {
            $this->db->where('is_core', 0);
        }

        if (!empty($options['status'])) {
            $this->db->where('status', $options['status']);
        }

        if (!empty($options['search'])) {
            $search = $options['search'];
            $this->db->group_start()
                ->like('uid', $search)
                ->or_like('title', $search)
                ->or_like('description', $search)
            ->group_end();
        }

        $this->db->order_by('is_core', 'DESC');
        //$this->db->order_by('uid', 'ASC');

        $rows = $this->db->get($this->table)->result_array();
        return $this->decode_rows($rows);
    }

    public function get_by_id($id)
    {
        $this->db->where('id', (int)$id);
        $row = $this->db->get($this->table)->row_array();
        return $this->decode_row($row);
    }

    public function get_by_uid($uid)
    {
        $this->db->where('uid', $uid);
        $row = $this->db->get($this->table)->row_array();

        if (!$row) {
            $this->db->where('alias', $uid);
            $row = $this->db->get($this->table)->row_array();
        }

        return $this->decode_row($row);
    }

    /**
     * Insert new schema metadata.
     *
     * Returns insert id.
     */
    public function insert($data)
    {
        $payload = $this->prepare_payload($data, $is_update = false);
        $this->db->insert($this->table, $payload);
        return $this->db->insert_id();
    }

    /**
     * Update existing schema metadata by id.
     */
    public function update($id, $data)
    {
        $schema = $this->get_by_id($id);

        if (!$schema) {
            throw new Exception("Schema not found: {$id}");
        }

        // Prevent modifications to core schemas except for select fields.
        if (!empty($schema['is_core'])) {
            // Allow updating metadata_options for core field mappings
            $allowed_core_fields = array('title', 'agency', 'description', 'status', 'metadata_options');
            $data = array_intersect_key($data, array_flip($allowed_core_fields));
        }

        $payload = $this->prepare_payload($data, $is_update = true);

        if (empty($payload)) {
            return true;
        }

        $this->db->where('id', (int)$id);
        return $this->db->update($this->table, $payload);
    }

    /**
     * Delete schema metadata by id.
     * Core schemas cannot be deleted.
     */
    public function delete($id)
    {
        $schema = $this->get_by_id($id);

        if (!$schema) {
            throw new Exception("Schema not found: {$id}");
        }

        if (!empty($schema['is_core'])) {
            throw new Exception("Core schemas cannot be deleted");
        }

        $this->db->where('id', (int)$id);
        return $this->db->delete($this->table);
    }

    /**
     * Return an associative array keyed by uid for quick lookups.
     */
    public function get_indexed_by_uid($options = array())
    {
        $rows = $this->get_all($options);
        $indexed = array();

        foreach ($rows as $row) {
            $indexed[$row['uid']] = $row;
        }

        return $indexed;
    }

    private function prepare_payload($data, $is_update = false)
    {
        $payload = array();
        $now = date('U');

        foreach ($data as $key => $value) {
            if (!in_array($key, $this->fields, true)) {
                continue;
            }

            if (in_array($key, $this->json_fields, true)) {
                if (is_array($value) || is_object($value)) {
                    $payload[$key] = json_encode($value);
                } elseif ($value === null && !$is_update) {
                    $payload[$key] = null;
                } elseif (is_string($value)) {
                    $payload[$key] = $value;
                }
                continue;
            }

            $payload[$key] = $value;
        }

        if (!$is_update) {
            if (!isset($payload['created'])) {
                $payload['created'] = $now;
            }
        } else {
            if (!array_key_exists('updated', $payload)) {
                $payload['updated'] = $now;
            }
        }

        return $payload;
    }

    private function decode_rows($rows)
    {
        if (empty($rows)) {
            return $rows;
        }

        foreach ($rows as $idx => $row) {
            $rows[$idx] = $this->decode_row($row);
        }

        return $rows;
    }

    private function decode_row($row)
    {
        if (!$row) {
            return $row;
        }

        foreach ($this->json_fields as $field) {
            if (isset($row[$field]) && !is_array($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = $decoded === null ? array() : $decoded;
            } elseif (!isset($row[$field])) {
                $row[$field] = array();
            }
        }

        if (isset($row['storage_path'])) {
            try {
                $row['storage_full_path'] = $this->resolve_schema_path($row);
            } catch (Exception $e) {
                $row['storage_full_path'] = null;
            }
        }

        if (isset($row['is_core'])) {
            $row['is_core'] = (int)$row['is_core'];
        }

        if (!isset($row['alias']) || $row['alias'] === null) {
            $row['alias'] = '';
        }

        return $row;
    }

    /**
     * Base path for core schemas (read-only, bundled with application).
     */
    public function get_core_base_path()
    {
        return $this->core_base_path;
    }

    /**
     * Base path for user-defined schemas (writable).
     */
    public function get_custom_base_path()
    {
        return $this->custom_base_path;
    }

    /**
     * Resolve absolute filesystem path for a schema entry.
     *
     * @param array|int $schema Array data or schema id
     * @return string Absolute path
     */
    public function resolve_schema_path($schema)
    {
        if (is_numeric($schema)) {
            $schema = $this->get_by_id($schema);
        }

        if (!is_array($schema)) {
            throw new Exception('Invalid schema reference');
        }

        if (!isset($schema['storage_path'])) {
            throw new Exception('Schema storage_path is not defined');
        }

        $base = !empty($schema['is_core']) ? $this->get_core_base_path() : $this->get_custom_base_path();
        return unix_path(rtrim($base, '/') . '/' . ltrim($schema['storage_path'], '/'));
    }

    /**
     * Get the full path to the main schema file for a given schema UID.
     * Handles aliases, filename mismatches, and fallbacks automatically.
     *
     * @param string $uid Schema UID (or alias)
     * @return string Full path to schema file
     * @throws Exception If schema not found or file doesn't exist
     */
    public function get_schema_file_path($uid)
    {
        // Resolve schema (handles aliases via get_by_uid)
        $schema = $this->get_by_uid($uid);
        
        if (!$schema) {
            throw new Exception("Schema not found for UID: {$uid}");
        }

        // Resolve schema directory
        $schema_dir = $this->resolve_schema_path($schema);

        // Try registered filename first
        $schema_filename = isset($schema['filename']) ? $schema['filename'] : null;
        if ($schema_filename) {
            $schema_path = unix_path($schema_dir . '/' . $schema_filename);
            if (file_exists($schema_path)) {
                return $schema_path;
            }
        }

        // Fallback to canonical UID filename (handles renamed files)
        $canonical_uid = isset($schema['uid']) ? $schema['uid'] : $uid;
        $fallback_filename = $canonical_uid . '-schema.json';
        $fallback_path = unix_path($schema_dir . '/' . $fallback_filename);
        
        if (file_exists($fallback_path)) {
            return $fallback_path;
        }

        // If both fail, try original uid as fallback (for aliases)
        if ($uid !== $canonical_uid) {
            $original_filename = $uid . '-schema.json';
            $original_path = unix_path($schema_dir . '/' . $original_filename);
            if (file_exists($original_path)) {
                return $original_path;
            }
        }

        // Last resort: list files in directory and find matching schema file
        if (is_dir($schema_dir)) {
            $files = scandir($schema_dir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                // Check if file matches schema pattern
                if (preg_match('/^' . preg_quote($canonical_uid, '/') . '[-_]?schema\.json$/i', $file)) {
                    $found_path = unix_path($schema_dir . '/' . $file);
                    if (file_exists($found_path)) {
                        return $found_path;
                    }
                }
            }
        }

        // All attempts failed
        $attempted_paths = array();
        if ($schema_filename) {
            $attempted_paths[] = unix_path($schema_dir . '/' . $schema_filename);
        }
        $attempted_paths[] = $fallback_path;
        if ($uid !== $canonical_uid) {
            $attempted_paths[] = unix_path($schema_dir . '/' . $uid . '-schema.json');
        }
        
        throw new Exception(
            "Schema file not found for '{$uid}' (canonical: '{$canonical_uid}'). " .
            "Tried: " . implode(', ', $attempted_paths)
        );
    }

    private function init_base_paths()
    {
        $core_path = unix_path(APPPATH . 'schemas');
        $this->core_base_path = rtrim($core_path, '/');
        if (!is_dir($this->core_base_path)) {
            @mkdir($this->core_base_path, 0777, true);
        }

        $editor_config = $this->config->item('editor');

        if (!is_array($editor_config)) {
            throw new Exception("Editor configuration not loaded.");
        }

        $custom_config = isset($editor_config['user_schema_path']) ? $editor_config['user_schema_path'] : null;

        if (!$custom_config) {
            $storage_path = isset($editor_config['storage_path']) ? $editor_config['storage_path'] : null;
            if ($storage_path) {
                $custom_config = rtrim($storage_path, '/') . '/user-schemas';
            }
        }

        if (!$custom_config) {
            throw new Exception("editor config 'user_schema_path' is not configured.");
        }

        $custom_config = unix_path($custom_config);
        $this->custom_base_path = rtrim($custom_config, '/');
        if (!is_dir($this->custom_base_path)) {
            if (!@mkdir($this->custom_base_path, 0777, true)) {
                throw new Exception("Failed to create user schema directory: " . $this->custom_base_path);
            }
        }
    }
}

