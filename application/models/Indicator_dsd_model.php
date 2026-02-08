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
            $fields = "id,sid,name,label,description,data_type,column_type,time_period_format,code_list,code_list_reference,metadata,sort_order";
        } else {
            $fields = "id,sid,name,label,description,data_type,column_type,time_period_format,code_list,code_list_reference,sort_order";
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

        return $column;
    }

    /**
     * 
     * Insert new DSD column
     * 
     * @param int $sid - Project ID
     * @param array $options - Column data
     * @return int Inserted column ID
     * 
     **/
    public function insert($sid, $options)
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
     * @return int Updated column ID
     * 
     **/
    public function update($sid, $id, $options)
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

        // Ensure id is an integer
        $id = (int)$id;
        $sid = (int)$sid;

        // Check if record exists before updating
        $exists = $this->db->select('id')->where('sid', $sid)->where('id', $id)->get('indicator_dsd')->row_array();
        if (!$exists) {
            throw new Exception("Column with ID {$id} not found for project {$sid}");
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
    function validate($options, $is_new = true)
    {
        $this->load->library("form_validation");
        $this->form_validation->reset_validation();
        $this->form_validation->set_data($options);

        // Validation rules - all optional (no 'required' rule)
        // Only validate if field is present
        if (isset($options['name'])) {
            $this->form_validation->set_rules('name', 'Column name', 'xss_clean|trim|max_length[100]|regex_match[/^[a-zA-Z0-9_]*$/]');
        }
        if (isset($options['data_type'])) {
            $this->form_validation->set_rules('data_type', 'Data type', 'in_list[string,integer,float,double,date,boolean]');
        }
        if (isset($options['column_type'])) {
            $this->form_validation->set_rules('column_type', 'Column type', 'in_list[dimension,time_period,measure,attribute,indicator_id,indicator_name,annotation,geography,observation_value,periodicity]');
        }
        if (isset($options['time_period_format'])) {
            $this->form_validation->set_rules('time_period_format', 'Time period format', 'in_list[YYYY,YYYY-MM,YYYY-MM-DD,YYYY-MM-DDTHH:MM:SS,YYYY-MM-DDTHH:MM:SSZ]');
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
    private function validate_indicator_id($csv_file_path, $indicator_id_column, $project_idno)
    {
        if (empty($project_idno)) {
            throw new Exception("Indicator IDNO is not available");
        }

        $csv = Reader::createFromPath($csv_file_path, 'r');
        $csv->setHeaderOffset(0);
        
        $row_number = 1; // Start at 1 (header is row 0)
        $project_idno_upper = strtoupper(trim($project_idno));
        
        foreach ($csv->getRecords() as $record) {
            $row_number++;
            
            // Get indicator_id value
            $indicator_id = isset($record[$indicator_id_column]) ? trim($record[$indicator_id_column]) : '';
            
            // Check empty
            if (empty($indicator_id)) {
                throw new Exception("Indicator ID is empty in row {$row_number}");
            }
            
            // Check match (case-insensitive)
            $indicator_id_upper = strtoupper($indicator_id);
            if ($indicator_id_upper !== $project_idno_upper) {
                throw new Exception("Indicator ID '{$indicator_id}' in row {$row_number} does not match indicator IDNO '{$project_idno}'");
            }
            
            // Continue to next row (early stop only on error)
        }
        
        return true;
    }

    public function import_csv($sid, $csv_file_path, $column_mappings, $overwrite_existing = 0, $skip_existing = 0, $user_id = null, $indicator_idno = null, $required_field_label_columns = array())
    {
        $this->Editor_model->check_project_editable($sid);

        $result = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => array()
        );

        if (!file_exists($csv_file_path)) {
            throw new Exception("CSV file not found");
        }

        // Read CSV headers
        $csv = Reader::createFromPath($csv_file_path, 'r');
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();

        // Validate column mappings match CSV headers
        $csv_columns = array_map('strtolower', $headers);
        foreach ($column_mappings as $mapping) {
            $csv_col = strtolower($mapping['csvColumn']);
            if (!in_array($csv_col, $csv_columns)) {
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
        
        // Validate indicator_id values (early stop on first mismatch)
        try {
            $this->validate_indicator_id(
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

                // Check if column exists (compare uppercase)
                $exists = isset($existing_by_name[strtoupper($column_name)]);
                $existing_column = $exists ? $existing_by_name[strtoupper($column_name)] : null;

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

                    // Update existing column
                    $update_data = array(
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
     * 
     * Validate DSD structure according to SDMX rules
     * 
     * Validation rules:
     * - Required (must have exactly 1): geography, time_period, indicator_id, observation_value
     * - Optional single (0 or 1): periodicity, indicator_name
     * - Optional multiple (0 or more): dimension, attribute, annotation
     * 
     * @param int $sid - Project ID
     * @return array Validation result with 'valid' (bool), 'errors' (array), 'warnings' (array)
     * 
     **/
    public function validate_dsd($sid)
    {
        $errors = array();
        $warnings = array();
        
        // Get all columns for this project
        $columns = $this->select_all($sid, false);
        
        // Group columns by column_type
        $by_type = array();
        foreach ($columns as $column) {
            $type = $column['column_type'];
            if (!isset($by_type[$type])) {
                $by_type[$type] = array();
            }
            $by_type[$type][] = $column;
        }
        
        // Required fields (must have exactly 1)
        $required_types = array('geography', 'time_period', 'indicator_id', 'observation_value');
        foreach ($required_types as $type) {
            $count = isset($by_type[$type]) ? count($by_type[$type]) : 0;
            if ($count === 0) {
                $errors[] = "Required column type '{$type}' is missing. Exactly one column of this type is required.";
            } elseif ($count > 1) {
                $column_names = array_map(function($col) { return $col['name']; }, $by_type[$type]);
                $errors[] = "Column type '{$type}' has {$count} columns (max allowed: 1). Found: " . implode(', ', $column_names);
            }
        }
        
        // Optional single fields (0 or 1 allowed)
        $optional_single_types = array('periodicity', 'indicator_name');
        foreach ($optional_single_types as $type) {
            $count = isset($by_type[$type]) ? count($by_type[$type]) : 0;
            if ($count > 1) {
                $column_names = array_map(function($col) { return $col['name']; }, $by_type[$type]);
                $errors[] = "Column type '{$type}' has {$count} columns (max allowed: 1). Found: " . implode(', ', $column_names);
            }
        }
        
        // Note: periodicity and indicator_name are optional, so no error if missing
        
        // Optional multiple fields (0 or more allowed)
        // dimension, attribute, annotation - no validation needed, unlimited allowed
        
        // Additional validation: Check for invalid column types
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
        }
        
        return array(
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => array(
                'total_columns' => count($columns),
                'by_type' => array_map(function($cols) { return count($cols); }, $by_type)
            )
        );
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
     * Populate code_list for all DSD columns from the indicator CSV file.
     * For each column: code = value from the CSV column matching DSD column name;
     * label = value from metadata.value_label_column CSV column if set, else same as code.
     * Updates each column's code_list with unique code/label pairs.
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

        $csv = Reader::createFromPath($csv_path, 'r');
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();
        $header_map = array();
        foreach ($headers as $header) {
            $header_map[strtoupper(trim($header))] = $header;
        }

        foreach ($columns as $column) {
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
                if ($code === '') {
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
                $this->update($sid, $column['id'], $update_data);
                $result['updated']++;
            } catch (Exception $e) {
                $result['errors'][] = $column['name'] . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Get chart data for visualization
     * 
     * @param int $sid - Project ID
     * @param array $filters - Filter options (geography, time_period_start, time_period_end)
     * @return array - Chart data formatted for Chart.js
     */
    public function get_chart_data($sid, $filters = array())
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
                'observation_value' => is_numeric($observation_value) ? (float)$observation_value : null
            );
        }

        // Sort by time_period
        usort($records, function($a, $b) {
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
                    'values' => $time_period_values
                )
            ),
            'metadata' => array(
                'geography_column' => $geography_column,
                'time_period_column' => $time_period_column,
                'observation_value_column' => $observation_value_column,
                'total_records' => count($records)
            )
        );
    }
}

