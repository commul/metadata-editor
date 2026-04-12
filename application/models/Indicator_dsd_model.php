<?php

use League\Csv\Reader;

/**
 * 
 * Indicator Data Structure Definition (DSD) Model
 * 
 * Manages data structure columns for indicator/timeseries projects
 * Similar to Editor_variable_model but for SDMX-compatible data structures
 * 
 */
class Indicator_dsd_model extends CI_Model {

    private $fields = array(
        'name',
        'label',
        'description',
        'sid',
        'data_type',
        'column_type',
        'time_period_format',
        'code_list',
        'code_list_reference',
        'metadata',
        'sum_stats',
        'codelist_type',
        'global_codelist_id',
        'local_codelist_id',
        'sort_order',
        'created',
        'changed',
        'created_by',
        'changed_by'
    );
 
    /**
     * Standard filename for indicator CSV data files
     */
    private $INDICATOR_DATA_FILENAME = 'indicator_data.csv';
    private $INDICATOR_FILE_ID = 'INDICATOR_DATA'; // Fixed file_id

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Editor_model');
    }

    /**
     * 
     * Get all DSD columns for a project
     * 
     * @param int $sid - Project ID
     * @param bool $metadata_detailed - Include detailed metadata
     * @param int $offset - Offset for pagination (default: 0)
     * @param int $limit - Limit for pagination (default: null, returns all)
     * @return array List of DSD columns
     * 
     **/
    function select_all($sid, $metadata_detailed = false, $offset = 0, $limit = null)
    {
        if ($metadata_detailed == true) {
            $fields = "id,sid,name,label,description,data_type,column_type,time_period_format,code_list,code_list_reference,metadata,sum_stats,codelist_type,global_codelist_id,local_codelist_id,sort_order";
        } else {
            $fields = "id,sid,name,label,description,data_type,column_type,time_period_format,code_list,code_list_reference,sum_stats,codelist_type,global_codelist_id,local_codelist_id,sort_order";
        }
        
        $this->db->select($fields);
        $this->db->where("sid", $sid);
        $this->db->order_by("sort_order,id", "asc");

        // Apply pagination if limit is specified
        if ($limit !== null && $limit > 0) {
            $this->db->limit($limit, $offset);
        }

        $columns = $this->db->get("indicator_dsd")->result_array();

        // Decode JSON columns
        foreach ($columns as $key => $column) {
            // Decode JSON fields
            if (isset($column['code_list']) && is_string($column['code_list'])) {
                $columns[$key]['code_list'] = json_decode($column['code_list'], true);
            }
            if (isset($column['code_list_reference']) && is_string($column['code_list_reference'])) {
                $columns[$key]['code_list_reference'] = json_decode($column['code_list_reference'], true);
            }
            if (isset($column['metadata']) && is_string($column['metadata'])) {
                $columns[$key]['metadata'] = json_decode($column['metadata'], true);
            }
            if (isset($columns[$key]['metadata']) && !is_array($columns[$key]['metadata'])) {
                $columns[$key]['metadata'] = array();
            }
            if (isset($columns[$key]['sum_stats']) && is_string($columns[$key]['sum_stats'])) {
                $decoded = json_decode($columns[$key]['sum_stats'], true);
                $columns[$key]['sum_stats'] = is_array($decoded) ? $decoded : null;
            } elseif (isset($columns[$key]['sum_stats']) && !is_array($columns[$key]['sum_stats'])) {
                $columns[$key]['sum_stats'] = null;
            }
        }

        return $columns;
    }

    /**
     * 
     * Get a single DSD column by ID
     * 
     * @param int $sid - Project ID
     * @param int $id - Column ID
     * @return array|false DSD column or false if not found
     * 
     **/
    function get_row($sid, $id)
    {
        $this->db->where("sid", $sid);
        $this->db->where("id", $id);
        $column = $this->db->get("indicator_dsd")->row_array();

        if (!$column) {
            return false;
        }

        // Decode JSON columns
        if (isset($column['code_list']) && is_string($column['code_list'])) {
            $column['code_list'] = json_decode($column['code_list'], true);
        }
        if (isset($column['code_list_reference']) && is_string($column['code_list_reference'])) {
            $column['code_list_reference'] = json_decode($column['code_list_reference'], true);
        }
        if (isset($column['metadata']) && is_string($column['metadata'])) {
            $column['metadata'] = json_decode($column['metadata'], true);
        }

        // Merge metadata if present
        if (isset($column['metadata']) && is_array($column['metadata'])) {
            $column_metadata = $column['metadata'];
            unset($column['metadata']);
            $column = array_merge($column, $column_metadata);
        }

        if (isset($column['sum_stats']) && is_string($column['sum_stats'])) {
            $decoded = json_decode($column['sum_stats'], true);
            $column['sum_stats'] = is_array($decoded) ? $decoded : null;
        } elseif (isset($column['sum_stats']) && !is_array($column['sum_stats'])) {
            $column['sum_stats'] = null;
        }

        return $column;
    }

    /**
     * 
     * Insert new DSD column
     * 
     * @param int $sid - Project ID
     * @param array $options - Column data
     * @param bool $validate_time_freq_rules When false, skip project-wide time_period / FREQ consistency check (internal writes).
     * @return int Inserted column ID
     * 
     **/
    public function insert($sid, $options, $validate_time_freq_rules = true)
    {
        $this->Editor_model->check_project_editable($sid);

        // Filter to allowed fields
        foreach ($options as $key => $value) {
            if (!in_array($key, $this->fields)) {
                unset($options[$key]);
            }
        }

        $options['sid'] = $sid;

        // Extract core fields from metadata if present and has actual data
        // Only extract if metadata contains the core fields (not just empty object)
        // Direct fields in $options take precedence over metadata fields
        if (isset($options['metadata']) && is_array($options['metadata']) && !empty($options['metadata'])) {
            // Check if metadata actually contains any core fields
            $has_core_fields = false;
            $core_field_names = array('name', 'label', 'description', 'data_type', 'column_type', 'time_period_format');
            foreach ($core_field_names as $field) {
                if (isset($options['metadata'][$field]) && $options['metadata'][$field] !== '' && $options['metadata'][$field] !== null) {
                    $has_core_fields = true;
                    break;
                }
            }
            
            if ($has_core_fields) {
                $core = $this->get_dsd_core_fields($options['metadata']);
                // Merge so direct fields ($options) overwrite metadata fields ($core)
                $options = array_merge($core, $options);
            }
        }

        if (isset($options['name']) && self::is_reserved_system_column_name($options['name'])) {
            throw new Exception('Column name cannot start with underscore (_); reserved for system fields.');
        }

        if ($validate_time_freq_rules) {
            $projected_new = array(
                'name' => isset($options['name']) ? $options['name'] : '',
                'column_type' => isset($options['column_type']) ? $options['column_type'] : 'dimension',
                'time_period_format' => array_key_exists('time_period_format', $options) ? $options['time_period_format'] : null,
                'metadata' => isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : array(),
            );
            $this->assert_project_time_period_freq_rules(array_merge($this->select_all($sid, true), array($projected_new)));
        }

        // Handle JSON columns - explicitly JSON encode arrays
        if (isset($options['code_list'])) {
            if (is_array($options['code_list']) && !empty($options['code_list'])) {
                $options['code_list'] = json_encode($options['code_list']);
            } elseif (is_array($options['code_list']) && empty($options['code_list'])) {
                // Empty array should be stored as JSON array, not null
                $options['code_list'] = json_encode(array());
            } else {
                $options['code_list'] = null;
            }
        }
        if (isset($options['code_list_reference'])) {
            if (is_array($options['code_list_reference']) || is_object($options['code_list_reference'])) {
                // Check if it's an empty object/array with only empty values
                $has_data = false;
                if (is_array($options['code_list_reference'])) {
                    foreach ($options['code_list_reference'] as $val) {
                        if (!empty($val)) {
                            $has_data = true;
                            break;
                        }
                    }
                } else {
                    foreach ((array)$options['code_list_reference'] as $val) {
                        if (!empty($val)) {
                            $has_data = true;
                            break;
                        }
                    }
                }
                if ($has_data) {
                    $options['code_list_reference'] = json_encode($options['code_list_reference']);
                } else {
                    $options['code_list_reference'] = null;
                }
            } else {
                $options['code_list_reference'] = null;
            }
        }
        if (isset($options['metadata'])) {
            if (is_array($options['metadata']) || is_object($options['metadata'])) {
                if (is_object($options['metadata'])) {
                    $options['metadata'] = (array) $options['metadata'];
                }
                if (!empty($options['metadata'])) {
                    $this->normalize_metadata_freq_key_for_storage($options['metadata']);
                }
                // Empty array/object should be stored as JSON
                if (empty($options['metadata'])) {
                    $options['metadata'] = json_encode(array());
                } else {
                    $options['metadata'] = json_encode($options['metadata']);
                }
            } else {
                $options['metadata'] = null;
            }
        }
        if (array_key_exists('sum_stats', $options)) {
            $options['sum_stats'] = $this->encode_sum_stats_for_db($options['sum_stats']);
        }

        // Ensure sort_order is an integer
        if (isset($options['sort_order'])) {
            $options['sort_order'] = (int)$options['sort_order'];
        } else {
            // Default to 0 if not provided
            $options['sort_order'] = 0;
        }

        // Set timestamps
        if (!isset($options['created'])) {
            $options['created'] = time();
        }

        $this->db->insert("indicator_dsd", $options);
        $insert_id = $this->db->insert_id();
        return $insert_id;
    }

    /**
     * 
     * Update existing DSD column
     * 
     * @param int $sid - Project ID
     * @param int $id - Column ID
     * @param array $options - Column data
     * @param bool $validate_time_freq_rules When false, skip project-wide time_period / FREQ consistency check (internal writes).
     * @return int Updated column ID
     * 
     **/
    public function update($sid, $id, $options, $validate_time_freq_rules = true)
    {
        $this->Editor_model->check_project_editable($sid);

        // Filter to allowed fields
        foreach ($options as $key => $value) {
            if (!in_array($key, $this->fields)) {
                unset($options[$key]);
            }
        }

        // Exclude sort_order from updates - it should be managed separately
        if (isset($options['sort_order'])) {
            unset($options['sort_order']);
        }

        $options['sid'] = $sid;

        // Extract core fields from metadata if present and has actual data
        // Only extract if metadata contains the core fields (not just empty object)
        // Direct fields in $options take precedence over metadata fields
        if (isset($options['metadata']) && is_array($options['metadata']) && !empty($options['metadata'])) {
            // Check if metadata actually contains any core fields
            $has_core_fields = false;
            $core_field_names = array('name', 'label', 'description', 'data_type', 'column_type', 'time_period_format');
            foreach ($core_field_names as $field) {
                if (isset($options['metadata'][$field]) && $options['metadata'][$field] !== '' && $options['metadata'][$field] !== null) {
                    $has_core_fields = true;
                    break;
                }
            }
            
            if ($has_core_fields) {
                $core = $this->get_dsd_core_fields($options['metadata']);
                // Merge so direct fields ($options) overwrite metadata fields ($core)
                $options = array_merge($core, $options);
            }
        }

        if (isset($options['name']) && self::is_reserved_system_column_name($options['name'])) {
            throw new Exception('Column name cannot start with underscore (_); reserved for system fields.');
        }

        // Ensure id is an integer
        $id = (int)$id;
        $sid = (int)$sid;

        // Check if record exists before updating
        $exists = $this->db->select('id')->where('sid', $sid)->where('id', $id)->get('indicator_dsd')->row_array();
        if (!$exists) {
            throw new Exception("Column with ID {$id} not found for project {$sid}");
        }

        $cols = $this->select_all($sid, true);
        $idx = -1;
        foreach ($cols as $i => $c) {
            if (isset($c['id']) && (int) $c['id'] === $id) {
                $idx = $i;
                break;
            }
        }
        if ($idx < 0) {
            throw new Exception("Column with ID {$id} not found for project {$sid}");
        }
        $merged = $cols[$idx];
        foreach ($options as $k => $v) {
            if (!in_array($k, $this->fields, true)) {
                continue;
            }
            if ($k === 'metadata' && is_array($v)) {
                $prev = isset($merged['metadata']) && is_array($merged['metadata']) ? $merged['metadata'] : array();
                $merged['metadata'] = array_merge($prev, $v);
            } else {
                $merged[$k] = $v;
            }
        }
        $cols[$idx] = $merged;
        if ($validate_time_freq_rules) {
            $this->assert_project_time_period_freq_rules($cols);
        }

        // Handle JSON columns - explicitly JSON encode arrays
        if (isset($options['code_list'])) {
            if (is_array($options['code_list'])) {
                $options['code_list'] = json_encode($options['code_list']);
            } else {
                $options['code_list'] = null;
            }
        }
        if (isset($options['code_list_reference'])) {
            if (is_array($options['code_list_reference']) || is_object($options['code_list_reference'])) {
                $options['code_list_reference'] = json_encode($options['code_list_reference']);
            } else {
                $options['code_list_reference'] = null;
            }
        }
        if (isset($options['metadata'])) {
            if (is_array($options['metadata']) || is_object($options['metadata'])) {
                if (is_object($options['metadata'])) {
                    $options['metadata'] = (array) $options['metadata'];
                }
                if (!empty($options['metadata'])) {
                    $this->normalize_metadata_freq_key_for_storage($options['metadata']);
                }
                // Empty array/object should be stored as JSON
                if (empty($options['metadata'])) {
                    $options['metadata'] = json_encode(array());
                } else {
                    $options['metadata'] = json_encode($options['metadata']);
                }
            } else {
                $options['metadata'] = null;
            }
        }
        if (array_key_exists('sum_stats', $options)) {
            $options['sum_stats'] = $this->encode_sum_stats_for_db($options['sum_stats']);
        }

        // Set changed timestamp
        $options['changed'] = time();

        // Remove 'id' from options if present (should not be updated)
        if (isset($options['id'])) {
            unset($options['id']);
        }

        // Ensure all text fields preserve empty strings (don't convert to null)
        // This ensures fields like 'label' with value "Name" are saved correctly
        $text_fields = array('name', 'label', 'description');
        foreach ($text_fields as $field) {
            // If field is set but is null, convert to empty string
            // This ensures the field is included in the update
            if (array_key_exists($field, $options) && $options[$field] === null) {
                $options[$field] = '';
            }
        }

        $this->db->where('sid', $sid);
        $this->db->where('id', $id);
        $this->db->update("indicator_dsd", $options);
        
        if ($this->db->affected_rows() === 0) {
            // No rows updated - this might indicate no changes, but log it
            log_message('debug', "Indicator DSD update: No rows affected for sid={$sid}, id={$id}. Options: " . json_encode($options));
        }
        
        return $id;
    }

    /**
     * 
     * Upsert DSD column (insert or update)
     * 
     * @param int $sid - Project ID
     * @param array $options - Column data (must include 'name' for lookup)
     * @return int Column ID
     * 
     **/
    /**
     * Normalize sum_stats for MySQL JSON column (insert/update).
     *
     * @param mixed $value array, JSON string, or null to clear
     * @return string|null JSON string or NULL for SQL
     */
    private function encode_sum_stats_for_db($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return empty($value) ? null : json_encode($value);
        }
        if (is_object($value)) {
            $arr = (array) $value;

            return empty($arr) ? null : json_encode($value);
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return null;
            }

            return json_encode($decoded);
        }

        return null;
    }

    /**
     * Raw row for import matching by primary key (no JSON decode).
     *
     * @param int $sid
     * @param int $id
     * @return array|null
     */
    private function get_indicator_dsd_row_by_id($sid, $id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $this->db->where('sid', (int) $sid);
        $this->db->where('id', $id);
        return $this->db->get('indicator_dsd')->row_array();
    }

    public function upsert($sid, $options)
    {
        if (!isset($options['name'])) {
            throw new Exception("Column name is required for upsert");
        }

        // Check if column exists by name
        $this->db->select("id");
        $this->db->where("sid", $sid);
        $this->db->where("name", $options['name']);
        $existing = $this->db->get("indicator_dsd")->row_array();

        if ($existing) {
            // Update existing
            return $this->update($sid, $existing['id'], $options);
        } else {
            // Insert new
            return $this->insert($sid, $options);
        }
    }

    /**
     * 
     * Delete DSD columns
     * 
     * @param int $sid - Project ID
     * @param array $id_list - Array of column IDs to delete
     * @return array Result with affected rows
     * 
     **/
    function delete($sid, $id_list)
    {
        if (empty($id_list) || !is_array($id_list)) {
            return array('rows' => 0);
        }

        $this->load->model('Local_codelists_model');
        foreach ($id_list as $dsd_id) {
            $this->Local_codelists_model->delete_list_by_field($sid, (int) $dsd_id);
        }

        $this->db->where('sid', $sid);
        $this->db->where_in('id', $id_list);
        $this->db->delete('indicator_dsd');
        
        return array(
            'rows' => $this->db->affected_rows(),
            'query' => $this->db->last_query()
        );
    }

    /**
     * 
     * Validate DSD column data
     * 
     * @param array $options - Column data
     * @param bool $is_new - Whether this is a new record
     * @return bool True if valid
     * @throws ValidationException If validation fails
     * 
     **/
    /**
     * Column names starting with "_" are reserved for system/DuckDB fields (e.g. _ts_freq).
     *
     * @param string $name
     * @return bool true if reserved / not allowed for user-defined DSD columns
     */
    public static function is_reserved_system_column_name($name)
    {
        $name = (string) $name;
        return $name !== '' && $name[0] === '_';
    }

    function validate($options, $is_new = true)
    {
        $this->load->library("form_validation");
        $this->form_validation->reset_validation();
        $this->form_validation->set_data($options);

        // Validation rules - all optional (no 'required' rule)
        // Only validate if field is present
        if (isset($options['name'])) {
            $this->form_validation->set_rules('name', 'Column name', 'xss_clean|trim|max_length[100]|regex_match[/^[a-zA-Z0-9_]*$/]');
            if (self::is_reserved_system_column_name($options['name'])) {
                throw new ValidationException('VALIDATION_ERROR: Column name cannot start with underscore (_); reserved for system fields.', array('name' => 'Reserved name'));
            }
        }
        if (isset($options['data_type'])) {
            $this->form_validation->set_rules('data_type', 'Data type', 'in_list[string,integer,float,double,date,boolean]');
        }
        if (isset($options['column_type'])) {
            $this->form_validation->set_rules('column_type', 'Column type', 'in_list[dimension,time_period,measure,attribute,indicator_id,indicator_name,annotation,geography,observation_value,periodicity]');
        }
        if (isset($options['time_period_format']) && $options['time_period_format'] !== '' && $options['time_period_format'] !== null) {
            $this->load->config('indicator_dsd', true);
            $tf_rows = $this->config->item('dsd_time_period_formats', 'indicator_dsd');
            $allowed_tf = array();
            foreach ((array) $tf_rows as $row) {
                if (!empty($row['code'])) {
                    $allowed_tf[] = $row['code'];
                }
            }
            if (count($allowed_tf) > 0) {
                $this->form_validation->set_rules('time_period_format', 'Time period format', 'in_list[' . implode(',', $allowed_tf) . ']');
            }
        }
        if (isset($options['codelist_type'])) {
            $this->form_validation->set_rules('codelist_type', 'Codelist type', 'in_list[none,global,local]');
        }

        if ($this->form_validation->run() == TRUE) {
            return TRUE;
        }

        // Failed
        $errors = $this->form_validation->error_array();
        $error_str = $this->form_validation->error_array_to_string($errors);
        throw new ValidationException("VALIDATION_ERROR: " . $error_str, $errors);
    }

    /**
     * 
     * Get core fields from metadata array
     * Extracts fields that should be stored in dedicated columns
     * 
     * @param array $column - Column metadata array
     * @return array Core fields
     * 
     **/
    private function get_dsd_core_fields($column)
    {
        // Extract only core fields that go into dedicated columns
        // code_list, code_list_reference, and other metadata stay in their respective JSON columns
        $core_fields = array(
            'name' => isset($column['name']) ? $column['name'] : '',
            'label' => isset($column['label']) ? $column['label'] : '',
            'description' => isset($column['description']) ? $column['description'] : '',
            'data_type' => isset($column['data_type']) ? $column['data_type'] : '',
            'column_type' => isset($column['column_type']) ? $column['column_type'] : '',
            'time_period_format' => isset($column['time_period_format']) ? $column['time_period_format'] : null
        );

        return $core_fields;
    }

    /**
     * Constant series SDMX FREQ on a time_period column: stored as metadata.freq; legacy metadata.import_freq_code still accepted when reading.
     *
     * @param array $meta
     * @return string|null trimmed code or null
     */
    private function resolve_metadata_constant_freq(array $meta)
    {
        if (isset($meta['freq']) && is_string($meta['freq'])) {
            $t = trim($meta['freq']);
            if ($t !== '') {
                return $t;
            }
        }
        if (isset($meta['import_freq_code']) && is_string($meta['import_freq_code'])) {
            $t = trim($meta['import_freq_code']);
            if ($t !== '') {
                return $t;
            }
        }

        return null;
    }

    /**
     * Before persisting indicator_dsd.metadata: use freq only, migrate import_freq_code -> freq, drop legacy key.
     *
     * @param array $meta
     */
    private function normalize_metadata_freq_key_for_storage(array &$meta)
    {
        $v = $this->resolve_metadata_constant_freq($meta);
        unset($meta['import_freq_code']);
        unset($meta['global_codelist_id'], $meta['global_freq_codelist_id']);
        if ($v !== null) {
            $meta['freq'] = $v;
        } else {
            unset($meta['freq']);
        }
    }

    /**
     * 
     * Import CSV file to create/update DSD columns
     * 
     * @param int $sid - Project ID
     * @param string $csv_file_path - Path to uploaded CSV file
     * @param array $column_mappings - Array of column mappings with action, columnType, dataType, label, etc.
     * @param int $overwrite_existing - Whether to overwrite existing columns (1) or not (0)
     * @param int $skip_existing - Whether to skip existing columns (1) or not (0)
     * @param int $user_id - User ID for created_by/changed_by
     * @return array Result with created, updated, skipped counts and errors
     * 
     **/
    /**
     * 
     * Validate indicator_id values in CSV match project IDNO
     * Stops early on first mismatch
     * 
     * @param string $csv_file_path - Path to CSV file
     * @param string $indicator_id_column - CSV column name for indicator_id
     * @param string $project_idno - Project IDNO to match against
     * @return void
     * @throws Exception if validation fails
     * 
     **/
    /**
     * Single CSV header cell → DuckDB/FastAPI-safe label: only letters, digits, underscore, hyphen.
     * Other characters (spaces, dots, slashes, parentheses, etc.) become underscores; runs collapse.
     *
     * @param string $name
     * @return string
     */
    private function normalize_csv_header_token($name)
    {
        $n = trim((string) $name);
        $n = preg_replace('/[^A-Za-z0-9_-]+/', '_', $n);
        $n = preg_replace('/_+/', '_', $n);
        $n = trim($n, '_-');

        return $n !== '' ? $n : 'column';
    }

    /**
     * Normalize a list of header tokens and ensure case-insensitive uniqueness (suffix _2, _3, …).
     *
     * @param array $raw_names Original header strings from CSV
     * @return array Normalized, unique column names
     */
    private function normalize_csv_column_names($raw_names)
    {
        $normalized = array();
        foreach ($raw_names as $name) {
            $normalized[] = $this->normalize_csv_header_token($name);
        }
        $seen = array();
        $out = array();
        foreach ($normalized as $name) {
            $key = strtoupper($name);
            if (isset($seen[$key])) {
                $suffix = 2;
                do {
                    $candidate = $name . '_' . $suffix;
                    $key = strtoupper($candidate);
                    $suffix++;
                } while (isset($seen[$key]));
                $name = $candidate;
            }
            $seen[$key] = true;
            $out[] = $name;
        }

        return $out;
    }

    /**
     * Whether a mapping csvColumn refers to a physical header after rewrite (exact or same sanitized token).
     *
     * @param string $mapping_csv_col Value from import UI
     * @param string $header_h Column name from file (already rewritten)
     * @return bool
     */
    private function mapping_csv_column_matches_header($mapping_csv_col, $header_h)
    {
        $a = trim((string) $mapping_csv_col);
        $b = trim((string) $header_h);
        if ($a !== '' && $b !== '' && strcasecmp($a, $b) === 0) {
            return true;
        }

        return strcasecmp(
            $this->normalize_csv_header_token($a),
            $this->normalize_csv_header_token($b)
        ) === 0;
    }

    /**
     * Rewrite first CSV line to FastAPI-safe headers (for staging upload / DuckDB import).
     *
     * @param string $csv_file_path Absolute path
     * @return string[] Normalized header names
     * @throws Exception
     */
    public function rewrite_indicator_csv_headers_for_duckdb($csv_file_path)
    {
        if (!is_readable($csv_file_path)) {
            throw new Exception('CSV file is not readable');
        }

        return $this->rewrite_csv_with_normalized_headers($csv_file_path);
    }

    /**
     * Normalize header names for optional dsd_columns passed to draft-queue (same rules as file rewrite).
     *
     * @param string[] $names
     * @return string[]
     */
    public function normalize_duckdb_staging_column_names(array $names)
    {
        $out = array();
        foreach ($names as $n) {
            $out[] = $this->normalize_csv_header_token($n);
        }

        return $out;
    }

    /**
     * Find length of the first CSV line in content (respects quoted fields with commas/newlines).
     *
     * @param string $content Full file content
     * @return array [length of first line including line ending, line ending string]
     */
    private function find_first_csv_line_length($content)
    {
        $len = strlen($content);
        $in_quotes = false;
        $i = 0;
        while ($i < $len) {
            $c = $content[$i];
            if ($c === '"') {
                if ($in_quotes && $i + 1 < $len && $content[$i + 1] === '"') {
                    $i += 2;
                    continue;
                }
                $in_quotes = !$in_quotes;
                $i++;
                continue;
            }
            if (!$in_quotes && ($c === "\n" || $c === "\r")) {
                $line_end_len = ($c === "\r" && $i + 1 < $len && $content[$i + 1] === "\n") ? 2 : 1;
                return array($i + $line_end_len, substr($content, $i, $line_end_len));
            }
            $i++;
        }
        return array($len, "\n");
    }

    /**
     * Build a single CSV line from values (quotes values that contain comma, quote, or newline).
     *
     * @param array $values
     * @return string
     */
    private function csv_line_from_values($values)
    {
        $out = array();
        foreach ($values as $v) {
            $v = (string) $v;
            if (strpbrk($v, ",\"\r\n") !== false) {
                $v = '"' . str_replace('"', '""', $v) . '"';
            }
            $out[] = $v;
        }
        return implode(',', $out);
    }

    /**
     * Rewrite only the first line of the CSV with FastAPI-safe column names (invalid chars -> underscores).
     * Rest of the file is unchanged. Faster than re-writing the entire file.
     *
     * @param string $csv_file_path Path to the CSV file
     * @return array Normalized headers (same length as original)
     */
    private function rewrite_csv_with_normalized_headers($csv_file_path)
    {
        $content = file_get_contents($csv_file_path);
        if ($content === false) {
            throw new Exception("Failed to read CSV file");
        }
        list($first_line_len, $line_ending) = $this->find_first_csv_line_length($content);
        $first_line = substr($content, 0, $first_line_len - strlen($line_ending));
        $rest = substr($content, $first_line_len);

        $headers = str_getcsv($first_line);
        if (empty($headers)) {
            throw new Exception("CSV file has no header row");
        }
        $normalized_headers = $this->normalize_csv_column_names($headers);
        $new_first_line = $this->csv_line_from_values($normalized_headers);

        $new_content = $new_first_line . $line_ending . $rest;
        if (file_put_contents($csv_file_path, $new_content) === false) {
            throw new Exception("Failed to rewrite CSV with normalized column names");
        }
        return $normalized_headers;
    }

    /**
     * Filter CSV to only rows where indicator_id column matches project IDNO (case-insensitive).
     * Overwrites the file with header + matching rows only, so the stored CSV stays clean.
     *
     * @param string $csv_file_path Path to the CSV file (with normalized headers)
     * @param string $indicator_id_column CSV column name for indicator_id
     * @param string $project_idno Project indicator IDNO to keep
     * @return int Number of rows kept
     * @throws Exception if no rows match or file cannot be written
     */
    private function filter_csv_to_matching_indicator_id($csv_file_path, $indicator_id_column, $project_idno)
    {
        if (empty($project_idno)) {
            throw new Exception("Indicator IDNO is not available");
        }

        $csv = Reader::createFromPath($csv_file_path, 'r');
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();
        $physical_indicator_col = null;
        foreach ($headers as $h) {
            if ($this->mapping_csv_column_matches_header($indicator_id_column, $h)) {
                $physical_indicator_col = $h;
                break;
            }
        }
        if ($physical_indicator_col === null) {
            throw new Exception("Indicator ID column '{$indicator_id_column}' not found in CSV headers");
        }
        $indicator_id_column = $physical_indicator_col;

        $project_idno_upper = strtoupper(trim($project_idno));

        $matching = array();
        foreach ($csv->getRecords() as $record) {
            $indicator_id = isset($record[$indicator_id_column]) ? trim($record[$indicator_id_column]) : '';
            if ($indicator_id !== '' && strtoupper($indicator_id) === $project_idno_upper) {
                $row = array();
                foreach ($headers as $h) {
                    $row[] = isset($record[$h]) ? $record[$h] : '';
                }
                $matching[] = $row;
            }
        }
        unset($csv);

        if (empty($matching)) {
            throw new Exception("No rows match indicator IDNO '{$project_idno}'. Please add at least one row with matching indicator ID, or check the indicator IDNO.");
        }

        $lines = array($this->csv_line_from_values($headers));
        foreach ($matching as $row) {
            $lines[] = $this->csv_line_from_values($row);
        }
        $content = implode("\n", $lines);
        if (file_put_contents($csv_file_path, $content) === false) {
            throw new Exception("Failed to write filtered CSV file");
        }
        return count($matching);
    }

    public function import_csv($sid, $csv_file_path, $column_mappings, $overwrite_existing = 0, $skip_existing = 0, $user_id = null, $indicator_idno = null, $required_field_label_columns = array())
    {
        $this->Editor_model->check_project_editable($sid);

        $result = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => array(),
            'rows_imported' => 0
        );

        if (!file_exists($csv_file_path)) {
            throw new Exception("CSV file not found");
        }

        // Normalize column names in the CSV (first line: FastAPI-safe identifiers) and overwrite file
        $headers = $this->rewrite_csv_with_normalized_headers($csv_file_path);

        // Validate column mappings match CSV headers (exact or same sanitized token as original header)
        foreach ($column_mappings as $mapping) {
            $found = false;
            foreach ($headers as $h) {
                if ($this->mapping_csv_column_matches_header($mapping['csvColumn'], $h)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $result['errors'][] = "Column '{$mapping['csvColumn']}' not found in CSV";
            }
        }

        if (!empty($result['errors'])) {
            return $result;
        }

        // Get project IDNO: use provided indicator_idno (from import form) when non-empty, else from project
        $project_idno = (!empty($indicator_idno) && is_string($indicator_idno)) ? trim($indicator_idno) : $this->Editor_model->get_project_idno_by_id($sid);
        
        // Find indicator_id mapping (REQUIRED)
        $indicator_id_mapping = null;
        foreach ($column_mappings as $mapping) {
            if (isset($mapping['columnType']) && $mapping['columnType'] === 'indicator_id') {
                $indicator_id_mapping = $mapping;
                break;
            }
        }
        
        // Validate indicator_id mapping exists
        if (!$indicator_id_mapping) {
            $result['errors'][] = "Indicator ID column mapping is required";
            return $result;
        }
        
        // Keep only rows where indicator_id matches project IDNO; rewrite CSV so stored file is clean
        try {
            $result['rows_imported'] = $this->filter_csv_to_matching_indicator_id(
                $csv_file_path,
                $indicator_id_mapping['csvColumn'],
                $project_idno
            );
        } catch (Exception $e) {
            $result['errors'][] = $e->getMessage();
            return $result; // Stop here, don't create columns
        }

        // Upload and store CSV file with standard name (singleton pattern)
        // This happens after validation but before processing columns
        try {
            $upload_result = $this->upload_indicator_csv($sid, $csv_file_path, $user_id);
            $result['file_id'] = $upload_result['file_id'];
            $result['file_name'] = $upload_result['file_name'];
        } catch (Exception $e) {
            $result['errors'][] = "Failed to store CSV file: " . $e->getMessage();
            return $result; // Stop here if file storage fails
        }

        // Get existing columns by name (compare uppercase for SDMX compatibility)
        $existing_columns = $this->select_all($sid, false);
        $existing_by_name = array();
        foreach ($existing_columns as $col) {
            $existing_by_name[strtoupper($col['name'])] = $col;
        }

        // Process each column mapping (only selected ones are sent)
        foreach ($column_mappings as $mapping) {
            try {
                $csv_column = $mapping['csvColumn'];
                // Use uppercase column name for SDMX compatibility
                $column_name = isset($mapping['columnName']) ? strtoupper(trim($mapping['columnName'])) : strtoupper($csv_column);
                
                // Validate column name
                if (empty($column_name)) {
                    $result['errors'][] = "Column name is required for '{$csv_column}'";
                    continue;
                }
                
                if (!preg_match('/^[A-Z0-9_]+$/', $column_name)) {
                    $result['errors'][] = "Invalid column name '{$column_name}'. Only uppercase letters, numbers, and underscores are allowed.";
                    continue;
                }
                
                if (strlen($column_name) > 255) {
                    $result['errors'][] = "Column name '{$column_name}' exceeds maximum length of 255 characters.";
                    continue;
                }

                if (self::is_reserved_system_column_name($column_name)) {
                    $result['errors'][] = "Column name '{$column_name}' cannot start with underscore (_); reserved for system fields.";
                    continue;
                }

                // Prefer stable DSD row id when client sends it (re-import after renames)
                $existing_column = null;
                $dsd_id = isset($mapping['dsdColumnId']) ? (int) $mapping['dsdColumnId'] : 0;
                if ($dsd_id > 0) {
                    $by_id = $this->get_indicator_dsd_row_by_id($sid, $dsd_id);
                    if ($by_id) {
                        $existing_column = $by_id;
                    }
                }
                if (!$existing_column) {
                    $exists = isset($existing_by_name[strtoupper($column_name)]);
                    $existing_column = $exists ? $existing_by_name[strtoupper($column_name)] : null;
                }
                $exists = (bool) $existing_column;

                // Handle existing columns
                if ($exists) {
                    if ($skip_existing) {
                        $result['skipped']++;
                        continue;
                    }
                    
                    if (!$overwrite_existing) {
                        $result['errors'][] = "Column '{$column_name}' already exists. Enable 'overwrite existing' to update.";
                        continue;
                    }

                    // Build metadata: load existing then set value_label_column from required_field_label_columns
                    $meta_row = $this->db->select('metadata')->where('id', $existing_column['id'])->where('sid', $sid)->get('indicator_dsd')->row_array();
                    $metadata = isset($meta_row['metadata']) ? json_decode($meta_row['metadata'], true) : array();
                    if (!is_array($metadata)) {
                        $metadata = array();
                    }
                    $col_type = isset($mapping['columnType']) ? $mapping['columnType'] : 'dimension';
                    if (!empty($required_field_label_columns[$col_type]) && is_string($required_field_label_columns[$col_type])) {
                        $metadata['value_label_column'] = trim($required_field_label_columns[$col_type]);
                    }
                    if (isset($mapping['metadata']) && is_array($mapping['metadata'])) {
                        $metadata = array_merge($metadata, $mapping['metadata']);
                    }
                    if ($col_type === 'time_period') {
                        if (!empty($mapping['timePeriodFreqCode']) && is_string($mapping['timePeriodFreqCode']) && trim($mapping['timePeriodFreqCode']) !== '') {
                            $metadata['freq'] = trim($mapping['timePeriodFreqCode']);
                        } else {
                            unset($metadata['freq']);
                        }
                        unset($metadata['import_freq_code']);
                    }
                    $this->normalize_metadata_freq_key_for_storage($metadata);

                    // Update existing column (include name so import mapping can rename; matched by dsdColumnId when sent)
                    $update_data = array(
                        'name' => $column_name,
                        'label' => isset($mapping['label']) ? $mapping['label'] : $column_name,
                        'description' => isset($mapping['description']) ? $mapping['description'] : '',
                        'data_type' => isset($mapping['dataType']) ? $mapping['dataType'] : 'string',
                        'column_type' => $col_type,
                        'time_period_format' => isset($mapping['timePeriodFormat']) ? $mapping['timePeriodFormat'] : null,
                        'code_list' => isset($mapping['codeList']) ? $mapping['codeList'] : null,
                        'code_list_reference' => isset($mapping['codeListReference']) ? $mapping['codeListReference'] : null,
                        'metadata' => $metadata,
                        'changed_by' => $user_id
                    );

                    $this->update($sid, $existing_column['id'], $update_data);
                    $result['updated']++;
                } else {
                    // Build metadata with value_label_column from required_field_label_columns
                    $metadata = isset($mapping['metadata']) && is_array($mapping['metadata']) ? $mapping['metadata'] : array();
                    $col_type = isset($mapping['columnType']) ? $mapping['columnType'] : 'dimension';
                    if (!empty($required_field_label_columns[$col_type]) && is_string($required_field_label_columns[$col_type])) {
                        $metadata['value_label_column'] = trim($required_field_label_columns[$col_type]);
                    }
                    if ($col_type === 'time_period') {
                        if (!empty($mapping['timePeriodFreqCode']) && is_string($mapping['timePeriodFreqCode']) && trim($mapping['timePeriodFreqCode']) !== '') {
                            $metadata['freq'] = trim($mapping['timePeriodFreqCode']);
                        } else {
                            unset($metadata['freq']);
                        }
                        unset($metadata['import_freq_code']);
                    }
                    $this->normalize_metadata_freq_key_for_storage($metadata);

                    // Create new column
                    $insert_data = array(
                        'name' => $column_name, // Already uppercase
                        'label' => isset($mapping['label']) ? $mapping['label'] : $column_name,
                        'description' => isset($mapping['description']) ? $mapping['description'] : '',
                        'data_type' => isset($mapping['dataType']) ? $mapping['dataType'] : 'string',
                        'column_type' => $col_type,
                        'time_period_format' => isset($mapping['timePeriodFormat']) ? $mapping['timePeriodFormat'] : null,
                        'code_list' => isset($mapping['codeList']) ? $mapping['codeList'] : null,
                        'code_list_reference' => isset($mapping['codeListReference']) ? $mapping['codeListReference'] : null,
                        'metadata' => $metadata,
                        'sort_order' => $this->get_max_sort_order($sid) + 1,
                        'created_by' => $user_id
                    );

                    $this->insert($sid, $insert_data);
                    $result['created']++;
                }
            } catch (Exception $e) {
                $result['errors'][] = "Error processing column '{$mapping['csvColumn']}': " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Get maximum sort_order for a project
     */
    private function get_max_sort_order($sid)
    {
        $this->db->select_max('sort_order');
        $this->db->where('sid', $sid);
        $result = $this->db->get('indicator_dsd')->row_array();
        return $result && $result['sort_order'] !== null ? (int)$result['sort_order'] : 0;
    }

    /**
     * Default FREQ code per time_period_format when no user FREQ column (from config indicator_dsd.php).
     *
     * @return array time_period_format => freq code
     */
    protected function get_dsd_default_freq_by_time_period_format_from_config()
    {
        $this->load->config('indicator_dsd', true);
        $map = $this->config->item('dsd_default_freq_by_time_period_format', 'indicator_dsd');

        return is_array($map) ? $map : array();
    }

    /**
     * Build optional time_spec for FastAPI promote (DuckDB _ts_year / _ts_freq).
     * Sent as JSON on POST timeseries/indicators/timeseries/import-queue when a time_period column exists.
     *
     * @param int $sid
     * @return array keys: time_column, time_period_format, default_freq_by_format; optional freq_column,
     *     implied_freq_code (from metadata.freq when no freq_column; legacy import_freq_code still read)
     */
    public function build_duckdb_promote_time_spec($sid)
    {
        $columns = $this->select_all($sid, true);
        $spec = array();

        foreach ($columns as $col) {
            if (empty($col['column_type']) || $col['column_type'] !== 'time_period') {
                continue;
            }
            $spec['time_column'] = $col['name'];
            $tf = isset($col['time_period_format']) ? $col['time_period_format'] : null;
            $spec['time_period_format'] = ($tf !== '' && $tf !== null) ? $tf : null;

            $meta = isset($col['metadata']) && is_array($col['metadata']) ? $col['metadata'] : array();
            $ifc_const = $this->resolve_metadata_constant_freq($meta);
            if ($ifc_const !== null) {
                $spec['implied_freq_code'] = $ifc_const;
            }
            break;
        }

        foreach ($columns as $col) {
            if (empty($col['column_type']) || $col['column_type'] !== 'periodicity') {
                continue;
            }
            $spec['freq_column'] = $col['name'];
            break;
        }

        if (!empty($spec['time_column'])) {
            $spec['default_freq_by_format'] = $this->get_dsd_default_freq_by_time_period_format_from_config();
        }

        return $spec;
    }

    /**
     * Without a periodicity (FREQ-from-data) column, each time_period row must have
     * time_period_format and metadata.freq (constant series FREQ; legacy import_freq_code still read).
     *
     * @param array $columns rows like select_all($sid, true) with metadata decoded
     * @return array list of error messages (may be empty)
     */
    private function collect_time_period_freq_errors(array $columns)
    {
        $has_periodicity = false;
        foreach ($columns as $c) {
            $nm = isset($c['name']) ? trim((string) $c['name']) : '';
            if ($nm !== '' && isset($c['column_type']) && $c['column_type'] === 'periodicity') {
                $has_periodicity = true;
                break;
            }
        }

        $this->load->config('indicator_dsd', true);
        $allowed_freq = array();
        foreach ((array) $this->config->item('dsd_freq_codes', 'indicator_dsd') as $row) {
            if (!empty($row['code'])) {
                $allowed_freq[] = (string) $row['code'];
            }
        }

        $errors = array();
        foreach ($columns as $c) {
            if (!isset($c['column_type']) || $c['column_type'] !== 'time_period') {
                continue;
            }
            if ($has_periodicity) {
                continue;
            }

            $name = isset($c['name']) ? $c['name'] : '?';
            $tf = isset($c['time_period_format']) ? $c['time_period_format'] : null;
            $meta = isset($c['metadata']) && is_array($c['metadata']) ? $c['metadata'] : array();
            $ifc = $this->resolve_metadata_constant_freq($meta);

            if ($tf === null || $tf === '' || (is_string($tf) && trim($tf) === '')) {
                $errors[] = "Time period column '{$name}': time_period_format is required when no FREQ (periodicity) column exists.";
            }
            if ($ifc === null) {
                $errors[] = "Time period column '{$name}': constant series FREQ (metadata.freq) is required when no FREQ (periodicity) column exists.";
            }
            if ($ifc !== null && count($allowed_freq) > 0) {
                $code = $ifc;
                if (!in_array($code, $allowed_freq, true)) {
                    $errors[] = "Time period column '{$name}': metadata.freq '{$code}' is not a configured SDMX FREQ code.";
                }
            }
        }

        return $errors;
    }

    /**
     * @param array $projected_columns full column set after a would-be insert/update (metadata arrays)
     * @throws ValidationException
     */
    private function assert_project_time_period_freq_rules(array $projected_columns)
    {
        $errs = $this->collect_time_period_freq_errors($projected_columns);
        if (!empty($errs)) {
            throw new ValidationException('VALIDATION_ERROR: ' . implode(' ', $errs), $errs);
        }
    }

    /**
     * Normalize a physical column name for cross-checking DSD names with CSV/DuckDB headers
     * (spaces/dots → underscores, trim; same normalization as CSV import).
     *
     * @param string $name
     * @return string Uppercase key, or '' if unusable
     */
    private function dsd_physical_column_key($name)
    {
        $n = trim((string) $name);
        $n = preg_replace('/[\s.]+/', '_', $n);
        $n = trim($n, '_');
        if ($n === '') {
            return '';
        }

        return strtoupper($n);
    }

    /**
     * Resolve data columns for validation from DuckDB: published timeseries, else staging.
     *
     * @param int $sid
     * @return array|null { source, column_keys: map upper=>name, row_count?, warning? }
     */
    private function resolve_indicator_data_validation_context($sid)
    {
        $this->load->library('indicator_duckdb_service');

        $page = $this->indicator_duckdb_service->timeseries_page($sid, 0, 1);
        if (is_array($page) && empty($page['error']) && !empty($page['columns']) && is_array($page['columns'])) {
            $map = array();
            foreach ($page['columns'] as $col) {
                $nm = '';
                if (is_array($col) && isset($col['name'])) {
                    $nm = trim((string) $col['name']);
                } elseif (is_string($col)) {
                    $nm = trim($col);
                }
                if ($nm === '') {
                    continue;
                }
                $k = $this->dsd_physical_column_key($nm);
                if ($k !== '') {
                    $map[$k] = $nm;
                }
            }
            if (count($map) > 0) {
                return array(
                    'source' => 'timeseries',
                    'column_keys' => $map,
                    'row_count' => isset($page['total_row_count']) ? (int) $page['total_row_count'] : null,
                    'warning' => null,
                );
            }
        }

        $st = $this->indicator_duckdb_service->draft_describe($sid);
        if (is_array($st) && empty($st['error']) && !empty($st['exists']) && !empty($st['columns']) && is_array($st['columns'])) {
            $map = array();
            foreach ($st['columns'] as $col) {
                $nm = '';
                if (is_array($col) && isset($col['name'])) {
                    $nm = trim((string) $col['name']);
                } elseif (is_string($col)) {
                    $nm = trim($col);
                }
                if ($nm === '') {
                    continue;
                }
                $k = $this->dsd_physical_column_key($nm);
                if ($k !== '') {
                    $map[$k] = $nm;
                }
            }
            if (count($map) > 0) {
                return array(
                    'source' => 'staging',
                    'column_keys' => $map,
                    'row_count' => isset($st['row_count']) ? (int) $st['row_count'] : null,
                    'warning' => null,
                );
            }
        }

        return null;
    }

    /**
     * @param array $dsd_columns rows like select_all($sid, true)
     * @param array $column_key_to_name map uppercase key => physical name in data
     * @param string $source timeseries|staging
     * @return array [ list $errors, list $warnings ]
     */
    private function validate_dsd_columns_against_data_keys(array $dsd_columns, array $column_key_to_name, $source)
    {
        $errors = array();
        $warnings = array();
        $labels = array(
            'timeseries' => 'published data (timeseries)',
            'staging' => 'staging data',
        );
        $label = isset($labels[$source]) ? $labels[$source] : (string) $source;

        foreach ($dsd_columns as $col) {
            $name = isset($col['name']) ? trim((string) $col['name']) : '';
            if ($name === '') {
                continue;
            }
            if (self::is_reserved_system_column_name($name)) {
                continue;
            }
            $key = $this->dsd_physical_column_key($name);
            if ($key === '') {
                continue;
            }
            if (!isset($column_key_to_name[$key])) {
                $errors[] = "DSD column '{$name}' is not present in {$label}.";
            }
        }

        return array($errors, $warnings);
    }

    /**
     * Structural errors: vocabulary is set (global/local/metadata-linked) but the list is missing or has no codes.
     *
     * @param int $sid
     * @param array $columns select_all(..., true)
     * @return string[] error messages
     */
    private function collect_dsd_codelist_definition_errors($sid, array $columns)
    {
        $errors = array();
        $this->load->model('Codelists_model');
        $this->load->model('Local_codelists_model');

        foreach ($columns as $column) {
            $label = isset($column['name']) && $column['name'] !== '' ? $column['name'] : ('#' . (isset($column['id']) ? $column['id'] : '?'));
            $ctype = isset($column['codelist_type']) ? $column['codelist_type'] : 'none';
            $col_type = isset($column['column_type']) ? $column['column_type'] : '';

            if ($ctype === 'global') {
                $pk = isset($column['global_codelist_id']) ? (int) $column['global_codelist_id'] : 0;
                if ($pk <= 0) {
                    $errors[] = "Column '{$label}': global vocabulary is selected but no registry codelist is linked (global_codelist_id).";
                } else {
                    $cl = $this->resolve_global_registry_codelist_row($column);
                    if (!$cl) {
                        $errors[] = "Column '{$label}': global vocabulary registry id {$pk} was not found.";
                    } else {
                        $codes = $this->Codelists_model->get_codes((int) $cl['id'], null, false);
                        if (!is_array($codes) || count($codes) === 0) {
                            $errors[] = "Column '{$label}': linked global codelist has no codes.";
                        }
                    }
                }
            } elseif ($ctype === 'local') {
                $lid = isset($column['local_codelist_id']) ? (int) $column['local_codelist_id'] : 0;
                if ($lid <= 0) {
                    $errors[] = "Column '{$label}': local vocabulary is selected but no local codelist is linked (save the column and add codes, or use “Local codelists from data”).";
                } elseif (!$this->Local_codelists_model->get_list($sid, $lid)) {
                    $errors[] = "Column '{$label}': local codelist id {$lid} was not found for this project.";
                } else {
                    $n = $this->Local_codelists_model->count_items($lid);
                    if ($n === 0) {
                        $errors[] = "Column '{$label}': local codelist has no codes.";
                    }
                }
            }

            $meta = isset($column['metadata']) && is_array($column['metadata']) ? $column['metadata'] : array();

            if ($ctype === 'none' && !empty($column['code_list']) && is_array($column['code_list']) && count($column['code_list']) > 0) {
                $imap = $this->dsd_allow_map_from_code_rows($column['code_list']);
                if (count($imap) === 0) {
                    $errors[] = "Column '{$label}': inline code list has no valid codes (entries are missing or empty).";
                }
            }
        }

        return $errors;
    }

    /**
     * Resolve registry codelist row from indicator_dsd.global_codelist_id (codelists.id).
     *
     * @param array $column indicator_dsd row (decoded)
     * @return array|null codelists row or null
     */
    private function resolve_global_registry_codelist_row(array $column)
    {
        $this->load->model('Codelists_model');
        $pk = isset($column['global_codelist_id']) ? (int) $column['global_codelist_id'] : 0;
        if ($pk <= 0) {
            return null;
        }
        $row = $this->Codelists_model->get_by_id($pk);
        return is_array($row) ? $row : null;
    }

    /**
     * Build lookup map code => true from codelist_items rows or inline code_list entries.
     *
     * @param array $rows get_codes() rows or local items
     * @return array
     */
    private function dsd_allow_map_from_code_rows(array $rows)
    {
        $m = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $c = isset($row['code']) ? trim((string) $row['code']) : '';
            if ($c === '') {
                continue;
            }
            $m[$c] = true;
        }

        return $m;
    }

    /**
     * Allowed codes for one DSD column (multiple constraints = value must match every map).
     *
     * @param int $sid
     * @param array $column indicator_dsd row decoded
     * @return array [ list of [ 'label' => string, 'map' => array code=>true ] ], empty = no codelist enforcement
     */
    private function dsd_column_codelist_constraints($sid, array $column)
    {
        $constraints = array();
        $this->load->model('Codelists_model');
        $this->load->model('Local_codelists_model');

        $ctype = isset($column['codelist_type']) ? $column['codelist_type'] : 'none';
        $col_type = isset($column['column_type']) ? $column['column_type'] : '';
        $meta = isset($column['metadata']) && is_array($column['metadata']) ? $column['metadata'] : array();

        if ($ctype === 'global') {
            $cl = $this->resolve_global_registry_codelist_row($column);
            if ($cl) {
                $codes = $this->Codelists_model->get_codes((int) $cl['id'], null, false);
                if (is_array($codes) && count($codes) > 0) {
                    $constraints[] = array(
                        'label' => 'global vocabulary',
                        'map' => $this->dsd_allow_map_from_code_rows($codes),
                    );
                }
            }
        } elseif ($ctype === 'local') {
            $lid = isset($column['local_codelist_id']) ? (int) $column['local_codelist_id'] : 0;
            if ($lid > 0 && $this->Local_codelists_model->get_list($sid, $lid)) {
                $items = $this->Local_codelists_model->get_items($lid, 0, null, null, 'ASC', null);
                if (is_array($items) && count($items) > 0) {
                    $constraints[] = array(
                        'label' => 'local vocabulary',
                        'map' => $this->dsd_allow_map_from_code_rows($items),
                    );
                }
            }
        } elseif ($ctype === 'none' && !empty($column['code_list']) && is_array($column['code_list'])) {
            $imap = $this->dsd_allow_map_from_code_rows($column['code_list']);
            if (count($imap) > 0) {
                $constraints[] = array(
                    'label' => 'inline code list',
                    'map' => $imap,
                );
            }
        }

        return $constraints;
    }

    /**
     * Distinct non-empty values for one physical column from timeseries or staging (DuckDB).
     *
     * @param int $sid
     * @param string $source timeseries|staging
     * @param string $physical_name
     * @return array [ string[] distinct values, bool truncated ]
     */
    private function fetch_distinct_values_for_dsd_data_column($sid, $source, $physical_name)
    {
        $this->load->library('indicator_duckdb_service');
        $physical_name = trim((string) $physical_name);
        $truncated = false;
        $values = array();

        if ($physical_name === '') {
            return array(array(), false);
        }

        if ($source === 'timeseries') {
            $raw = $this->indicator_duckdb_service->timeseries_distinct_pairs($sid, $physical_name, null, 20000);
            if (is_array($raw) && empty($raw['error']) && !empty($raw['items']) && is_array($raw['items'])) {
                foreach ($raw['items'] as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $c = isset($it['code']) ? trim((string) $it['code']) : '';
                    if ($c !== '') {
                        $values[$c] = true;
                    }
                }
                if (!empty($raw['truncated'])) {
                    $truncated = true;
                }
            }

            return array(array_keys($values), $truncated);
        }

        if ($source === 'staging') {
            $raw = $this->indicator_duckdb_service->draft_distinct($sid, $physical_name, 3000);
            if (is_array($raw) && empty($raw['error'])) {
                $items = isset($raw['items']) && is_array($raw['items']) ? $raw['items'] : array();
                foreach ($items as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $v = isset($it['value']) ? trim((string) $it['value']) : '';
                    if ($v !== '') {
                        $values[$v] = true;
                    }
                }
                if (!empty($raw['truncated'])) {
                    $truncated = true;
                }
            }

            return array(array_keys($values), $truncated);
        }

        return array(array(), false);
    }

    /**
     * Data-phase: every distinct value must appear in each applicable codelist (AND across constraints).
     *
     * @param int $sid
     * @param array $dsd_columns
     * @param array $ctx from resolve_indicator_data_validation_context
     * @return array [ errors[], warnings[] ]
     */
    private function validate_dsd_codelist_values_against_data($sid, array $dsd_columns, array $ctx)
    {
        $errors = array();
        $warnings = array();

        foreach ($dsd_columns as $col) {
            $name = isset($col['name']) ? trim((string) $col['name']) : '';
            if ($name === '') {
                continue;
            }
            if (self::is_reserved_system_column_name($name)) {
                continue;
            }

            $constraints = $this->dsd_column_codelist_constraints($sid, $col);
            if (empty($constraints)) {
                continue;
            }

            $key = $this->dsd_physical_column_key($name);
            if ($key === '' || !isset($ctx['column_keys'][$key])) {
                continue;
            }

            $physical = $ctx['column_keys'][$key];
            list($distinct_vals, $truncated) = $this->fetch_distinct_values_for_dsd_data_column(
                $sid,
                $ctx['source'],
                $physical
            );
            if ($truncated) {
                $warnings[] = "Column '{$name}': codelist validation may be incomplete (distinct value limit reached).";
            }

            foreach ($distinct_vals as $v) {
                if ($v === '') {
                    continue;
                }
                foreach ($constraints as $c) {
                    if (!isset($c['map'][$v])) {
                        $errors[] = "Column '{$name}': value '" . $v . "' is not allowed by " . $c['label'] . '.';
                        break;
                    }
                }
            }
        }

        return array($errors, $warnings);
    }

    /**
     * Minimal timeseries_page-shaped payload so chart_timeseries_slice_context can resolve physical columns.
     *
     * @param array $ctx resolve_indicator_data_validation_context result
     * @return array
     */
    private function validation_synthetic_page_from_column_keys(array $ctx)
    {
        $cols = array();
        foreach ($ctx['column_keys'] as $phys) {
            $cols[] = array('name' => $phys);
        }

        return array('columns' => $cols);
    }

    /**
     * SDMX-style observation key: time_period × slice facets (same as chart-aggregate: geography, dimension, measure, periodicity when codelist), excluding observation value and attributes/annotations.
     * Only rows with non-empty observation value are counted (matches chart-aggregate WHERE on value column).
     *
     * @param int $sid
     * @param array $ctx resolve_indicator_data_validation_context
     * @return array{0: string[], 1: string[], 2: array}
     */
    private function validate_dsd_observation_key_uniqueness($sid, array $ctx)
    {
        $errors = array();
        $warnings = array();
        $meta = array(
            'skipped' => true,
            'reason' => null,
            'semantics' => 'time_period_geography_dimensions_measure_periodicity',
            'key_columns' => array(),
            'value_column' => null,
            'table_rows_read' => null,
            'rows_with_observation_value' => null,
            'unique_observation_count' => null,
            'duplicate_row_count' => null,
            'scan_truncated' => false,
            'valid' => null,
        );

        if ($ctx['source'] === 'staging') {
            $meta['reason'] = 'Observation key uniqueness is checked on published timeseries only, not on staging.';

            return array($errors, $warnings, $meta);
        }

        $page = $this->validation_synthetic_page_from_column_keys($ctx);
        try {
            $slice_ctx = $this->chart_timeseries_slice_context($sid, $page, self::chart_observation_key_slice_column_types());
        } catch (Exception $e) {
            $meta['reason'] = 'Could not derive observation key from DSD and data columns: ' . $e->getMessage();

            return array($errors, $warnings, $meta);
        }

        $phys_time = $slice_ctx['phys_time'];
        $phys_val = $slice_ctx['phys_val'];
        $phys_slices = array();
        $meta['key_columns'][] = array(
            'dsd_name' => $slice_ctx['time_dsd_name'],
            'physical_name' => $phys_time,
            'column_type' => 'time_period',
        );
        foreach ($slice_ctx['slice_facets'] as $sf) {
            $phys_slices[] = $sf['physical'];
            $meta['key_columns'][] = array(
                'dsd_name' => $sf['name'],
                'physical_name' => $sf['physical'],
                'column_type' => isset($sf['column_type']) ? (string) $sf['column_type'] : 'dimension',
            );
        }
        $meta['value_column'] = array(
            'dsd_name' => $slice_ctx['value_dsd_name'],
            'physical_name' => $phys_val,
            'column_type' => 'observation_value',
        );
        $meta['skipped'] = false;

        if ($ctx['source'] !== 'timeseries') {
            $meta['skipped'] = true;
            $meta['reason'] = 'Observation key uniqueness is only checked on published timeseries.';

            return array($errors, $warnings, $meta);
        }

        $this->load->library('indicator_duckdb_service');
        $api = $this->indicator_duckdb_service->timeseries_observation_key_validate($sid, array(
            'time_column' => $phys_time,
            'value_column' => $phys_val,
            'slice_columns' => $phys_slices,
        ));

        if (!is_array($api) || !empty($api['error'])) {
            $msg = isset($api['message']) ? (string) $api['message'] : 'Observation key validation request failed.';
            $errors[] = 'Observation key uniqueness could not be checked via DuckDB: ' . $msg;
            $meta['valid'] = false;
            $meta['aggregate_source'] = null;

            return array($errors, $warnings, $meta);
        }

        $rows_with_value = isset($api['rows_with_observation_value']) ? (int) $api['rows_with_observation_value'] : 0;
        $unique = isset($api['unique_observation_key_count']) ? (int) $api['unique_observation_key_count'] : 0;
        $dup = isset($api['duplicate_row_count']) ? (int) $api['duplicate_row_count'] : 0;
        $table_total = isset($api['table_total_row_count']) ? (int) $api['table_total_row_count'] : null;

        $meta['table_rows_read'] = $rows_with_value;
        $meta['rows_with_observation_value'] = $rows_with_value;
        $meta['unique_observation_count'] = $unique;
        $meta['duplicate_row_count'] = $dup;
        $meta['scan_truncated'] = false;
        $meta['aggregate_source'] = isset($api['source']) ? (string) $api['source'] : 'duckdb';
        if ($table_total !== null) {
            $meta['table_total_row_count'] = $table_total;
        }
        if (isset($api['duplicate_key_group_count'])) {
            $meta['duplicate_key_group_count'] = (int) $api['duplicate_key_group_count'];
        }

        if ($rows_with_value === 0) {
            $meta['valid'] = true;
            $warnings[] = 'No rows with a non-empty observation value were found; uniqueness was not tested.';

            return array($errors, $warnings, $meta);
        }

        if ($dup > 0) {
            $meta['valid'] = false;
            $key_label = implode(', ', array_map(function ($c) {
                return isset($c['dsd_name']) ? (string) $c['dsd_name'] : '';
            }, $meta['key_columns']));
            $errors[] = "Duplicate observation keys: {$rows_with_value} rows with values map to {$unique} distinct keys (columns: {$key_label}). "
                . 'At most one row per key (time × geography, dimensions, measure, periodicity when in key; attributes and annotations excluded).';

            return array($errors, $warnings, $meta);
        }

        $meta['valid'] = true;

        return array($errors, $warnings, $meta);
    }

    /**
     * @param array $structure validate_dsd_structure result (includes 'columns' key)
     * @param array $data_validation
     * @return array API-shaped validation payload
     */
    private function merge_dsd_validation_response(array $structure, array $data_validation)
    {
        $errors = $structure['errors'];
        $warnings = array_merge($structure['warnings'], $data_validation['warnings']);
        if (!$data_validation['skipped'] && isset($data_validation['valid']) && $data_validation['valid'] === false) {
            $errors = array_merge($errors, $data_validation['errors']);
        }

        $overall = $structure['valid']
            && ($data_validation['skipped'] || (isset($data_validation['valid']) && $data_validation['valid'] === true));

        $structure_block = array(
            'valid' => $structure['valid'],
            'errors' => $structure['errors'],
            'warnings' => $structure['warnings'],
            'summary' => $structure['summary'],
        );

        return array(
            'valid' => $overall,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => $structure['summary'],
            'structure' => $structure_block,
            'data_validation' => $data_validation,
        );
    }

    /**
     * DSD structure only (MySQL definitions): SDMX roles, types, time/FREQ metadata rules.
     * Does not read CSV or DuckDB.
     *
     * @param int $sid
     * @return array valid, errors, warnings, summary, columns (internal)
     */
    public function validate_dsd_structure($sid)
    {
        $errors = array();
        $warnings = array();

        $columns = $this->select_all($sid, true);

        $by_type = array();
        foreach ($columns as $column) {
            $type = $column['column_type'];
            if (!isset($by_type[$type])) {
                $by_type[$type] = array();
            }
            $by_type[$type][] = $column;
        }

        $required_types = array('geography', 'time_period', 'indicator_id', 'observation_value');
        foreach ($required_types as $type) {
            $count = isset($by_type[$type]) ? count($by_type[$type]) : 0;
            if ($count === 0) {
                $errors[] = "Required column type '{$type}' is missing. Exactly one column of this type is required.";
            } elseif ($count > 1) {
                $column_names = array_map(function ($col) {
                    return $col['name'];
                }, $by_type[$type]);
                $errors[] = "Column type '{$type}' has {$count} columns (max allowed: 1). Found: " . implode(', ', $column_names);
            }
        }

        $optional_single_types = array('periodicity', 'indicator_name');
        foreach ($optional_single_types as $type) {
            $count = isset($by_type[$type]) ? count($by_type[$type]) : 0;
            if ($count > 1) {
                $column_names = array_map(function ($col) {
                    return $col['name'];
                }, $by_type[$type]);
                $errors[] = "Column type '{$type}' has {$count} columns (max allowed: 1). Found: " . implode(', ', $column_names);
            }
        }

        $valid_types = array(
            'dimension', 'time_period', 'measure', 'attribute',
            'indicator_id', 'indicator_name', 'annotation',
            'geography', 'observation_value', 'periodicity'
        );

        foreach ($columns as $column) {
            $type = $column['column_type'];
            if (!in_array($type, $valid_types)) {
                $errors[] = "Column '{$column['name']}' has invalid column_type '{$type}'";
            }
            if (!empty($column['name']) && self::is_reserved_system_column_name($column['name'])) {
                $errors[] = "Column '{$column['name']}' uses a reserved name (cannot start with '_').";
            }
        }

        foreach ($this->collect_time_period_freq_errors($columns) as $msg) {
            $errors[] = $msg;
        }

        foreach ($this->collect_dsd_codelist_definition_errors($sid, $columns) as $msg) {
            $errors[] = $msg;
        }

        $valid_attachment_levels  = array('DataSet', 'Series', 'Observation');
        $valid_assignment_statuses = array('Mandatory', 'Conditional');
        foreach ($columns as $column) {
            $ct = $column['column_type'];
            if ($ct !== 'attribute' && $ct !== 'annotation') {
                continue;
            }
            $meta = isset($column['metadata']) && is_array($column['metadata']) ? $column['metadata'] : array();
            $al = isset($meta['attachment_level']) ? $meta['attachment_level'] : null;
            $as = isset($meta['assignment_status']) ? $meta['assignment_status'] : null;
            // Both fields are optional; only warn when a value is present but not valid.
            if ($al !== null && $al !== '' && !in_array($al, $valid_attachment_levels, true)) {
                $warnings[] = "Column '{$column['name']}' ({$ct}): attachment_level '{$al}' is not a recognised value (DataSet, Series, Observation).";
            }
            if ($as !== null && $as !== '' && !in_array($as, $valid_assignment_statuses, true)) {
                $warnings[] = "Column '{$column['name']}' ({$ct}): assignment_status '{$as}' is not a recognised value (Mandatory, Conditional).";
            }
        }

        return array(
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => array(
                'total_columns' => count($columns),
                'by_type' => array_map(function ($cols) {
                    return count($cols);
                }, $by_type),
            ),
            'columns' => $columns,
        );
    }

    /**
     * Validate DSD structure, then data presence vs structure when the structure is valid.
     * Data checks are skipped if structure validation fails.
     *
     * Validation rules (structure):
     * - Required (must have exactly 1): geography, time_period, indicator_id, observation_value
     * - Optional single (0 or 1): periodicity, indicator_name
     * - Optional multiple (0 or more): dimension, measure (SDMX measure as a slice dimension), attribute, annotation
     *
     * Data (when structure valid and data present): column presence, codelist allow-lists, and (published timeseries only)
     * observation-key uniqueness: time_period plus geography, dimension, measure, and periodicity (when in data and periodicity has
     * a resolved codelist) — attributes and annotations are never part of the key; among rows with non-empty observation value.
     *
     * @param int $sid - Project ID
     * @return array valid, errors, warnings, summary, structure, data_validation
     */
    public function validate_dsd($sid)
    {
        $structure = $this->validate_dsd_structure($sid);

        $data_validation = array(
            'skipped' => true,
            'has_data' => false,
            'source' => null,
            'valid' => null,
            'errors' => array(),
            'warnings' => array(),
            'reason' => null,
            'row_count' => null,
            'observation_key' => null,
        );

        if (!$structure['valid']) {
            $data_validation['reason'] = 'DSD structure validation failed; data checks were not run.';

            return $this->merge_dsd_validation_response($structure, $data_validation);
        }

        $ctx = $this->resolve_indicator_data_validation_context($sid);
        if ($ctx === null) {
            $data_validation['reason'] = 'No indicator data in DuckDB (published timeseries or staging) found; data checks were not run.';

            return $this->merge_dsd_validation_response($structure, $data_validation);
        }

        $data_validation['skipped'] = false;
        $data_validation['has_data'] = true;
        $data_validation['source'] = $ctx['source'];
        $data_validation['row_count'] = isset($ctx['row_count']) ? $ctx['row_count'] : null;
        if (!empty($ctx['warning'])) {
            $data_validation['warnings'][] = $ctx['warning'];
        }

        list($derr, $dwarn) = $this->validate_dsd_columns_against_data_keys(
            $structure['columns'],
            $ctx['column_keys'],
            $ctx['source']
        );
        list($cerr, $cwarn) = $this->validate_dsd_codelist_values_against_data(
            $sid,
            $structure['columns'],
            $ctx
        );
        list($oerr, $owarn, $obs_key_meta) = $this->validate_dsd_observation_key_uniqueness($sid, $ctx);
        $data_validation['observation_key'] = $obs_key_meta;
        $data_validation['errors'] = array_merge($derr, $cerr, $oerr);
        $data_validation['warnings'] = array_merge($data_validation['warnings'], $dwarn, $cwarn, $owarn);
        $data_validation['valid'] = count($data_validation['errors']) === 0;

        return $this->merge_dsd_validation_response($structure, $data_validation);
    }

    /**
     * Upload and store CSV file for indicator project (singleton pattern)
     * Always overwrites existing file if present
     * Renames file to standard name: indicator_data.csv
     * 
     * @param int $sid - Project ID
     * @param string $uploaded_file_path - Path to uploaded temporary file
     * @param int $user_id - User ID
     * @return array - File upload result with file_id
     */
    public function upload_indicator_csv($sid, $uploaded_file_path, $user_id = null)
    {
        $this->load->model('Editor_datafile_model');
        
        if (!file_exists($uploaded_file_path)) {
            throw new Exception("Uploaded file not found: " . $uploaded_file_path);
        }
        
        // Get or create the single datafile record
        $existing_file = $this->get_indicator_datafile($sid);
        
        // Ensure project folder exists
        $project_folder = $this->Editor_model->get_project_folder($sid);
        if (!$project_folder) {
            $this->Editor_model->create_project_folder($sid);
            $project_folder = $this->Editor_model->get_project_folder($sid);
        }
        
        $data_folder = $project_folder . '/data/';
        if (!file_exists($data_folder)) {
            @mkdir($data_folder, 0777, true);
        }
        
        // Target file path with standard name
        $target_file_path = $data_folder . $this->INDICATOR_DATA_FILENAME;
        
        // Move uploaded file to target location (overwrite if exists)
        if (!@copy($uploaded_file_path, $target_file_path)) {
            throw new Exception("Failed to save CSV file to: " . $target_file_path);
        }
        
        // Update or create database record
        if ($existing_file) {
            // Update existing record
            $update_data = array(
                'file_physical_name' => $this->INDICATOR_DATA_FILENAME,
                'file_name' => 'indicator_data', // Without extension
                'changed_by' => $user_id,
                'changed' => time(),
                'store_data' => 1
            );
            $this->Editor_datafile_model->update($existing_file['id'], $update_data);
            $file_id = $existing_file['file_id'];
        } else {
            // Create new record
            $insert_data = array(
                'sid' => $sid,
                'file_id' => $this->INDICATOR_FILE_ID,
                'file_physical_name' => $this->INDICATOR_DATA_FILENAME,
                'file_name' => 'indicator_data',
                'store_data' => 1,
                'created_by' => $user_id,
                'changed_by' => $user_id,
                'created' => time(),
                'changed' => time()
            );
            $this->Editor_datafile_model->insert($sid, $insert_data);
            $file_id = $this->INDICATOR_FILE_ID;
        }
        
        return array(
            'file_id' => $file_id,
            'file_name' => $this->INDICATOR_DATA_FILENAME,
            'file_path' => $target_file_path
        );
    }

    /**
     * Get the single datafile for an indicator project
     * 
     * @param int $sid - Project ID
     * @return array|null - Datafile record or null if not found
     */
    public function get_indicator_datafile($sid)
    {
        $this->load->model('Editor_datafile_model');
        
        // Try to get by fixed file_id first
        $file = $this->Editor_datafile_model->data_file_by_id($sid, $this->INDICATOR_FILE_ID);
        if ($file) {
            return $file;
        }
        
        // Fallback: get first file if file_id doesn't match (for migration)
        $all_files = $this->Editor_datafile_model->select_all($sid);
        if (!empty($all_files)) {
            return reset($all_files); // Return first file
        }
        
        return null;
    }

    /**
     * Get file path for indicator CSV data
     * 
     * @param int $sid - Project ID
     * @return string|null - Full file path or null if not found
     */
    public function get_indicator_csv_path($sid)
    {
        $datafile = $this->get_indicator_datafile($sid);
        if (!$datafile) {
            return null;
        }
        
        $project_folder = $this->Editor_model->get_project_folder($sid);
        if (!$project_folder) {
            return null;
        }
        
        $file_path = $project_folder . '/data/' . $this->INDICATOR_DATA_FILENAME;
        
        // Check if file exists
        if (file_exists($file_path)) {
            return $file_path;
        }
        
        // Fallback: try to get path from datafile record
        if (isset($datafile['file_physical_name'])) {
            $fallback_path = $project_folder . '/data/' . $datafile['file_physical_name'];
            if (file_exists($fallback_path)) {
                return $fallback_path;
            }
        }
        
        return null;
    }

    /**
     * Delete indicator CSV data file
     * 
     * @param int $sid - Project ID
     * @return bool - True on success
     */
    public function delete_indicator_csv($sid)
    {
        $datafile = $this->get_indicator_datafile($sid);
        if (!$datafile) {
            return true; // Already deleted
        }
        
        $this->load->model('Editor_datafile_model');
        
        // Delete physical file
        $file_path = $this->get_indicator_csv_path($sid);
        if ($file_path && file_exists($file_path)) {
            @unlink($file_path);
        }
        
        // Delete database record
        $this->Editor_datafile_model->delete($sid, $datafile['file_id']);
        
        return true;
    }

    /**
     * Valid code for local/inline population: non-empty, DB-safe length, no control characters.
     * (Series / SDMX-style ids often use ".", "-", ":" etc.; strict alphanumeric was dropping them.)
     *
     * @param string $code
     * @return bool
     */
    private function is_valid_code_list_code($code)
    {
        $code = (string) $code;
        if ($code === '' || strlen($code) > 150) {
            return false;
        }

        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $code) !== 1;
    }

    /**
     * Populate local_codelist_items from DuckDB timeseries for columns with codelist_type = local
     * (any column_type: attribute, indicator_id, dimension, etc., if the name exists in timeseries).
     * Uses metadata.value_label_column (physical header) for labels when set and present in timeseries.
     *
     * @param int $sid
     * @param int|null $user_id
     * @return array keys: updated (int), skipped (string[]), errors (string[]), warnings (string[]), truncated (string[])
     */
    public function populate_local_codelists_from_timeseries($sid, $user_id = null)
    {
        $this->Editor_model->check_project_editable($sid);
        $this->load->library('indicator_duckdb_service');
        $this->load->model('Local_codelists_model');

        $result = array(
            'updated' => 0,
            'skipped' => array(),
            'errors' => array(),
            'warnings' => array(),
            'truncated' => array(),
        );

        $columns = $this->select_all($sid, true);
        $local_count = 0;
        foreach ($columns as $c) {
            if (isset($c['codelist_type']) && $c['codelist_type'] === 'local') {
                $local_count++;
            }
        }
        if ($local_count === 0) {
            $result['skipped'][] = 'No columns with codelist type "local". Set vocabulary to Local, then try again.';

            return $result;
        }

        $page = $this->indicator_duckdb_service->timeseries_page($sid, 0, 1);
        if (!is_array($page) || !empty($page['error'])) {
            $result['errors'][] = isset($page['message']) ? $page['message'] : 'Could not read timeseries from DuckDB.';

            return $result;
        }
        $duck_cols = isset($page['columns']) && is_array($page['columns']) ? $page['columns'] : array();
        $physical_names = array();
        foreach ($duck_cols as $coldef) {
            if (isset($coldef['name']) && $coldef['name'] !== '') {
                $physical_names[strtoupper(trim($coldef['name']))] = $coldef['name'];
            }
        }

        foreach ($columns as $column) {
            $ct = isset($column['codelist_type']) ? $column['codelist_type'] : 'none';
            if ($ct !== 'local') {
                continue;
            }
            $name_u = strtoupper(trim($column['name']));
            if (!isset($physical_names[$name_u])) {
                $result['skipped'][] = $column['name'] . ' (no column with this name in DuckDB timeseries)';

                continue;
            }
            $phys_code = $physical_names[$name_u];
            $metadata = isset($column['metadata']) && is_array($column['metadata']) ? $column['metadata'] : array();
            $label_header = isset($metadata['value_label_column']) ? trim((string) $metadata['value_label_column']) : '';
            $phys_label = null;
            if ($label_header !== '') {
                $lu = strtoupper($label_header);
                if (isset($physical_names[$lu])) {
                    $phys_label = $physical_names[$lu];
                } else {
                    $result['skipped'][] = $column['name'] . ' (value label column "' . $label_header . '" not in timeseries)';

                    continue;
                }
            }

            $raw = $this->indicator_duckdb_service->timeseries_distinct_pairs($sid, $phys_code, $phys_label, 5000);
            if (!is_array($raw) || !empty($raw['error'])) {
                $result['errors'][] = $column['name'] . ': ' . (isset($raw['message']) ? $raw['message'] : 'could not load distinct values');

                continue;
            }
            $items = isset($raw['items']) && is_array($raw['items']) ? $raw['items'] : array();
            if (!empty($raw['truncated'])) {
                $result['truncated'][] = $column['name'];
                $result['warnings'][] = $column['name'] . ': list truncated at distinct limit.';
            }

            $pairs_map = array();
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $code = isset($it['code']) ? trim((string) $it['code']) : '';
                if ($code === '' || !$this->is_valid_code_list_code($code)) {
                    continue;
                }
                $label = isset($it['label']) ? trim((string) $it['label']) : '';
                if ($label === '') {
                    $label = $code;
                }
                $pairs_map[$code] = array('code' => $code, 'label' => $label);
            }
            ksort($pairs_map);
            $pairs = array_values($pairs_map);

            $list_id = isset($column['local_codelist_id']) ? (int) $column['local_codelist_id'] : 0;
            if ($list_id <= 0) {
                $existing = $this->Local_codelists_model->get_list_by_field($sid, (int) $column['id']);
                if ($existing) {
                    $list_id = (int) $existing['id'];
                } else {
                    try {
                        $list_id = $this->Local_codelists_model->insert_list(
                            $sid,
                            (int) $column['id'],
                            array('name' => !empty($column['label']) ? $column['label'] : $column['name']),
                            $user_id
                        );
                    } catch (Exception $e) {
                        $result['errors'][] = $column['name'] . ': ' . $e->getMessage();

                        continue;
                    }
                }
                try {
                    $this->update($sid, (int) $column['id'], array(
                        'local_codelist_id' => $list_id,
                        'changed_by' => $user_id,
                    ), false);
                } catch (Exception $e) {
                    $result['errors'][] = $column['name'] . ': ' . $e->getMessage();

                    continue;
                }
            }

            try {
                $this->Local_codelists_model->replace_all_items($sid, $list_id, $pairs, $user_id);
                $result['updated']++;
            } catch (Exception $e) {
                $result['errors'][] = $column['name'] . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Populate code_list from the indicator CSV file only for dimensions and core fields (geography, time_period).
     * For each column: code = trimmed cell value (non-empty, max 150 chars, no control characters);
     * label = value from metadata.value_label_column CSV column if set, else same as code.
     *
     * @param int $sid - Project ID
     * @param int|null $user_id - User ID for changed_by
     * @return array - ['updated' => int, 'errors' => array(), 'skipped' => array()]
     */
    public function populate_code_lists_from_csv($sid, $user_id = null)
    {
        $this->Editor_model->check_project_editable($sid);

        $result = array('updated' => 0, 'errors' => array(), 'skipped' => array());

        $csv_path = $this->get_indicator_csv_path($sid);
        if (!$csv_path || !file_exists($csv_path)) {
            $result['errors'][] = 'CSV data file not found. Import a CSV first.';
            return $result;
        }

        $columns = $this->select_all($sid, true);
        if (empty($columns)) {
            $result['errors'][] = 'No DSD columns found.';
            return $result;
        }

        $allowed_column_types = array('dimension', 'measure', 'geography', 'time_period');

        $csv = Reader::createFromPath($csv_path, 'r');
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();
        $header_map = array();
        foreach ($headers as $header) {
            $header_map[strtoupper(trim($header))] = $header;
        }

        foreach ($columns as $column) {
            $col_type = isset($column['column_type']) ? $column['column_type'] : 'attribute';
            if (!in_array($col_type, $allowed_column_types, true)) {
                $result['skipped'][] = $column['name'] . ' (only dimensions and geography/time_period get code lists from data)';
                continue;
            }

            $col_name_upper = strtoupper(trim($column['name']));
            $code_column = isset($header_map[$col_name_upper]) ? $header_map[$col_name_upper] : null;
            if (!$code_column) {
                $result['skipped'][] = $column['name'] . ' (no matching CSV column)';
                continue;
            }

            $metadata = isset($column['metadata']) && is_array($column['metadata']) ? $column['metadata'] : array();
            $label_column_name = isset($metadata['value_label_column']) && is_string($metadata['value_label_column']) ? trim($metadata['value_label_column']) : '';
            $label_column = null;
            if ($label_column_name !== '') {
                $label_column = isset($header_map[strtoupper($label_column_name)]) ? $header_map[strtoupper($label_column_name)] : null;
                if (!$label_column) {
                    $label_column = null;
                }
            }

            $code_to_label = array();
            foreach ($csv->getRecords() as $record) {
                $code = isset($record[$code_column]) ? trim((string)$record[$code_column]) : '';
                if ($code === '' || !$this->is_valid_code_list_code($code)) {
                    continue;
                }
                $label = $label_column !== null && isset($record[$label_column]) ? trim((string)$record[$label_column]) : $code;
                $code_to_label[$code] = $label;
            }

            $code_list = array();
            foreach ($code_to_label as $code => $label) {
                $code_list[] = array(
                    'code' => $code,
                    'label' => $label,
                    'description' => ''
                );
            }

            $update_metadata = $metadata;
            $update_data = array(
                'label' => isset($column['label']) ? $column['label'] : $column['name'],
                'description' => isset($column['description']) ? $column['description'] : '',
                'data_type' => isset($column['data_type']) ? $column['data_type'] : 'string',
                'column_type' => isset($column['column_type']) ? $column['column_type'] : 'dimension',
                'time_period_format' => $column['time_period_format'] ?? null,
                'code_list' => $code_list,
                'code_list_reference' => isset($column['code_list_reference']) ? $column['code_list_reference'] : null,
                'metadata' => $update_metadata,
                'changed_by' => $user_id
            );

            try {
                $this->update($sid, $column['id'], $update_data, false);
                $result['updated']++;
            } catch (Exception $e) {
                $result['errors'][] = $column['name'] . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Build [{ code, label }, ...] for chart/filter UIs from inline JSON, global registry, or local list.
     * Global/local codelists often leave indicator_dsd.code_list empty — this resolves actual codes.
     *
     * @param int $sid
     * @param array $column indicator_dsd row with decoded code_list / metadata
     * @return array
     */
    public function resolve_column_code_list_items_for_ui($sid, array $column)
    {
        $out = array();
        $ctype = isset($column['codelist_type']) ? $column['codelist_type'] : 'none';

        if (!empty($column['code_list']) && is_array($column['code_list'])) {
            foreach ($column['code_list'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = isset($row['code']) ? trim((string) $row['code']) : '';
                if ($code === '') {
                    continue;
                }
                $lab = isset($row['label']) ? trim((string) $row['label']) : '';
                $out[] = array(
                    'code' => $code,
                    'label' => $lab !== '' ? $lab : $code,
                );
            }
            if (count($out) > 0) {
                return $out;
            }
        }

        if ($ctype === 'global') {
            $this->load->model('Codelists_model');
            $cl = $this->resolve_global_registry_codelist_row($column);
            if ($cl) {
                $codes = $this->Codelists_model->get_codes((int) $cl['id'], null, true);
                if (is_array($codes)) {
                    foreach ($codes as $cr) {
                        if (!is_array($cr)) {
                            continue;
                        }
                        $code = isset($cr['code']) ? trim((string) $cr['code']) : '';
                        if ($code === '') {
                            continue;
                        }
                        $label = $code;
                        if (!empty($cr['labels']) && is_array($cr['labels'])) {
                            foreach ($cr['labels'] as $lb) {
                                if (is_array($lb) && isset($lb['label']) && trim((string) $lb['label']) !== '') {
                                    $label = trim((string) $lb['label']);
                                    break;
                                }
                            }
                        }
                        $out[] = array('code' => $code, 'label' => $label);
                    }
                }
            }

            return $out;
        }

        if ($ctype === 'local') {
            $this->load->model('Local_codelists_model');
            $lid = isset($column['local_codelist_id']) ? (int) $column['local_codelist_id'] : 0;
            if ($lid > 0 && $this->Local_codelists_model->get_list($sid, $lid)) {
                $items = $this->Local_codelists_model->get_items($lid, 0, null, null, 'ASC', null);
                if (is_array($items)) {
                    foreach ($items as $it) {
                        if (!is_array($it)) {
                            continue;
                        }
                        $code = isset($it['code']) ? trim((string) $it['code']) : '';
                        if ($code === '') {
                            continue;
                        }
                        $lab = isset($it['label']) ? trim((string) $it['label']) : '';
                        $out[] = array(
                            'code' => $code,
                            'label' => $lab !== '' ? $lab : $code,
                        );
                    }
                }
            }

            return $out;
        }

        return $out;
    }

    /**
     * Fill each column's code_list in-memory for API consumers (e.g. chart filters) when empty but linked to global/local.
     *
     * @param int $sid
     * @param array $columns from select_all
     * @return array
     */
    public function enrich_columns_resolved_code_lists($sid, array $columns)
    {
        foreach ($columns as $k => $col) {
            if (!is_array($col)) {
                continue;
            }
            $items = $this->resolve_column_code_list_items_for_ui($sid, $col);
            if (count($items) > 0) {
                $columns[$k]['code_list'] = $items;
            }
        }

        return $columns;
    }

    /**
     * Whether a column has at least one code after resolving inline / global / local codelists.
     *
     * @param int $sid
     * @param array $column indicator_dsd row (decoded)
     * @return bool
     */
    public function indicator_dsd_column_has_resolved_codelist($sid, array $column)
    {
        return count($this->resolve_column_code_list_items_for_ui($sid, $column)) > 0;
    }

    /**
     * DSD roles included in chart GROUP BY / series breakdown (SDMX-aligned: no indicator_id/name facets; measure as dimension).
     *
     * @return string[]
     */
    private static function chart_slice_dsd_column_types()
    {
        return array('geography', 'dimension', 'measure', 'attribute', 'periodicity', 'annotation');
    }

    /**
     * DSD roles included in observation-key uniqueness (with time_period): geography, dimensions, measure, periodicity — not attribute or annotation.
     *
     * @return string[]
     */
    private static function chart_observation_key_slice_column_types()
    {
        return array('geography', 'dimension', 'measure', 'periodicity');
    }

    /**
     * Uppercase key => physical column name from a timeseries/page response.
     *
     * @param array $page
     * @return array<string,string>
     */
    private function timeseries_page_column_upper_to_physical($page)
    {
        $map = array();
        if (empty($page['columns']) || !is_array($page['columns'])) {
            return $map;
        }
        foreach ($page['columns'] as $col) {
            $nm = '';
            if (is_array($col) && isset($col['name'])) {
                $nm = trim((string) $col['name']);
            } elseif (is_string($col)) {
                $nm = trim($col);
            }
            if ($nm === '') {
                continue;
            }
            $k = $this->dsd_physical_column_key($nm);
            if ($k !== '') {
                $map[$k] = $nm;
            }
        }

        return $map;
    }

    /**
     * Time/value physical columns and chart slice facets (DSD name + DuckDB physical), aligned with chart-aggregate.
     *
     * @param int $sid
     * @param array $page timeseries_page first page (columns)
     * @param string[]|null $slice_column_types If set, only these column_type values become slice facets (e.g. observation-key validation); null = full chart slice set.
     * @return array{key_map: array<string,string>, geography_upper: string|null, phys_time: string, phys_val: string, slice_facets: array<int,array{name:string,physical:string}>}
     * @throws Exception
     */
    private function chart_timeseries_slice_context($sid, $page, ?array $slice_column_types = null)
    {
        $key_map = $this->timeseries_page_column_upper_to_physical($page);
        if (empty($key_map)) {
            throw new Exception('Could not read timeseries columns from DuckDB.');
        }

        if ($slice_column_types === null) {
            $slice_column_types = self::chart_slice_dsd_column_types();
        }

        $columns = $this->enrich_columns_resolved_code_lists($sid, $this->select_all($sid, false));
        $time_dsd = null;
        $value_dsd = null;
        $geography_upper = null;
        $slice_dsds = array();

        foreach ($columns as $col) {
            $ct = isset($col['column_type']) ? $col['column_type'] : '';
            $uk = strtoupper(trim((string) $col['name']));
            if ($ct === 'time_period') {
                $time_dsd = $col;
            } elseif ($ct === 'observation_value') {
                $value_dsd = $col;
            } elseif ($ct === 'geography') {
                $geography_upper = $uk;
            }
            if (!in_array($ct, $slice_column_types, true)) {
                continue;
            }
            if (in_array($ct, array('periodicity', 'attribute', 'annotation'), true)) {
                if (!$this->indicator_dsd_column_has_resolved_codelist($sid, $col)) {
                    continue;
                }
            }
            $slice_dsds[] = $col;
        }

        if (!$time_dsd || !$value_dsd) {
            throw new Exception('DSD must include time_period and observation_value columns for chart data.');
        }

        $time_key = $this->dsd_physical_column_key($time_dsd['name']);
        $val_key = $this->dsd_physical_column_key($value_dsd['name']);
        if ($time_key === '' || $val_key === '' || !isset($key_map[$time_key]) || !isset($key_map[$val_key])) {
            throw new Exception('Time or observation column is missing from DuckDB timeseries table.');
        }

        $phys_time = $key_map[$time_key];
        $phys_val = $key_map[$val_key];

        $slice_facets = array();
        $seen_phys = array();
        foreach ($slice_dsds as $sc) {
            $sk = $this->dsd_physical_column_key($sc['name']);
            if ($sk === '' || !isset($key_map[$sk])) {
                continue;
            }
            $p = $key_map[$sk];
            if ($p === $phys_time || $p === $phys_val) {
                continue;
            }
            if (isset($seen_phys[$p])) {
                continue;
            }
            $seen_phys[$p] = true;
            $sc_ct = isset($sc['column_type']) ? (string) $sc['column_type'] : '';
            $slice_facets[] = array(
                'name' => (string) $sc['name'],
                'physical' => $p,
                'column_type' => $sc_ct,
            );
        }

        return array(
            'key_map' => $key_map,
            'geography_upper' => $geography_upper,
            'phys_time' => $phys_time,
            'phys_val' => $phys_val,
            'slice_facets' => $slice_facets,
            'time_dsd_name' => isset($time_dsd['name']) ? (string) $time_dsd['name'] : '',
            'value_dsd_name' => isset($value_dsd['name']) ? (string) $value_dsd['name'] : '',
        );
    }

    /**
     * Build FastAPI chart-aggregate body from DSD + filters. Validates slice filters.
     *
     * @param int $sid
     * @param array $filters geography, dimensions (upper DSD name => string[]), time_period_*, use_ts_year_for_time_filter
     * @param array $page timeseries_page first page (columns)
     * @return array
     * @throws Exception
     */
    private function build_chart_aggregate_spec($sid, $filters, $page)
    {
        $ctx = $this->chart_timeseries_slice_context($sid, $page);
        $key_map = $ctx['key_map'];
        $geography_upper = $ctx['geography_upper'];
        $phys_time = $ctx['phys_time'];
        $phys_val = $ctx['phys_val'];
        $slice_phys = array();
        foreach ($ctx['slice_facets'] as $sf) {
            $slice_phys[] = $sf['physical'];
        }

        $norm_dims = array();
        if (isset($filters['dimensions']) && is_array($filters['dimensions'])) {
            foreach ($filters['dimensions'] as $k => $v) {
                $norm_dims[strtoupper(trim((string) $k))] = is_array($v) ? $v : array();
            }
        }
        if (!empty($filters['geography']) && is_array($filters['geography']) && $geography_upper !== null) {
            if (!isset($norm_dims[$geography_upper]) || !is_array($norm_dims[$geography_upper])) {
                $norm_dims[$geography_upper] = array();
            }
            $norm_dims[$geography_upper] = array_values(array_unique(array_merge(
                $norm_dims[$geography_upper],
                $filters['geography']
            )));
        }

        $phys_filters = array();
        foreach ($norm_dims as $dsd_u => $vals) {
            if (!is_array($vals) || count($vals) === 0) {
                continue;
            }
            if (!isset($key_map[$dsd_u])) {
                throw new Exception("Unknown filter column: {$dsd_u}");
            }
            $phys = $key_map[$dsd_u];
            if (!in_array($phys, $slice_phys, true)) {
                throw new Exception("Column {$dsd_u} is not a chart dimension (slice) column.");
            }
            $phys_filters[$phys] = array_values(array_filter(array_map(function ($x) {
                return trim((string) $x);
            }, $vals), function ($x) {
                return $x !== '';
            }));
        }

        if (count($slice_phys) > 0 && empty($phys_filters)) {
            throw new Exception('Select at least one value in at least one dimension filter (e.g. geography).');
        }

        $body = array(
            'time_column' => $phys_time,
            'value_column' => $phys_val,
            'slice_columns' => $slice_phys,
            'filters' => $phys_filters,
            'time_period_start' => isset($filters['time_period_start']) ? $filters['time_period_start'] : null,
            'time_period_end' => isset($filters['time_period_end']) ? $filters['time_period_end'] : null,
        );
        if (array_key_exists('use_ts_year_for_time_filter', $filters)) {
            $body['use_ts_year_for_time_filter'] = $filters['use_ts_year_for_time_filter'] ? true : false;
        }

        return $body;
    }

    /**
     * Dataset-wide row counts per distinct value for each chart slice column (DuckDB). Keys = DSD column names.
     *
     * @param int $sid
     * @return array{column_counts: array<string,array<int,array{value:string,count:int}>>, metadata: array}
     */
    public function get_chart_facet_value_counts($sid)
    {
        $this->load->library('indicator_duckdb_service');
        $page = $this->indicator_duckdb_service->timeseries_page($sid, 0, 1);
        if (!is_array($page) || !empty($page['error']) || empty($page['columns']) || !is_array($page['columns'])) {
            return array(
                'column_counts' => array(),
                'metadata' => array('source' => 'none'),
            );
        }

        try {
            $ctx = $this->chart_timeseries_slice_context($sid, $page);
        } catch (Exception $e) {
            return array(
                'column_counts' => array(),
                'metadata' => array('source' => 'none', 'message' => $e->getMessage()),
            );
        }

        $slice_facets = isset($ctx['slice_facets']) && is_array($ctx['slice_facets']) ? $ctx['slice_facets'] : array();
        if (count($slice_facets) === 0) {
            return array(
                'column_counts' => array(),
                'metadata' => array('source' => 'duckdb'),
            );
        }

        $columns = array();
        foreach ($slice_facets as $sf) {
            if (!empty($sf['physical'])) {
                $columns[] = $sf['physical'];
            }
        }
        $columns = array_values(array_unique($columns));
        if (count($columns) === 0) {
            return array(
                'column_counts' => array(),
                'metadata' => array('source' => 'duckdb'),
            );
        }

        $raw = $this->indicator_duckdb_service->timeseries_facet_value_counts($sid, array('columns' => $columns));
        if (!empty($raw['error'])) {
            return array(
                'column_counts' => array(),
                'metadata' => array(
                    'source' => 'error',
                    'message' => isset($raw['message']) ? (string) $raw['message'] : '',
                ),
            );
        }

        $phys_to_name = array();
        foreach ($slice_facets as $sf) {
            if (!empty($sf['physical']) && !empty($sf['name'])) {
                $phys_to_name[$sf['physical']] = $sf['name'];
            }
        }

        $column_counts_raw = isset($raw['column_counts']) && is_array($raw['column_counts']) ? $raw['column_counts'] : array();
        $out_counts = array();
        foreach ($column_counts_raw as $phys => $items) {
            if (!is_array($items)) {
                continue;
            }
            $dsd_name = isset($phys_to_name[$phys]) ? $phys_to_name[$phys] : (string) $phys;
            $norm = array();
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $v = isset($row['value']) ? (string) $row['value'] : '';
                $c = isset($row['count']) ? (int) $row['count'] : 0;
                if ($v === '') {
                    continue;
                }
                $norm[] = array('value' => $v, 'count' => $c);
            }
            $out_counts[$dsd_name] = $norm;
        }

        $trunc = array();
        if (isset($raw['columns_truncated']) && is_array($raw['columns_truncated'])) {
            $trunc = $raw['columns_truncated'];
        }

        return array(
            'column_counts' => $out_counts,
            'metadata' => array(
                'source' => 'duckdb',
                'columns_truncated' => $trunc,
            ),
        );
    }

    /**
     * Add series_key_label and geography (geography column label only); strip slice_values.
     *
     * @param int $sid
     * @param array $page timeseries_page
     * @param array $records
     * @param array $metadata chart-aggregate metadata (slice_columns)
     */
    private function enrich_chart_aggregate_records_from_dsd($sid, $page, array &$records, array $metadata)
    {
        $slice_cols = isset($metadata['slice_columns']) && is_array($metadata['slice_columns'])
            ? $metadata['slice_columns']
            : array();

        if (count($records) === 0) {
            return;
        }

        $key_map = $this->timeseries_page_column_upper_to_physical($page);
        if (empty($key_map) || count($slice_cols) === 0) {
            foreach ($records as $i => $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                if (array_key_exists('slice_values', $rec)) {
                    unset($records[$i]['slice_values']);
                }
                if (!isset($records[$i]['series_key_label']) && isset($records[$i]['series_key'])) {
                    $records[$i]['series_key_label'] = $records[$i]['series_key'];
                }
                if (!isset($records[$i]['geography']) && isset($records[$i]['series_key'])) {
                    $records[$i]['geography'] = $records[$i]['series_key'];
                }
            }

            return;
        }

        $columns = $this->enrich_columns_resolved_code_lists($sid, $this->select_all($sid, false));

        /** @var array<string,array<string,string>> upper physical -> lower(code) -> label */
        $labels_by_col_upper = array();
        foreach ($columns as $col) {
            if (!is_array($col)) {
                continue;
            }
            $ct = isset($col['column_type']) ? $col['column_type'] : '';
            if (!in_array($ct, self::chart_slice_dsd_column_types(), true)) {
                continue;
            }
            if (in_array($ct, array('periodicity', 'attribute', 'annotation'), true)) {
                if (!$this->indicator_dsd_column_has_resolved_codelist($sid, $col)) {
                    continue;
                }
            }
            $uk = $this->dsd_physical_column_key($col['name']);
            if ($uk === '' || !isset($key_map[$uk])) {
                continue;
            }
            $phys = $key_map[$uk];
            $pu = strtoupper($phys);
            $in_slice = false;
            foreach ($slice_cols as $sc) {
                if (strtoupper(trim((string) $sc)) === $pu) {
                    $in_slice = true;
                    break;
                }
            }
            if (!$in_slice) {
                continue;
            }

            $items = $this->resolve_column_code_list_items_for_ui($sid, $col);
            if (!isset($labels_by_col_upper[$pu])) {
                $labels_by_col_upper[$pu] = array();
            }
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $c = isset($it['code']) ? trim((string) $it['code']) : '';
                if ($c === '') {
                    continue;
                }
                $lab = isset($it['label']) ? trim((string) $it['label']) : '';
                if ($lab === '') {
                    $lab = $c;
                }
                $labels_by_col_upper[$pu][strtolower($c)] = $lab;
            }
        }

        $geo_phys_upper = null;
        foreach ($columns as $col) {
            if (!is_array($col)) {
                continue;
            }
            if (isset($col['column_type']) && $col['column_type'] === 'geography') {
                $uk = $this->dsd_physical_column_key($col['name']);
                if ($uk !== '' && isset($key_map[$uk])) {
                    $geo_phys_upper = strtoupper($key_map[$uk]);
                }
                break;
            }
        }

        foreach ($records as $i => $rec) {
            if (!is_array($rec)) {
                continue;
            }

            $vals = isset($rec['slice_values']) && is_array($rec['slice_values']) ? $rec['slice_values'] : array();
            if (count($vals) !== count($slice_cols)) {
                $vals = array();
                if (isset($rec['series_key']) && is_string($rec['series_key']) && count($slice_cols) > 0) {
                    $parts = explode(' | ', $rec['series_key'], count($slice_cols));
                    while (count($parts) < count($slice_cols)) {
                        $parts[] = '';
                    }
                    $vals = array_slice($parts, 0, count($slice_cols));
                }
            }

            $parts_label = array();
            $geo_label = null;
            foreach ($slice_cols as $idx => $phys) {
                $pu = strtoupper(trim((string) $phys));
                $code = isset($vals[$idx]) ? trim((string) $vals[$idx]) : '';
                $display = $code;
                if ($code !== '' && isset($labels_by_col_upper[$pu][strtolower($code)])) {
                    $display = $labels_by_col_upper[$pu][strtolower($code)];
                }
                $parts_label[] = ($display !== '' ? $display : $code);
                if ($geo_phys_upper !== null && $pu === $geo_phys_upper) {
                    $geo_label = ($display !== '' ? $display : $code);
                }
            }

            $records[$i]['series_key_label'] = implode(' | ', $parts_label);
            if ($geo_label !== null && $geo_label !== '') {
                $records[$i]['geography'] = $geo_label;
            } elseif (!isset($records[$i]['geography']) && isset($records[$i]['series_key'])) {
                $records[$i]['geography'] = $records[$i]['series_key'];
            }

            unset($records[$i]['slice_values']);
        }
    }

    /**
     * FastAPI chart-aggregate: time_period, observation_value, series_key, slice_values (stripped here).
     *
     * @param int $sid
     * @param array $page timeseries_page first row metadata
     * @param array $raw FastAPI JSON
     * @return array
     */
    private function normalize_chart_aggregate_response($sid, $page, array $raw)
    {
        $records = isset($raw['records']) && is_array($raw['records']) ? $raw['records'] : array();
        $metadata = isset($raw['metadata']) && is_array($raw['metadata']) ? $raw['metadata'] : array();

        $this->enrich_chart_aggregate_records_from_dsd($sid, $page, $records, $metadata);

        $out = array(
            'records' => $records,
            'filter_options' => isset($raw['filter_options']) && is_array($raw['filter_options'])
                ? $raw['filter_options']
                : array(),
            'metadata' => $metadata,
        );
        $out['metadata']['source'] = 'duckdb';

        return $out;
    }

    /**
     * @param int $sid
     * @param array $filters
     * @param array $page
     * @return array|null
     */
    private function try_get_chart_data_duckdb($sid, $filters, $page)
    {
        $spec = $this->build_chart_aggregate_spec($sid, $filters, $page);

        $this->load->library('indicator_duckdb_service');
        $raw = $this->indicator_duckdb_service->timeseries_chart_aggregate($sid, $spec);
        if (!empty($raw['error'])) {
            $hc = isset($raw['http_code']) ? (int) $raw['http_code'] : 0;
            if ($hc === 404 || $hc === 405 || $hc === 501) {
                return null;
            }
            if ($hc >= 400 && $hc < 500) {
                $msg = isset($raw['message']) ? (string) $raw['message'] : 'Chart aggregate request rejected';

                throw new Exception($msg);
            }

            return null;
        }

        return $this->normalize_chart_aggregate_response($sid, $page, $raw);
    }

    /**
     * Get chart data: DuckDB observation rows when timeseries + FastAPI chart-aggregate exist; else CSV.
     *
     * @param int $sid
     * @param array $filters geography, dimensions, time_period_start/end
     * @return array
     */
    public function get_chart_data($sid, $filters = array())
    {
        $this->load->library('indicator_duckdb_service');
        $page = $this->indicator_duckdb_service->timeseries_page($sid, 0, 1);
        $duck_ok = is_array($page) && empty($page['error']) && !empty($page['columns']) && is_array($page['columns']);

        if ($duck_ok) {
            $agg = $this->try_get_chart_data_duckdb($sid, $filters, $page);
            if ($agg !== null) {
                return $agg;
            }
        }

        if (!empty($filters['dimensions']) && is_array($filters['dimensions'])) {
            foreach ($filters['dimensions'] as $k => $v) {
                if (is_array($v) && count($v) > 0) {
                    throw new Exception('Multi-dimension charts require published DuckDB timeseries and the chart-aggregate API. See metadata-editor-fastapi/src/routers/timeseries.py (indicator_timeseries_chart_aggregate).');
                }
            }
        }

        return $this->get_chart_data_from_csv($sid, $filters);
    }

    /**
     * Legacy CSV path (small data): last row wins for duplicate keys; no SQL aggregation.
     *
     * @param int $sid
     * @param array $filters
     * @return array
     */
    private function get_chart_data_from_csv($sid, $filters = array())
    {
        $csv_path = $this->get_indicator_csv_path($sid);
        if (!$csv_path || !file_exists($csv_path)) {
            throw new Exception("CSV data file not found. Please import data first.");
        }

        // Get DSD columns to identify field names
        $columns = $this->select_all($sid, false);
        $column_map = array();
        $geography_column = null;
        $time_period_column = null;
        $observation_value_column = null;

        foreach ($columns as $col) {
            $column_map[strtoupper($col['name'])] = $col;
            if ($col['column_type'] === 'geography') {
                $geography_column = strtoupper($col['name']);
            } elseif ($col['column_type'] === 'time_period') {
                $time_period_column = strtoupper($col['name']);
            } elseif ($col['column_type'] === 'observation_value') {
                $observation_value_column = strtoupper($col['name']);
            }
        }

        if (!$geography_column || !$time_period_column || !$observation_value_column) {
            throw new Exception("Required columns (geography, time_period, observation_value) not found in DSD.");
        }

        // Read CSV
        $csv = Reader::createFromPath($csv_path, 'r');
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();

        // Create mapping of uppercase header names to original header names
        $header_map = array();
        foreach ($headers as $header) {
            $header_map[strtoupper($header)] = $header;
        }

        // Find column names (case-insensitive)
        $geography_col = isset($header_map[$geography_column]) ? $header_map[$geography_column] : null;
        $time_period_col = isset($header_map[$time_period_column]) ? $header_map[$time_period_column] : null;
        $observation_value_col = isset($header_map[$observation_value_column]) ? $header_map[$observation_value_column] : null;

        if (!$geography_col || !$time_period_col || !$observation_value_col) {
            throw new Exception("Required columns not found in CSV file. Looking for: " .
                $geography_column . ", " . $time_period_column . ", " . $observation_value_column);
        }

        // Apply filters
        $geography_filter = isset($filters['geography']) && is_array($filters['geography']) ? $filters['geography'] : null;
        $time_period_start = isset($filters['time_period_start']) ? $filters['time_period_start'] : null;
        $time_period_end = isset($filters['time_period_end']) ? $filters['time_period_end'] : null;

        // Process CSV records
        $records = array();
        $geography_values = array();
        $time_period_values = array();

        foreach ($csv->getRecords() as $record) {
            $geography = isset($record[$geography_col]) ? trim($record[$geography_col]) : '';
            $time_period = isset($record[$time_period_col]) ? trim($record[$time_period_col]) : '';
            $observation_value = isset($record[$observation_value_col]) ? trim($record[$observation_value_col]) : '';

            // Skip empty rows
            if (empty($geography) || empty($time_period) || empty($observation_value)) {
                continue;
            }

            // Apply geography filter
            if ($geography_filter !== null && !in_array($geography, $geography_filter)) {
                continue;
            }

            // Apply time period filter
            if ($time_period_start !== null && $time_period < $time_period_start) {
                continue;
            }
            if ($time_period_end !== null && $time_period > $time_period_end) {
                continue;
            }

            // Collect unique values for filter options
            if (!in_array($geography, $geography_values)) {
                $geography_values[] = $geography;
            }
            if (!in_array($time_period, $time_period_values)) {
                $time_period_values[] = $time_period;
            }

            $records[] = array(
                'geography' => $geography,
                'time_period' => $time_period,
                'observation_value' => is_numeric($observation_value) ? (float) $observation_value : null,
                'series_key' => $geography,
                'series_key_label' => $geography,
            );
        }

        // Sort by time_period
        usort($records, function ($a, $b) {
            return strcmp($a['time_period'], $b['time_period']);
        });

        // Sort filter values
        sort($geography_values);
        sort($time_period_values);

        // Return raw data - let client handle transformation for visualization
        return array(
            'records' => $records,
            'filter_options' => array(
                'geography' => $geography_values,
                'time_period' => array(
                    'min' => !empty($time_period_values) ? min($time_period_values) : null,
                    'max' => !empty($time_period_values) ? max($time_period_values) : null,
                    'values' => $time_period_values,
                ),
            ),
            'metadata' => array(
                'geography_column' => $geography_column,
                'time_period_column' => $time_period_column,
                'observation_value_column' => $observation_value_column,
                'total_records' => count($records),
                'source' => 'csv',
            ),
        );
    }
}

