<?php

require(APPPATH.'/libraries/MY_REST_Controller.php');

use JsonSchema\Validator;
use JsonSchema\Constraints\Constraint;
use Swaggest\JsonDiff\JsonPatch;

/**
 * Validation API
 *
 * Provides validation reports for projects including schema and template validation.
 */
class Validation extends MY_REST_Controller
{
    private $api_user;

    public function __construct()
    {
        parent::__construct();
        $this->load->helper("date");
        $this->load->helper("file");
        $this->load->model("Editor_model");
        $this->load->model("Editor_variable_model");
        $this->load->library("Editor_acl");
        $this->is_authenticated_or_die();
        $this->api_user = $this->api_user();
    }

    function _auth_override_check()
    {
        if ($this->session->userdata('user_id')){
            return true;
        }
        parent::_auth_override_check();
    }

    /**
     * Get schema validation report for a project
     * 
     * @param int $sid Project ID
     */
    function schema_get($sid=null)
    {
        try{
            // get_sid accepts numeric ID or idno string
            $sid = $this->get_sid($sid);
            $project = $this->Editor_model->get_row($sid);

            if (!$project){
                throw new Exception("project not found");
            }

            $this->editor_acl->user_has_project_access($sid, $permission='view');

            // Validate schema
            $type = $project['type'];
            $metadata = $project['metadata'];
            
            // Get schema file path using schema registry (handles aliases and custom schemas)
            $canonical_type = $type;
            $schema_file = null;
            try {
                $this->load->model('Metadata_schemas_model');
                $schema_row = $this->Metadata_schemas_model->get_by_uid($type);
                if ($schema_row && isset($schema_row['uid']) && $schema_row['uid']) {
                    $canonical_type = $schema_row['uid'];
                    $schema_file = $this->Metadata_schemas_model->get_schema_file_path($type);
                }
            } catch(Exception $e) {
                // If schema registry lookup fails, fallback to hard-coded path
                $schema_file = "application/schemas/$type-schema.json";
                if (!file_exists($schema_file)) {
                    $schema_file = null;
                }
            }

            $validation_result = array(
                'valid' => false,
                'type' => $canonical_type,
                'issues' => array()
            );

            if (!$schema_file || !file_exists($schema_file)){
                $validation_result['issues'][] = array(
                    'type' => 'schema_not_found',
                    'message' => "Schema file not found for type: $type",
                    'path' => null
                );
            } else {
                // Initialize type issues array
                $type_issues = array();
                
                // Load compiled schema for type checking
                $this->load->library('Schema_registry');
                $schema = $this->Metadata_schemas_model->get_by_uid($canonical_type);
                
                if ($schema) {
                    $schema_dir = $this->Metadata_schemas_model->resolve_schema_path($schema);
                    if (is_dir($schema_dir)) {
                        // Get the actual filename from the resolved schema file path
                        // This ensures we use the correct filename even if it differs from $schema['filename']
                        $actual_filename = basename($schema_file);
                        
                        // Create a schema object with the correct filename for load_schema_documents
                        $schema_for_loading = $schema;
                        $schema_for_loading['filename'] = $actual_filename;
                        
                        $documents = $this->schema_registry->load_schema_documents($schema_for_loading, $schema_dir);
                        $compiled_schema = $this->schema_registry->inline_schema($actual_filename, $documents, $schema_dir);
                        
                        // Get project template if available
                        $template_uid = isset($project['template_uid']) ? $project['template_uid'] : null;
                        $template_data = null;
                        if ($template_uid) {
                            $this->load->model('Editor_template_model');
                            $template_data = $this->Editor_template_model->get_template_by_uid($template_uid);
                        }
                        
                        // Validate field types explicitly
                        $this->validate_field_types($metadata, $compiled_schema, '', $type_issues, $template_data);
                        
                        if (!empty($type_issues)) {
                            $validation_result['valid'] = false;
                            foreach ($type_issues as $issue) {
                                $validation_result['issues'][] = $issue;
                            }
                        }
                    }
                }
                
                // Validate using JSON Schema
                $validator = new Validator;
                $validator->validate(
                    $metadata, 
                    (object)['$ref' => 'file://' . unix_path(realpath($schema_file))],
                    Constraint::CHECK_MODE_TYPE_CAST 
                    + Constraint::CHECK_MODE_COERCE_TYPES 
                    + Constraint::CHECK_MODE_APPLY_DEFAULTS
                );

                if ($validator->isValid() && empty($type_issues)) {
                    $validation_result['valid'] = true;
                } else {
                    // Convert validator errors to structured issues
                    foreach ($validator->getErrors() as $error) {
                        $validation_result['issues'][] = array(
                            'type' => 'validation_error',
                            'property' => $error['property'],
                            'message' => $error['message'],
                            'constraint' => isset($error['constraint']) ? $error['constraint'] : null,
                            'path' => $error['property']
                        );
                    }
                    // If we found type issues, mark as invalid
                    if (!empty($type_issues)) {
                        $validation_result['valid'] = false;
                    }
                }
            }

            $response = array(
                'status' => 'success',
                'validation' => $validation_result
            );

            $this->set_response($response, REST_Controller::HTTP_OK);
        }
        catch(ValidationException $e){
            $error_output = array(
                'status' => 'failed',
                'message' => $e->getMessage(),
                'errors' => $e->GetValidationErrors()
            );
            $this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
        }
        catch(Exception $e){
            $error_output = array(
                'status' => 'failed',
                'message' => $e->getMessage()
            );
            $this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get variables validation report for a project
     * Only available for microdata (survey) type projects
     * 
     * @param int $sid Project ID
     * 
     * Query parameters:
     * - limit: Maximum number of errors to return (default: 50). Once limit is reached, validation stops.
     */
    function variables_get($sid=null)
    {
        try{
            $sid = $this->get_sid($sid);
            $project = $this->Editor_model->get_row($sid);

            if (!$project){
                throw new Exception("project not found");
            }

            $this->editor_acl->user_has_project_access($sid, $permission='view');

            // Get error limit parameter (default: 50)
            $error_limit = $this->get('limit');
            if ($error_limit === null || !is_numeric($error_limit) || $error_limit < 1) {
                $error_limit = 50;
            } else {
                $error_limit = (int) $error_limit;
            }

            // Check if project is microdata/survey type
            $type = $project['type'];
            $canonical_type = $type;
            try {
                $this->load->model('Metadata_schemas_model');
                $schema_row = $this->Metadata_schemas_model->get_by_uid($type);
                if ($schema_row && isset($schema_row['uid']) && $schema_row['uid']) {
                    $canonical_type = $schema_row['uid'];
                }
            } catch(Exception $e) {
                // If schema registry lookup fails, use original type
            }

            // Only microdata/survey projects have variables
            if ($canonical_type !== 'microdata' && $canonical_type !== 'survey') {
                throw new Exception("Variables validation is only available for microdata (survey) type projects");
            }

            $validation_result = array(
                'valid' => true,
                'type' => 'variable',
                'variables_checked' => 0,
                'variables_with_errors' => 0,
                'issues' => array(),
                'error_limit' => $error_limit,
                'has_more_errors' => false
            );

            // Load variable model
            $this->load->helper('array');
            $all_issues = array();
            $error_count = 0;
            $validation_stopped = false;

            try {
                // Iterate through all variables using chunk reader
                foreach($this->Editor_variable_model->chunk_reader_generator($sid) as $variable){
                    // Stop if we've reached the error limit
                    if ($error_count >= $error_limit) {
                        $validation_stopped = true;
                        break;
                    }
                    
                    $validation_result['variables_checked']++;
                    
                    try{
                        // Validate variable metadata against schema
                        $metadata = isset($variable['metadata']) ? $variable['metadata'] : array();
                        $metadata = array_remove_empty($metadata);
                        
                        $this->Editor_model->validate_schema('variable', $metadata);
                    }
                    catch(ValidationException $e){
                        $validation_result['variables_with_errors']++;
                        $validation_errors = $e->GetValidationErrors();
                        
                        $variable_name = isset($variable['metadata']['name']) ? $variable['metadata']['name'] : 'Unknown';
                        $variable_fid = isset($variable['fid']) ? $variable['fid'] : 'Unknown';
                        $variable_uid = isset($variable['uid']) ? $variable['uid'] : null;

                        foreach($validation_errors as $error) {
                            // Stop if we've reached the error limit
                            if ($error_count >= $error_limit) {
                                $validation_stopped = true;
                                break 2; // Break out of both foreach loops
                            }
                            
                            $issue = array(
                                'type' => 'validation_error',
                                'property' => isset($error['property']) ? $error['property'] : null,
                                'path' => isset($error['property']) ? 'variables/' . $variable_fid . '/' . $error['property'] : null,
                                'message' => isset($error['message']) ? $error['message'] : 'Validation error',
                                'constraint' => isset($error['constraint']) ? $error['constraint'] : null,
                                'variable_fid' => $variable_fid,
                                'variable_name' => $variable_name,
                                'variable_uid' => $variable_uid
                            );
                            
                            // Enhance message with variable context
                            if (isset($error['message'])) {
                                $issue['message'] = $error['message'] . ' - FILE: ' . $variable_fid . ' - Variable: ' . $variable_name;
                            }
                            
                            $all_issues[] = $issue;
                            $error_count++;
                        }
                    }
                }

                // Set has_more_errors flag if validation was stopped due to limit
                $validation_result['has_more_errors'] = $validation_stopped;

                if (!empty($all_issues)){
                    $validation_result['valid'] = false;
                    $validation_result['issues'] = $all_issues;
                }

            } catch(Exception $e) {
                // If there's an error iterating variables, return error
                $validation_result['valid'] = false;
                $validation_result['issues'][] = array(
                    'type' => 'validation_error',
                    'message' => 'Error validating variables: ' . $e->getMessage(),
                    'path' => null,
                    'property' => null
                );
            }

            $response = array(
                'status' => 'success',
                'validation' => $validation_result
            );

            $this->set_response($response, REST_Controller::HTTP_OK);
        }
        catch(Exception $e){
            $error_output = array(
                'status' => 'failed',
                'message' => $e->getMessage()
            );
            $this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get template validation report for a project
     * 
     * @param int $sid Project ID
     * 
     * Query parameters:
     * - report_type: 'full' (default) to return all validated fields, 'errors' to return only invalid fields
     */
    function template_get($sid=null)
    {
        try{
            $sid = $this->get_sid($sid);
            $project = $this->Editor_model->get_row($sid);

            if (!$project){
                throw new Exception("project not found");
            }

            $this->editor_acl->user_has_project_access($sid, $permission='view');

            // Get report type parameter (default: 'full')
            $report_type = $this->get('report_type');
            if (!$report_type || !in_array($report_type, array('full', 'errors'))) {
                $report_type = 'full';
            }

            $metadata = $project['metadata'];
            $template_uid = isset($project['template_uid']) ? $project['template_uid'] : null;

            $validation_result = array(
                'valid' => true,
                'template_uid' => $template_uid,
                'issues' => array()
            );

            if (!$template_uid){
                $validation_result['valid'] = false;
                $validation_result['issues'][] = array(
                    'type' => 'no_template',
                    'message' => 'Project does not have a template assigned',
                    'path' => null,
                    'field' => null
                );
            } else {
                // Load template
                $this->load->model('Editor_template_model');
                $template = $this->Editor_template_model->get_template_by_uid($template_uid);

                if (!$template){
                    $validation_result['valid'] = false;
                    $validation_result['issues'][] = array(
                        'type' => 'template_not_found',
                        'message' => "Template not found: $template_uid",
                        'path' => null,
                        'field' => null
                    );
                } else {
                    // Get template items structure
                    $template_items = isset($template['template']['items']) ? $template['template']['items'] : array();
                    
                    // Validate template items
                    $this->load->helper('array');
                    $this->load->library('Form_validation');
                    
                    $issues = array();
                    $validation_report = array(); // Track all validated fields (success + failed)
                    $this->validate_template_items($template_items, $metadata, '', $issues, $validation_report);
                    
                    // Filter validation report based on report_type parameter
                    if ($report_type === 'errors') {
                        // Only return invalid fields
                        $validation_report = array_filter($validation_report, function($item) {
                            return isset($item['status']) && $item['status'] === 'invalid';
                        });
                        // Re-index array after filtering
                        $validation_report = array_values($validation_report);
                    }
                    // If report_type is 'full', return all fields (no filtering)
                    
                    // Set overall validation status
                    $validation_result['valid'] = empty($issues);
                    $validation_result['issues'] = $issues;
                    $validation_result['validation_report'] = $validation_report; // Include comprehensive report (filtered if errors only)
                    $validation_result['report_type'] = $report_type; // Include report type in response
                }
            }

            $response = array(
                'status' => 'success',
                'validation' => $validation_result
            );

            $this->set_response($response, REST_Controller::HTTP_OK);
        }
        catch(Exception $e){
            $error_output = array(
                'status' => 'failed',
                'message' => $e->getMessage()
            );
            $this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Map frontend validation rule names to CodeIgniter backend rule names
     *
     * Template validation rules are designed for client-side (VeeValidate),
     * but need to be mapped to CodeIgniter's Form_validation rule names
     *
     * Client-side uses colon format for parameters (e.g., "min:4"), while
     * CodeIgniter uses bracket format (e.g., "min[4]"). This function handles both.
     *
     * @param string|array $rules Frontend rule name(s)
     * @return string|array Backend rule name(s)
     */
    private function map_frontend_to_backend_rules($rules)
    {
        // Mapping from frontend (VeeValidate) rule names to backend (CodeIgniter) rule names
        $rule_mapping = array(
            'min' => 'min_length',          // Frontend: min:4 or min[4] -> Backend: min_length[4]
            'max' => 'max_length',          // Frontend: max:100 or max[100] -> Backend: max_length[100]
            'regex' => 'regex_match',       // Frontend: regex[/pattern/] -> Backend: regex_match[/pattern/]
            'alpha_num' => 'alpha_numeric', // Frontend: alpha_num -> Backend: alpha_numeric
            // Rules that match on both sides:
            // 'required' => 'required' (same)
            // 'alpha' => 'alpha' (same)
            // 'numeric' => 'numeric' (same)
            // 'is_uri' => 'is_uri' (same - custom rule on both sides)
        );

        // Helper function to normalize and map a single rule
        $normalize_and_map_rule = function($rule) use ($rule_mapping) {
            if (!is_string($rule)) {
                return $rule; // Keep non-string rules as-is
            }
            
            // First, convert colon format to bracket format (e.g., "min:4" -> "min[4]")
            // Handle both colon format (min:4) and bracket format (min[4])
            if (preg_match('/^([^\[:]+)[:](.+)$/', $rule, $colon_matches)) {
                // Colon format detected - convert to bracket format
                $rule_name = $colon_matches[1];
                $param_value = $colon_matches[2];
                $rule = $rule_name . '[' . $param_value . ']';
            }
            
            // Now handle bracket format (e.g., "min[4]")
            if (preg_match('/^([^\[]+)(\[.*\])?$/', $rule, $matches)) {
                $rule_name = $matches[1];
                $param = isset($matches[2]) ? $matches[2] : '';
                
                // Map the rule name if needed
                if (isset($rule_mapping[$rule_name])) {
                    return $rule_mapping[$rule_name] . $param;
                }
                
                return $rule; // No mapping needed
            }
            
            return $rule;
        };

        // Handle string (single rule, possibly with parameter like "min:4" or "min[4]")
        if (is_string($rules)) {
            return $normalize_and_map_rule($rules);
        }

        // Handle array of rules
        if (is_array($rules)) {
            $mapped_rules = array();
            
            foreach ($rules as $rule) {
                $mapped_rules[] = $normalize_and_map_rule($rule);
            }
            
            return $mapped_rules;
        }

        return $rules;
    }

    /**
     * Recursively validate template items
     * 
     * @param array $items Template items
     * @param array $metadata Project metadata
     * @param string $base_path Base path for nested items
     * @param array &$issues Reference to issues array to populate (errors only)
     * @param array &$validation_report Reference to validation report array (all fields with status)
     */
    private function validate_template_items($items, $metadata, $base_path = '', &$issues = array(), &$validation_report = array())
    {
        if (!is_array($items)){
            return;
        }

        foreach ($items as $item) {
            // Skip custom fields
            if (isset($item['is_custom']) && $item['is_custom']){
                continue;
            }

            $field_key = isset($item['key']) ? $item['key'] : null;
            
            // Check if key already contains dots (indicating it's a full path)
            // In templates, the 'key' field already contains the full path from root
            // If so, use it as-is without prepending base_path
            if ($field_key && strpos($field_key, '.') !== false) {
                // Key is already a full path - use it directly
                $field_path = $field_key;
            } else {
                // Key is just a segment - build path from base_path
                $field_path = $base_path ? $base_path . '.' . $field_key : $field_key;
            }

            // Get validation rules from item
            $rules = null;
            if (isset($item['rules']) && !empty($item['rules'])){
                $rules = $item['rules'];
            } elseif (isset($item['_rules']) && !empty($item['_rules'])){
                $rules = $item['_rules'];
            }

            // Check if field is required
            $is_required_flag = isset($item['is_required']) && $item['is_required'] === true;

            // Validate field if it has rules OR is required
            if ($field_key && ($rules || $is_required_flag)) {
                $value = array_data_get($metadata, $field_key, null);
                
                // Check if field is empty (null, empty string, empty array, or whitespace-only string)
                $is_empty = ($value === null || 
                            $value === '' || 
                            (is_array($value) && empty($value)) ||
                            (is_string($value) && trim($value) === ''));
                
                // Initialize rules_array
                if ($rules) {
                    // Convert rules to array if it's a string
                    if (is_string($rules)){
                        $rules_array = explode('|', $rules);
                    } else {
                        $rules_array = $rules;
                    }
                    
                    // Ensure rules_array is an array (handle empty objects {} or other types)
                    if (!is_array($rules_array)) {
                        // If it's an object, convert to array, otherwise make empty array
                        if (is_object($rules_array)) {
                            $rules_array = (array) $rules_array;
                        } else {
                            $rules_array = array();
                        }
                    }
                } else {
                    // No rules provided, start with empty array
                    $rules_array = array();
                }
                
                // If rules is an associative array (e.g., {"is_uri": true}),
                // convert it to a simple array of rule names (e.g., ["is_uri"])
                // CodeIgniter expects rule names as array values, not keys
                $keys_are_strings = true;
                $converted_rules = array();
                foreach ($rules_array as $key => $val) {
                    if (is_string($key)) {
                        // This is an associative array - use the key as the rule name
                        $converted_rules[] = $key;
                    } else {
                        // This is already a simple array - use as-is
                        $keys_are_strings = false;
                        break;
                    }
                }
                // Only replace if we detected associative array format
                if ($keys_are_strings && !empty($converted_rules)) {
                    $rules_array = $converted_rules;
                }
                
                // Check if field is required and ensure 'required' rule is added
                $is_required = false;
                
                // If is_required flag is set to true, always add 'required' rule
                if ($is_required_flag) {
                    $is_required = true;
                    // Add 'required' if not already present
                    if (!in_array('required', $rules_array, true)) {
                        array_unshift($rules_array, 'required');
                    }
                } elseif (isset($item['is_required']) && $item['is_required'] === false) {
                    // If is_required is explicitly false, remove 'required' rule
                    $rules_array = array_values(array_filter($rules_array, function($rule) {
                        return $rule !== 'required';
                    }));
                    $is_required = false;
                } elseif (in_array('required', $rules_array, true)) {
                    // If 'required' is already in the rules array, mark as required
                    $is_required = true;
                }
                
                // Skip validation entirely if field is empty and not required
                // This prevents CodeIgniter from running validation on empty optional fields
                if (!($is_empty && !$is_required)) {
                    // Only validate if field is not empty OR field is required
                    
                    // Map frontend rule names to backend CodeIgniter rule names
                    $rules_array = $this->map_frontend_to_backend_rules($rules_array);
                    
                    // Validate using CodeIgniter Form_validation
                    // Clear ALL previous validation state completely to prevent label/error caching
                    $this->form_validation->reset_validation();
                    $this->form_validation->validation_data = array();
                    
                    // Determine the correct field label for error messages
                    // Extract field name from path (last segment) as fallback if title is wrong
                    $field_name_from_path = $field_path;
                    if (strpos($field_path, '.') !== false) {
                        $path_parts = explode('.', $field_path);
                        $field_name_from_path = end($path_parts);
                    }
                    
                    // Use title from item, but if title seems wrong (empty or generic), use field name from path
                    $item_title = isset($item['title']) ? trim($item['title']) : '';
                    $field_label = (!empty($item_title) && $item_title !== 'Title') 
                        ? $item_title 
                        : ($field_name_from_path ? ucfirst(str_replace('_', ' ', $field_name_from_path)) : ($field_key ? ucfirst(str_replace('_', ' ', $field_key)) : 'Field'));
                    
                    // Set data and rules with fresh state
                    $this->form_validation->set_data(array('field' => $value));
                    $this->form_validation->set_rules('field', $field_label, $rules_array);
                    
                    $validation_passed = $this->form_validation->run();
                    $validation_errors = array();
                    
                    if (!$validation_passed){
                        $validation_errors = $this->form_validation->error_array();
                        foreach ($validation_errors as $error_msg) {
                            $issues[] = array(
                                'type' => 'template_validation_error',
                                'path' => $field_path,
                                'field' => $field_key,
                                'title' => isset($item['title']) ? $item['title'] : $field_key,
                                'message' => $error_msg,
                                'rules' => $rules,
                                'value' => $value
                            );
                        }
                    }
                    
                    // Add to comprehensive validation report (success or failed)
                    $validation_report[] = array(
                        'path' => $field_path,
                        'field' => $field_key,
                        'title' => isset($item['title']) ? $item['title'] : $field_key,
                        'status' => $validation_passed ? 'valid' : 'invalid',
                        'rules' => $rules,
                        'rules_applied' => $rules_array, // Backend rules after mapping
                        'value' => $value,
                        'is_empty' => $is_empty,
                        'is_required' => $is_required,
                        'errors' => $validation_errors,
                        'error_count' => count($validation_errors)
                    );
                } else {
                    // Field was skipped (empty and not required) - still add to report
                    $validation_report[] = array(
                        'path' => $field_path,
                        'field' => $field_key,
                        'title' => isset($item['title']) ? $item['title'] : $field_key,
                        'status' => 'skipped',
                        'rules' => $rules,
                        'rules_applied' => array(),
                        'value' => $value,
                        'is_empty' => $is_empty,
                        'is_required' => $is_required,
                        'errors' => array(),
                        'error_count' => 0,
                        'skip_reason' => 'Field is empty and not required'
                    );
                }
            }

            // Handle nested items
            if (isset($item['items']) && is_array($item['items'])){
                $this->validate_template_items($item['items'], $metadata, $field_path, $issues, $validation_report);
            }

            // Handle array props (for array fields)
            if (isset($item['props']) && is_array($item['props']) && $field_key){
                $array_data = array_data_get($metadata, $field_key, array());
                if (is_array($array_data)){
                    foreach ($array_data as $index => $array_item) {
                        foreach ($item['props'] as $prop) {
                            // Use prop_key if available (contains full path), otherwise use key
                            $prop_key_full = isset($prop['prop_key']) ? $prop['prop_key'] : null;
                            $prop_key_name = isset($prop['key']) ? $prop['key'] : null;
                            
                            if (!$prop_key_full && !$prop_key_name){
                                continue;
                            }

                            // Determine prop_path and prop_key_name for value access
                            if ($prop_key_full) {
                                // prop_key contains full path - use it directly with array index
                                $prop_path = $prop_key_full . '[' . $index . ']';
                                // Extract just the field name for accessing the value in array_item
                                $prop_key_parts = explode('.', $prop_key_full);
                                $prop_key_name = end($prop_key_parts);
                            } else {
                                // Fallback: build path from field_path
                                $prop_path = $field_path . '[' . $index . '].' . $prop_key_name;
                            }
                            
                            // Get prop rules
                            $prop_rules = null;
                            if (isset($prop['rules']) && !empty($prop['rules'])){
                                $prop_rules = $prop['rules'];
                            } elseif (isset($prop['_rules']) && !empty($prop['_rules'])){
                                $prop_rules = $prop['_rules'];
                            }

                            // Check if prop is required
                            $prop_is_required_flag = isset($prop['is_required']) && $prop['is_required'] === true;

                            // Validate prop if it has rules OR is required
                            if ($prop_rules || $prop_is_required_flag) {
                                $prop_value = isset($array_item[$prop_key_name]) ? $array_item[$prop_key_name] : null;
                                
                                // Check if prop field is empty (null, empty string, empty array, or whitespace-only string)
                                $prop_is_empty = ($prop_value === null || 
                                                 $prop_value === '' || 
                                                 (is_array($prop_value) && empty($prop_value)) ||
                                                 (is_string($prop_value) && trim($prop_value) === ''));
                                
                                // Initialize prop_rules_array
                                if ($prop_rules) {
                                    // Convert rules to array if it's a string
                                    if (is_string($prop_rules)){
                                        $prop_rules_array = explode('|', $prop_rules);
                                    } else {
                                        $prop_rules_array = $prop_rules;
                                    }
                                    
                                    // Ensure prop_rules_array is an array (handle empty objects {} or other types)
                                    if (!is_array($prop_rules_array)) {
                                        // If it's an object, convert to array, otherwise make empty array
                                        if (is_object($prop_rules_array)) {
                                            $prop_rules_array = (array) $prop_rules_array;
                                        } else {
                                            $prop_rules_array = array();
                                        }
                                    }
                                } else {
                                    // No rules provided, start with empty array
                                    $prop_rules_array = array();
                                }
                                
                                // If rules is an associative array (e.g., {"is_uri": true}),
                                // convert it to a simple array of rule names (e.g., ["is_uri"])
                                // CodeIgniter expects rule names as array values, not keys
                                $keys_are_strings = true;
                                $converted_rules = array();
                                foreach ($prop_rules_array as $key => $val) {
                                    if (is_string($key)) {
                                        // This is an associative array - use the key as the rule name
                                        $converted_rules[] = $key;
                                    } else {
                                        // This is already a simple array - use as-is
                                        $keys_are_strings = false;
                                        break;
                                    }
                                }
                                // Only replace if we detected associative array format
                                if ($keys_are_strings && !empty($converted_rules)) {
                                    $prop_rules_array = $converted_rules;
                                }
                                
                                // Check if prop field is required and ensure 'required' rule is added
                                $prop_is_required = false;
                                
                                // If is_required flag is set to true, always add 'required' rule
                                if ($prop_is_required_flag) {
                                    $prop_is_required = true;
                                    // Add 'required' if not already present
                                    if (!in_array('required', $prop_rules_array, true)) {
                                        array_unshift($prop_rules_array, 'required');
                                    }
                                } elseif (isset($prop['is_required']) && $prop['is_required'] === false) {
                                    // If is_required is explicitly false, remove 'required' rule
                                    $prop_rules_array = array_values(array_filter($prop_rules_array, function($rule) {
                                        return $rule !== 'required';
                                    }));
                                    $prop_is_required = false;
                                } elseif (in_array('required', $prop_rules_array, true)) {
                                    // If 'required' is already in the rules array, mark as required
                                    $prop_is_required = true;
                                }
                                
                                // Skip validation entirely if prop field is empty and not required
                                // This prevents CodeIgniter from running validation on empty optional fields
                                if (!($prop_is_empty && !$prop_is_required)) {
                                    // Only validate if field is not empty OR field is required
                                    
                                    // Map frontend rule names to backend CodeIgniter rule names
                                    $prop_rules_array = $this->map_frontend_to_backend_rules($prop_rules_array);
                                    
                                    // Validate prop
                                    // Clear ALL previous validation state completely to prevent label/error caching
                                    $this->form_validation->reset_validation();
                                    $this->form_validation->validation_data = array();
                                    
                                    // Determine the correct field label for error messages
                                    // Extract field name from path (last segment) as fallback if title is wrong
                                    $prop_field_name_from_path = $prop_path;
                                    if (strpos($prop_path, '.') !== false) {
                                        $prop_path_parts = explode('.', $prop_path);
                                        $prop_field_name_from_path = end($prop_path_parts);
                                    }
                                    
                                    // Use title from prop, but if title seems wrong (empty or generic), use field name from path
                                    $prop_title = isset($prop['title']) ? trim($prop['title']) : '';
                                    $prop_display_name = (!empty($prop_title) && $prop_title !== 'Title') 
                                        ? $prop_title 
                                        : ($prop_field_name_from_path ? ucfirst(str_replace('_', ' ', $prop_field_name_from_path)) : ($prop_key_full ? ucfirst(str_replace('_', ' ', $prop_key_full)) : ($prop_key_name ? ucfirst(str_replace('_', ' ', $prop_key_name)) : 'Field')));
                                    
                                    // Set data and rules with fresh state
                                    $this->form_validation->set_data(array('field' => $prop_value));
                                    $this->form_validation->set_rules('field', $prop_display_name, $prop_rules_array);
                                    
                                    $prop_validation_passed = $this->form_validation->run();
                                    $prop_validation_errors = array();
                                    
                                    if (!$prop_validation_passed){
                                        $prop_validation_errors = $this->form_validation->error_array();
                                        foreach ($prop_validation_errors as $error_msg) {
                                            $issues[] = array(
                                                'type' => 'template_validation_error',
                                                'path' => $prop_path,
                                                'field' => $prop_key_full ? $prop_key_full : $prop_key_name,
                                                'title' => isset($prop['title']) ? $prop['title'] : ($prop_key_full ? $prop_key_full : $prop_key_name),
                                                'message' => $error_msg,
                                                'rules' => $prop_rules,
                                                'value' => $prop_value
                                            );
                                        }
                                    }
                                    
                                    // Add to comprehensive validation report (success or failed)
                                    $validation_report[] = array(
                                        'path' => $prop_path,
                                        'field' => $prop_key_full ? $prop_key_full : $prop_key_name,
                                        'title' => isset($prop['title']) ? $prop['title'] : ($prop_key_full ? $prop_key_full : $prop_key_name),
                                        'status' => $prop_validation_passed ? 'valid' : 'invalid',
                                        'rules' => $prop_rules,
                                        'rules_applied' => $prop_rules_array, // Backend rules after mapping
                                        'value' => $prop_value,
                                        'is_empty' => $prop_is_empty,
                                        'is_required' => $prop_is_required,
                                        'errors' => $prop_validation_errors,
                                        'error_count' => count($prop_validation_errors),
                                        'is_array_prop' => true,
                                        'array_index' => $index
                                    );
                                } else {
                                    // Prop field was skipped (empty and not required) - still add to report
                                    $validation_report[] = array(
                                        'path' => $prop_path,
                                        'field' => $prop_key_full ? $prop_key_full : $prop_key_name,
                                        'title' => isset($prop['title']) ? $prop['title'] : ($prop_key_full ? $prop_key_full : $prop_key_name),
                                        'status' => 'skipped',
                                        'rules' => $prop_rules,
                                        'rules_applied' => array(),
                                        'value' => $prop_value,
                                        'is_empty' => $prop_is_empty,
                                        'is_required' => $prop_is_required,
                                        'errors' => array(),
                                        'error_count' => 0,
                                        'skip_reason' => 'Field is empty and not required',
                                        'is_array_prop' => true,
                                        'array_index' => $index
                                    );
                                }
                            }

                            // Handle nested props items
                            if (isset($prop['items']) && is_array($prop['items'])){
                                $this->validate_template_items($prop['items'], $array_item, $prop_path, $issues, $validation_report);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Get extra fields detection report for a project
     * Finds fields in metadata that are not defined in the schema
     * 
     * @param int $sid Project ID
     */
    function extra_fields_get($sid=null)
    {
        try{
            $sid = $this->get_sid($sid);
            $project = $this->Editor_model->get_row($sid);

            if (!$project){
                throw new Exception("project not found");
            }

            $this->editor_acl->user_has_project_access($sid, $permission='view');

            $metadata = $project['metadata'];
            $type = $project['type'];
            
            // Resolve canonical type
            $canonical_type = $type;
            try {
                $this->load->model('Metadata_schemas_model');
                $schema_row = $this->Metadata_schemas_model->get_by_uid($type);
                if ($schema_row && isset($schema_row['uid']) && $schema_row['uid']) {
                    $canonical_type = $schema_row['uid'];
                }
            } catch(Exception $e) {
                // If schema registry lookup fails, use original type
            }

            $result = array(
                'schema_uid' => $canonical_type,
                'extra_fields' => array()
            );

            // Load compiled schema
            $this->load->library('Schema_registry');
            $schema = $this->Metadata_schemas_model->get_by_uid($canonical_type);

            if (!$schema){
                $result['error'] = "Schema not found: $canonical_type";
            } else {
                // Get schema file path using schema registry (handles aliases and custom schemas)
                try {
                    $schema_file = $this->Metadata_schemas_model->get_schema_file_path($type);
                } catch(Exception $e) {
                    $result['error'] = "Schema file not found for type: $type - " . $e->getMessage();
                    $response = array(
                        'status' => 'success',
                        'result' => $result
                    );
                    $this->set_response($response, REST_Controller::HTTP_OK);
                    return;
                }
                
                $schema_dir = $this->Metadata_schemas_model->resolve_schema_path($schema);
                if (!is_dir($schema_dir)) {
                    $result['error'] = "Schema directory not found: $schema_dir";
                } else {
                    // Get the actual filename from the resolved schema file path
                    // This ensures we use the correct filename even if it differs from $schema['filename']
                    $actual_filename = basename($schema_file);
                    
                    // Create a schema object with the correct filename for load_schema_documents
                    $schema_for_loading = $schema;
                    $schema_for_loading['filename'] = $actual_filename;
                    
                    // Get compiled schema
                    $documents = $this->schema_registry->load_schema_documents($schema_for_loading, $schema_dir);
                    $compiled_schema = $this->schema_registry->inline_schema($actual_filename, $documents, $schema_dir);
                    
                    // Find extra fields
                    $this->load->helper('array');
                    $extra_fields = array();
                    $this->find_extra_fields($metadata, $compiled_schema, '', $extra_fields);
                    
                    $result['extra_fields'] = $extra_fields;
                }
            }

            $response = array(
                'status' => 'success',
                'result' => $result
            );

            $this->set_response($response, REST_Controller::HTTP_OK);
        }
        catch(Exception $e){
            $error_output = array(
                'status' => 'failed',
                'message' => $e->getMessage()
            );
            $this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get template extra fields detection report for a project
     * Finds fields in metadata that are not defined in the current template and not displayed
     * 
     * @param int $sid Project ID
     */
    function template_extra_fields_get($sid=null)
    {
        try{
            $sid = $this->get_sid($sid);
            $project = $this->Editor_model->get_row($sid);

            if (!$project){
                throw new Exception("project not found");
            }

            $this->editor_acl->user_has_project_access($sid, $permission='view');

            $metadata = $project['metadata'];
            $template_uid = isset($project['template_uid']) ? $project['template_uid'] : null;

            $result = array(
                'template_uid' => $template_uid,
                'extra_fields' => array()
            );

            if (!$template_uid){
                $result['error'] = "Project does not have a template assigned";
            } else {
                $this->load->model('Editor_template_model');
                $template = $this->Editor_template_model->get_template_by_uid($template_uid);

                if (!$template){
                    $result['error'] = "Template not found: $template_uid";
                } else {
                    // Get template items structure
                    $template_items = isset($template['template']) && is_array($template['template']) ? $template['template'] : array();
                    
                    if (empty($template_items)) {
                        $result['error'] = "Template has no items defined";
                    } else {
                        // Collect all template keys (from items and props)
                        $template_keys = array();
                        $this->collect_template_keys($template_items, '', $template_keys);
                        
                        // Find extra fields in metadata not present in template
                        $extra_fields = array();
                        $this->find_template_extra_fields($metadata, $template_keys, '', $extra_fields);
                        
                        $result['extra_fields'] = $extra_fields;
                    }
                }
            }

            $response = array(
                'status' => 'success',
                'result' => $result
            );

            $this->set_response($response, REST_Controller::HTTP_OK);
        }
        catch(Exception $e){
            $error_output = array(
                'status' => 'failed',
                'message' => $e->getMessage()
            );
            $this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Recursively collect all template keys (from items and props)
     * 
     * @param array $items Template items
     * @param string $base_path Base path for nested items
     * @param array &$template_keys Reference to template keys array to populate (key => true mapping)
     */
    private function collect_template_keys($items, $base_path = '', &$template_keys = array())
    {
        if (!is_array($items)){
            return;
        }

        foreach ($items as $item) {
            // Skip custom fields
            if (isset($item['is_custom']) && $item['is_custom']){
                continue;
            }

            $field_key = isset($item['key']) ? $item['key'] : null;
            
            if ($field_key) {
                // Check if key already contains dots (indicating it's a full path)
                // In templates, the 'key' field already contains the full path from root
                if (strpos($field_key, '.') !== false) {
                    // Key is already a full path - use it directly
                    $full_path = $field_key;
                } else {
                    // Key is just a segment - build path from base_path
                    $full_path = $base_path ? $base_path . '.' . $field_key : $field_key;
                }
                
                // Add to template keys
                $template_keys[$full_path] = true;
                
                // Handle array props - collect prop keys
                if (isset($item['props']) && is_array($item['props'])) {
                    foreach ($item['props'] as $prop) {
                        $prop_key_name = isset($prop['key']) ? $prop['key'] : null;
                        $prop_key_full = isset($prop['prop_key']) ? $prop['prop_key'] : null;
                        
                        if ($prop_key_full) {
                            // prop_key contains the full path including parent key and array notation
                            $template_keys[$prop_key_full] = true;
                        } elseif ($prop_key_name && $full_path) {
                            // Build prop path: parent_path[*].prop_key or parent_path[i].prop_key
                            // For props, we need to handle both cases - actual array index and wildcard
                            // Since we're collecting keys that could exist, we'll add both patterns
                            // The wildcard pattern is used during comparison
                            $template_keys[$full_path . '.*.' . $prop_key_name] = true;
                        }
                    }
                }
                
                // Recurse into nested items
                if (isset($item['items']) && is_array($item['items'])) {
                    $this->collect_template_keys($item['items'], $full_path, $template_keys);
                }
            }
        }
    }

    /**
     * Recursively find fields in metadata that are not defined in the template
     * 
     * @param mixed $data Current metadata node
     * @param array $template_keys Array of template keys (key => true mapping)
     * @param string $base_path Base path for tracking field location (dot notation)
     * @param array &$extra_fields Reference to extra fields array to populate
     */
    private function find_template_extra_fields($data, $template_keys, $base_path = '', &$extra_fields = array())
    {
        // Skip if data is null or not an array/object
        if ($data === null || (!is_array($data) && !is_object($data))) {
            return;
        }

        // Exclude /additional section - it's an object type meant to store arbitrary fields
        // Skip processing if we're already inside /additional path
        $dot_path = str_replace('/', '.', $base_path);
        if ($base_path === 'additional' || $dot_path === 'additional' || strpos($base_path, 'additional.') === 0 || strpos($dot_path, 'additional.') === 0) {
            return;
        }

        // Convert object to array for easier processing
        if (is_object($data)) {
            $data = (array) $data;
        }

        // Check each field in metadata
        foreach ($data as $key => $value) {
            $field_path = $base_path ? $base_path . '.' . $key : $key;
            $dot_field_path = str_replace('/', '.', $field_path);

            // Skip if this is a schema-internal field
            if (in_array($key, array('$schema', '$id', '$ref'))) {
                continue;
            }

            // Skip the /additional field itself and anything inside it
            if ($key === 'additional' || $field_path === 'additional' || strpos($field_path, 'additional.') === 0) {
                continue;
            }

            // Check if field exists in template
            // Check exact match first
            $in_template = isset($template_keys[$field_path]);
            
            // Also check with dot notation variations
            if (!$in_template) {
                $in_template = isset($template_keys[$dot_field_path]);
            }
            
            // Check if field_path matches any template key exactly (case-insensitive for robustness)
            if (!$in_template) {
                foreach ($template_keys as $template_key => $dummy) {
                    if (strcasecmp($field_path, $template_key) === 0) {
                        $in_template = true;
                        break;
                    }
                }
            }
            
            // Special case: Check if the field itself is a key in template_keys
            // This handles section_containers like study_desc which have key: "study_desc" in template
            if (!$in_template && isset($template_keys[$key])) {
                // If the key itself (without path) is in template_keys, it's in the template
                // This handles root-level fields like study_desc
                $in_template = true;
            }
            
            // Check for array wildcard pattern (e.g., parent.*.prop matches parent[0].prop, parent[1].prop, etc.)
            if (!$in_template && is_numeric($key)) {
                // This is an array index - check if parent path exists with wildcard
                $parent_path = $base_path;
                if ($parent_path) {
                    // Try pattern: parent.* (matches parent[0], parent[1], etc.)
                    if (isset($template_keys[$parent_path . '.*'])) {
                        $in_template = true;
                    }
                    // Also try to match array prop pattern (e.g., parent.*.prop_key matches parent[0].prop_key)
                    foreach ($template_keys as $template_key => $dummy) {
                        if (strpos($template_key, $parent_path . '.*.') === 0) {
                            $in_template = true;
                            break;
                        }
                    }
                }
            } elseif (!$in_template && $base_path && strpos($base_path, '[') !== false) {
                // We're inside an array item - check for array prop patterns
                // Extract parent path without array index (e.g., "parent[0]" -> "parent")
                $parent_base = preg_replace('/\[\d+\]$/', '', $base_path);
                // Check if any template key matches this parent with wildcard (e.g., "parent.*.prop")
                foreach ($template_keys as $template_key => $dummy) {
                    if (strpos($template_key, $parent_base . '.*.') === 0) {
                        // Template has props for this array - check if this field is a prop
                        $prop_part = substr($template_key, strlen($parent_base . '.*.'));
                        if ($prop_part === $key || strpos($template_key, $parent_base . '.*.' . $key) === 0) {
                            $in_template = true;
                            break;
                        }
                    }
                }
            }

            // Check if field is a container with children in the template
            // This check applies to ALL fields (whether in template or not) because
            // containers like doc_desc and study_desc should be excluded even if they're
            // section_containers in the template (they're not form fields, just organizational)
            $is_container_with_children = false;
            if (is_array($value) || is_object($value)) {
                // Check if this field has any children in the template
                // This will catch cases like doc_desc (not in template but has doc_desc.title, etc.)
                // and study_desc (in template but also has study_desc.title_statement, etc.)
                $is_container_with_children = $this->has_template_children($field_path, $template_keys);
            }

            // A field should NOT be reported as extra if:
            // 1. It's in the template (exact match or container key), OR
            // 2. It's a container with children in the template (like doc_desc, study_desc)
            // Note: Even if a field is in the template (like study_desc), we still check for children
            // because section_containers are organizational and their children are the actual fields
            if (!$in_template && !$is_container_with_children) {
                // Field not in template and not a container with children - add to extra fields
                $value_preview = $this->get_value_preview($value);
                
                $extra_fields[] = array(
                    'path' => '/' . str_replace('.', '/', str_replace('[', '/', str_replace(']', '', $field_path))),
                    'key' => $key,
                    'location' => $base_path === '' ? 'root' : str_replace('.', '/', str_replace('[', '/', str_replace(']', '', $base_path))),
                    'value' => $value,
                    'value_preview' => $value_preview,
                    'type' => $this->get_php_type($value)
                );
            }
            
            // Recurse into nested structures for both template fields and containers with children
            if (($in_template || $is_container_with_children) && (is_array($value) || is_object($value))) {
                // Check if it's an array
                if (is_array($value) && !$this->is_assoc_array($value)) {
                    // Numeric array - check each item
                    foreach ($value as $index => $item) {
                        // Build item path with array notation
                        $item_path = $field_path . '[' . $index . ']';
                        // Also check for array props within this item
                        if (is_array($item) || is_object($item)) {
                            $this->find_template_extra_fields($item, $template_keys, $item_path, $extra_fields);
                        }
                    }
                } else {
                    // Associative array or object - recurse into properties
                    $this->find_template_extra_fields($value, $template_keys, $field_path, $extra_fields);
                }
            }
        }
    }

    /**
     * Check if a field path has any children fields present in the template
     * Used to detect container fields (e.g., doc_desc, study_desc) that themselves
     * are not in the template but contain children that are in the template
     * 
     * @param string $field_path Field path to check (dot notation)
     * @param array $template_keys Array of template keys (key => true mapping)
     * @return bool True if any child fields exist in template
     */
    private function has_template_children($field_path, $template_keys)
    {
        // Build prefix patterns to match children
        $prefix1 = $field_path . '.';
        $prefix2 = $field_path . '.*.'; // For array props
        
        // Check if any template key starts with this field's path prefix
        foreach ($template_keys as $template_key => $dummy) {
            // Check if template key is a child of this field (exact prefix match)
            if (strpos($template_key, $prefix1) === 0) {
                return true;
            }
            // Check for array prop pattern (e.g., parent.*.child matches parent as container)
            if (strpos($template_key, $prefix2) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Recursively find fields in metadata that are not defined in the schema
     * 
     * @param mixed $data Current metadata node
     * @param array $schema Current schema node
     * @param string $base_path Base path for tracking field location
     * @param array &$extra_fields Reference to extra fields array to populate
     */
    private function find_extra_fields($data, $schema, $base_path = '', &$extra_fields = array())
    {
        // Skip if data is null or not an array/object
        if ($data === null || (!is_array($data) && !is_object($data))) {
            return;
        }

        // Exclude /additional section - it's an object type meant to store arbitrary fields
        // Skip processing if we're already inside /additional path
        if ($base_path === '/additional' || strpos($base_path, '/additional/') === 0) {
            return;
        }

        // Convert object to array for easier processing
        if (is_object($data)) {
            $data = (array) $data;
        }

        // Get schema properties
        $schema_properties = array();
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $schema_properties = $schema['properties'];
        }

        // Handle allOf - merge properties from allOf schemas
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $sub_schema) {
                if (isset($sub_schema['properties']) && is_array($sub_schema['properties'])) {
                    $schema_properties = array_merge($schema_properties, $sub_schema['properties']);
                }
            }
        }

        // Check each field in metadata
        foreach ($data as $key => $value) {
            $field_path = $base_path ? $base_path . '/' . $key : '/' . $key;

            // Skip if this is a schema-internal field
            if (in_array($key, array('$schema', '$id', '$ref'))) {
                continue;
            }

            // Skip the /additional field itself and anything inside it
            if ($key === 'additional' || $field_path === '/additional' || strpos($field_path, '/additional/') === 0) {
                continue;
            }

            // Check if field exists in schema
            if (!isset($schema_properties[$key])) {
                // Field not in schema - add to extra fields
                $value_preview = $this->get_value_preview($value);
                
                $extra_fields[] = array(
                    'path' => $field_path,
                    'key' => $key,
                    'location' => $base_path === '' ? 'root' : $base_path,
                    'value' => $value,
                    'value_preview' => $value_preview,
                    'type' => $this->get_php_type($value)
                );
            } else {
                // Field exists in schema - recurse into nested structures
                $field_schema = $schema_properties[$key];
                
                // Handle nested objects/arrays
                if (is_array($value) || is_object($value)) {
                    // Check if it's an array of items
                    if (isset($field_schema['items'])) {
                        // Array type - check items
                        $items_schema = $field_schema['items'];
                        // Resolve $ref if present
                        if (isset($items_schema['$ref'])) {
                            $items_schema = $this->resolve_schema_ref($items_schema['$ref'], $schema);
                        }
                        
                        // Check each array item
                        if (is_array($value)) {
                            foreach ($value as $index => $item) {
                                $item_path = $field_path . '/' . $index;
                                // Skip if this would be inside /additional
                                if (strpos($item_path, '/additional/') === 0) {
                                    continue;
                                }
                                if (is_array($item) || is_object($item)) {
                                    $this->find_extra_fields($item, $items_schema, $item_path, $extra_fields);
                                }
                            }
                        }
                    } elseif (isset($field_schema['type']) && $field_schema['type'] === 'object') {
                        // Object type - recurse into properties
                        // Skip if this is the 'additional' field itself
                        if ($key === 'additional') {
                            continue;
                        }
                        // Resolve $ref if present
                        if (isset($field_schema['$ref'])) {
                            $field_schema = $this->resolve_schema_ref($field_schema['$ref'], $schema);
                        }
                        
                        $this->find_extra_fields($value, $field_schema, $field_path, $extra_fields);
                    }
                }
            }
        }
    }

    /**
     * Validate field data types against schema-defined types
     * 
     * @param mixed $data Current metadata node
     * @param array $schema Current schema node
     * @param string $base_path Base path for tracking field location
     * @param array &$type_issues Reference to type issues array to populate
     */
    private function validate_field_types($data, $schema, $base_path = '', &$type_issues = array(), $template_data = null)
    {
        // Skip if data is null
        if ($data === null) {
            return;
        }

        // Convert object to array for easier processing
        if (is_object($data)) {
            $data = (array) $data;
        }

        // Get schema properties
        $schema_properties = array();
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $schema_properties = $schema['properties'];
        }

        // Handle allOf - merge properties from allOf schemas
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $sub_schema) {
                if (isset($sub_schema['properties']) && is_array($sub_schema['properties'])) {
                    $schema_properties = array_merge($schema_properties, $sub_schema['properties']);
                }
            }
        }

        // Check each field in metadata
        foreach ($data as $key => $value) {
            $field_path = $base_path ? $base_path . '/' . $key : '/' . $key;

            // Skip if this is a schema-internal field
            if (in_array($key, array('$schema', '$id', '$ref'))) {
                continue;
            }

            // Check if field exists in schema
            if (isset($schema_properties[$key])) {
                $field_schema = $schema_properties[$key];
                
                // Resolve $ref if present
                if (isset($field_schema['$ref'])) {
                    $field_schema = $this->resolve_schema_ref($field_schema['$ref'], $schema);
                }
                
                // Get expected type from schema
                $expected_type = $this->get_schema_type($field_schema);
                
                // Get actual type of the value
                $actual_type = $this->get_php_type($value);
                
                // Map PHP types to JSON Schema types
                $actual_schema_type = $this->map_php_to_schema_type($actual_type, $value);
                
                // Special check: array stored as object with numeric keys
                if ($expected_type === 'array' && $actual_schema_type === 'object' && $this->is_object_with_numeric_keys($value)) {
                    $type_issues[] = array(
                        'type' => 'array_as_object',
                        'property' => $key,
                        'path' => $field_path,
                        'message' => "Array is incorrectly stored as object with numeric keys. Should be an array.",
                        'expected_type' => $expected_type,
                        'actual_type' => $actual_schema_type,
                        'value_preview' => $this->get_value_preview($value),
                        'fixable' => true
                    );
                } elseif ($expected_type && $actual_schema_type && $expected_type !== $actual_schema_type) {
                    // Check if this type mismatch is fixable
                    // Pass field_schema and full schema context to check array items type
                    $fixable = $this->is_type_mismatch_fixable($expected_type, $actual_schema_type, $value, $field_schema, $schema);
                    
                    $issue_data = array(
                        'type' => 'type_mismatch',
                        'property' => $key,
                        'path' => $field_path,
                        'message' => "Type mismatch: expected '{$expected_type}', found '{$actual_schema_type}'",
                        'expected_type' => $expected_type,
                        'actual_type' => $actual_schema_type,
                        'value_preview' => $this->get_value_preview($value),
                        'fixable' => $fixable
                    );
                    
                    // For non-fixable issues, include field definition from schema
                    if (!$fixable && $field_schema) {
                        $issue_data['field_definition'] = $this->get_field_definition_for_issue($field_schema, $field_path);
                    }
                    
                    $type_issues[] = $issue_data;
                }
                
                // Recurse into nested structures
                if (is_array($value) || is_object($value)) {
                    // Check if it's an array of items
                    if (isset($field_schema['items'])) {
                        // Array type - validate each item
                        $items_schema = $field_schema['items'];
                        // Resolve $ref if present
                        if (isset($items_schema['$ref'])) {
                            $items_schema = $this->resolve_schema_ref($items_schema['$ref'], $schema);
                        }
                        
                        // Check each array item
                        if (is_array($value)) {
                            foreach ($value as $index => $item) {
                                $item_path = $field_path . '/' . $index;
                                if (is_array($item) || is_object($item)) {
                                    $this->validate_field_types($item, $items_schema, $item_path, $type_issues, $template_data);
                                } else {
                                    // Check type of primitive array items
                                    $item_expected_type = $this->get_schema_type($items_schema);
                                    $item_actual_type = $this->map_php_to_schema_type($this->get_php_type($item), $item);
                                    if ($item_expected_type && $item_actual_type && $item_expected_type !== $item_actual_type) {
                                        $type_issues[] = array(
                                            'type' => 'type_mismatch',
                                            'property' => $key . '[' . $index . ']',
                                            'path' => $item_path,
                                            'message' => "Type mismatch: expected '{$item_expected_type}', found '{$item_actual_type}'",
                                            'expected_type' => $item_expected_type,
                                            'actual_type' => $item_actual_type,
                                            'value_preview' => $this->get_value_preview($item)
                                        );
                                    }
                                }
                            }
                        }
                                } elseif (isset($field_schema['type']) && $field_schema['type'] === 'object') {
                                    // Object type - recurse into properties
                                    $this->validate_field_types($value, $field_schema, $field_path, $type_issues, $template_data);
                                }
                }
            }
        }
    }

    /**
     * Get the expected type from a schema definition
     * 
     * @param array $schema Schema definition
     * @return string|null Schema type (string, number, integer, boolean, array, object, null)
     */
    private function get_schema_type($schema)
    {
        if (!is_array($schema)) {
            return null;
        }
        
        // Check explicit type
        if (isset($schema['type'])) {
            if (is_array($schema['type'])) {
                // Multiple types allowed - return first one or most specific
                return $schema['type'][0];
            }
            return $schema['type'];
        }
        
        // Check for items (indicates array)
        if (isset($schema['items'])) {
            return 'array';
        }
        
        // Check for properties (indicates object)
        if (isset($schema['properties'])) {
            return 'object';
        }
        
        // Can't determine type
        return null;
    }

    /**
     * Check if an object/array has numeric keys that indicate it should be an array
     * 
     * @param mixed $value Value to check
     * @return bool True if value is an object/associative array with numeric keys
     */
    private function is_object_with_numeric_keys($value)
    {
        if (!is_array($value) && !is_object($value)) {
            return false;
        }
        
        if (empty($value)) {
            return false;
        }
        
        // Convert to array if object
        if (is_object($value)) {
            $value = (array) $value;
        }
        
        $keys = array_keys($value);
        
        // Check if all keys are numeric (as strings or integers)
        $all_numeric = true;
        $numeric_keys = array();
        
        foreach ($keys as $key) {
            if (is_numeric($key)) {
                $numeric_keys[] = (int) $key;
            } else {
                $all_numeric = false;
                break;
            }
        }
        
        if (!$all_numeric || empty($numeric_keys)) {
            return false;
        }
        
        // Check if keys are sequential starting from 1 or 0
        // Sort numeric keys
        sort($numeric_keys);
        
        // Check if sequential (0,1,2,3... or 1,2,3,4...)
        $is_sequential = true;
        $start = $numeric_keys[0];
        for ($i = 0; $i < count($numeric_keys); $i++) {
            if ($numeric_keys[$i] !== $start + $i) {
                $is_sequential = false;
                break;
            }
        }
        
        return $is_sequential;
    }

    /**
     * Convert an object with numeric keys to an array
     * 
     * @param mixed $value Value to convert
     * @return array Converted array
     */
    private function convert_object_to_array($value)
    {
        if (!is_array($value) && !is_object($value)) {
            return $value;
        }
        
        // Convert to array if object
        if (is_object($value)) {
            $value = (array) $value;
        }
        
        // Get keys and sort them
        $keys = array_keys($value);
        $numeric_keys = array();
        foreach ($keys as $key) {
            if (is_numeric($key)) {
                $numeric_keys[] = (int) $key;
            }
        }
        
        // Sort numeric keys
        sort($numeric_keys);
        
        // Build new array with sequential indices
        $result = array();
        foreach ($numeric_keys as $num_key) {
            $result[] = $value[(string) $num_key];
        }
        
        return $result;
    }

    /**
     * Check if a type mismatch is fixable
     * 
     * @param string $expected_type Expected JSON Schema type
     * @param string $actual_type Actual JSON Schema type
     * @param mixed $value Actual value
     * @param array|null $field_schema Field schema definition (optional, used to check array items type)
     * @param array|null $full_schema Full schema context (optional, used to resolve $ref references)
     * @return bool True if the mismatch can be automatically fixed
     */
    private function is_type_mismatch_fixable($expected_type, $actual_type, $value, $field_schema = null, $full_schema = null)
    {
        // String to Array: wrap string in array
        // BUT: only if array doesn't expect objects
        if ($expected_type === 'array' && $actual_type === 'string') {
            // Check if array expects objects - if so, don't auto-fix
            if ($field_schema && isset($field_schema['items'])) {
                $items_schema = $field_schema['items'];
                // Resolve $ref if present - use full schema context if available
                if (isset($items_schema['$ref'])) {
                    $schema_context = $full_schema ? $full_schema : $field_schema;
                    $items_schema = $this->resolve_schema_ref($items_schema['$ref'], $schema_context);
                }
                // If items type is object, don't auto-fix (too complex)
                if (isset($items_schema['type']) && $items_schema['type'] === 'object') {
                    return false;
                }
                // If items has properties (indicating object structure), don't auto-fix
                if (isset($items_schema['properties']) && is_array($items_schema['properties']) && !empty($items_schema['properties'])) {
                    return false;
                }
            }
            // Safe to fix: array of primitives or unknown items type
            return true;
        }
        
        // Array to String: convert array to string (join or first element)
        if ($expected_type === 'string' && $actual_type === 'array') {
            return true;
        }
        
        // Number/Integer to String: convert to string
        if ($expected_type === 'string' && ($actual_type === 'number' || $actual_type === 'integer')) {
            return true;
        }
        
        // String to Number/Integer: try to parse
        if (($expected_type === 'number' || $expected_type === 'integer') && $actual_type === 'string') {
            // Check if string is numeric
            if (is_numeric($value)) {
                return true;
            }
        }
        
        // Boolean to String: convert boolean to string representation
        if ($expected_type === 'string' && $actual_type === 'boolean') {
            return true;
        }
        
        // String to Boolean: parse common boolean strings
        if ($expected_type === 'boolean' && $actual_type === 'string') {
            $lower = strtolower(trim($value));
            if (in_array($lower, array('true', 'false', '1', '0', 'yes', 'no', 'on', 'off'))) {
                return true;
            }
        }
        
        // Null to any type: can be set to appropriate default
        if ($actual_type === 'null') {
            return true;
        }
        
        return false;
    }

    /**
     * Convert value to expected type
     * 
     * @param mixed $value Current value
     * @param string $expected_type Expected JSON Schema type
     * @param string $actual_type Current actual type
     * @return mixed Converted value
     */
    private function convert_value_to_type($value, $expected_type, $actual_type)
    {
        // String to Array: wrap in array
        if ($expected_type === 'array' && $actual_type === 'string') {
            return array($value);
        }
        
        // Array to String: convert array to string
        if ($expected_type === 'string' && $actual_type === 'array') {
            if (empty($value)) {
                return '';
            }
            // If array has one element, use that element (convert to string if needed)
            if (count($value) === 1) {
                $first = reset($value);
                if (is_scalar($first)) {
                    return (string) $first;
                }
                // If element is object/array, convert to JSON string
                return json_encode($first);
            }
            // If array has multiple elements, join them (if all are strings) or use first element
            $all_strings = true;
            foreach ($value as $item) {
                if (!is_string($item) && !is_numeric($item)) {
                    $all_strings = false;
                    break;
                }
            }
            if ($all_strings) {
                return implode(', ', $value);
            }
            // Otherwise, use first element converted to string
            $first = reset($value);
            if (is_scalar($first)) {
                return (string) $first;
            }
            return json_encode($first);
        }
        
        // Number/Integer to String
        if ($expected_type === 'string' && ($actual_type === 'number' || $actual_type === 'integer')) {
            return (string) $value;
        }
        
        // String to Number
        if ($expected_type === 'number' && $actual_type === 'string') {
            if (is_numeric($value)) {
                return (float) $value;
            }
            return $value; // Return as-is if not numeric
        }
        
        // String to Integer
        if ($expected_type === 'integer' && $actual_type === 'string') {
            if (is_numeric($value)) {
                return (int) $value;
            }
            return $value; // Return as-is if not numeric
        }
        
        // Boolean to String
        if ($expected_type === 'string' && $actual_type === 'boolean') {
            return $value ? 'true' : 'false';
        }
        
        // String to Boolean
        if ($expected_type === 'boolean' && $actual_type === 'string') {
            $lower = strtolower(trim($value));
            if (in_array($lower, array('true', '1', 'yes', 'on'))) {
                return true;
            } elseif (in_array($lower, array('false', '0', 'no', 'off'))) {
                return false;
            }
            return (bool) $value; // Fallback
        }
        
        // Null to type: return appropriate default
        if ($actual_type === 'null') {
            switch ($expected_type) {
                case 'string':
                    return '';
                case 'array':
                    return array();
                case 'object':
                    return array();
                case 'number':
                case 'integer':
                    return 0;
                case 'boolean':
                    return false;
                default:
                    return null;
            }
        }
        
        return $value; // Return as-is if no conversion available
    }

    /**
     * Map PHP type to JSON Schema type
     * 
     * @param string $php_type PHP type (string, integer, double, boolean, array, object, NULL)
     * @param mixed $value Actual value for additional checking
     * @return string|null JSON Schema type (string, number, integer, boolean, array, object, null)
     */
    private function map_php_to_schema_type($php_type, $value)
    {
        switch ($php_type) {
            case 'string':
                return 'string';
            case 'integer':
                return 'integer';
            case 'double':
            case 'float':
                return 'number';
            case 'boolean':
                return 'boolean';
            case 'array':
                // Check if it's an indexed array (list) or associative array (object)
                if (empty($value)) {
                    // Empty array - can't determine, assume array
                    return 'array';
                }
                $keys = array_keys($value);
                // Check if all keys are numeric and sequential starting from 0
                $is_indexed = array_keys($keys) === $keys && (!empty($keys) ? $keys[0] === 0 : true);
                return $is_indexed ? 'array' : 'object';
            case 'object':
                return 'object';
            case 'NULL':
            case 'null':
                return 'null';
            default:
                return null;
        }
    }

    /**
     * Resolve a $ref in schema (basic implementation)
     * For now, we'll just return the ref path - full resolution would require
     * traversing the schema document structure
     * 
     * @param string $ref Reference path
     * @param array $schema Current schema context
     * @return array Resolved schema or original ref
     */
    private function resolve_schema_ref($ref, $schema)
    {
        // Basic implementation - if ref starts with #/ we can try to resolve
        if (strpos($ref, '#/') === 0) {
            $path = substr($ref, 2); // Remove '#/'
            $parts = explode('/', $path);
            
            $current = $schema;
            foreach ($parts as $part) {
                if (isset($current[$part])) {
                    $current = $current[$part];
                } else {
                    // Can't resolve - return empty schema
                    return array();
                }
            }
            return is_array($current) ? $current : array();
        }
        
        // External ref - can't resolve here, return empty
        return array();
    }

    /**
     * Get a preview of a value for display
     * 
     * @param mixed $value
     * @return string
     */
    private function get_value_preview($value)
    {
        if (is_null($value)) {
            return '(null)';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            $count = is_array($value) ? count($value) : count((array)$value);
            return is_array($value) ? "[Array: $count items]" : "[Object: $count properties]";
        }
        if (is_string($value)) {
            $preview = substr($value, 0, 100);
            if (strlen($value) > 100) {
                $preview .= '...';
            }
            return $preview;
        }
        return (string) $value;
    }

    /**
     * Get PHP type of a value
     * 
     * @param mixed $value
     * @return string
     */
    private function get_php_type($value)
    {
        if (is_array($value)) {
            // Check if it's an indexed array (list) or associative array (object)
            return array_keys($value) === range(0, count($value) - 1) ? 'array' : 'object';
        }
        return gettype($value);
    }

    /**
     * Move extra fields to additional section
     * 
     * @param int $sid Project ID
     */
    function move_to_additional_post($sid=null)
    {
        try{
            $sid = $this->get_sid($sid);
            $project = $this->Editor_model->get_row($sid);

            if (!$project){
                throw new Exception("project not found");
            }

            $this->editor_acl->user_has_project_access($sid, $permission='edit');

            $this->Editor_model->check_project_editable($sid);

            $paths = $this->post('paths');
            if (!is_array($paths) || empty($paths)){
                throw new Exception("paths parameter is required and must be an array");
            }

            $metadata = $project['metadata'];

            // Build JSON Patch operations
            $patches = array();
            $moved_fields = array();
            $errors = array();

            // Move each field to additional
            foreach ($paths as $path) {
                try {
                    // Get value from path
                    $value = $this->get_value_by_path($metadata, $path);
                    
                    if ($value !== null) {
                        // Create field key from path (use last segment)
                        $path_parts = explode('/', trim($path, '/'));
                        $field_key = end($path_parts);
                        
                        // Handle nested paths - preserve structure in additional
                        // Convert path to additional path format
                        $additional_key = $this->create_additional_key($path);
                        
                        // Create additional path in JSON Pointer format
                        $additional_path = '/additional';
                        if (!empty($additional_key)) {
                            $additional_path_parts = explode('.', $additional_key);
                            foreach ($additional_path_parts as $part) {
                                $additional_path .= '/' . $part;
                            }
                        }
                        
                        // Important: Add to additional FIRST, then remove from original
                        // This ensures data is preserved even if remove fails
                        
                        // Add operation: add to additional (JsonPatch creates intermediate paths if needed)
                        $patches[] = array(
                            'op' => 'add',
                            'path' => $additional_path,
                            'value' => $value
                        );
                        
                        // Remove operation: remove from original location
                        $patches[] = array(
                            'op' => 'remove',
                            'path' => $path
                        );
                        
                        $moved_fields[] = array(
                            'path' => $path,
                            'key' => $field_key,
                            'additional_key' => $additional_key,
                            'additional_path' => $additional_path
                        );
                    }
                } catch(Exception $e) {
                    $errors[] = array(
                        'path' => $path,
                        'error' => $e->getMessage()
                    );
                }
            }

            // Apply patches if any using patch_project method
            if (!empty($patches)) {
                $type = $project['type'];
                $this->Editor_model->patch_project($type, $sid, array('patches' => $patches), $validate=false);
            }

            $response = array(
                'status' => 'success',
                'moved_fields' => $moved_fields,
                'errors' => $errors
            );

            $this->set_response($response, REST_Controller::HTTP_OK);
        }
        catch(Exception $e){
            $error_output = array(
                'status' => 'failed',
                'message' => $e->getMessage()
            );
            $this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Fix array-as-object issues by converting objects with numeric keys to arrays
     * 
     * @param int $sid Project ID
     */
    function fix_array_as_object_post($sid=null)
    {
        try{
            $sid = $this->get_sid($sid);
            $project = $this->Editor_model->get_row($sid);

            if (!$project){
                throw new Exception("project not found");
            }

            $this->editor_acl->user_has_project_access($sid, $permission='edit');
            $this->Editor_model->check_project_editable($sid);

            $paths = $this->post('paths');
            if (!is_array($paths) || empty($paths)){
                throw new Exception("paths parameter is required and must be an array");
            }

            $metadata = $project['metadata'];
            $type = $project['type'];
            
            // Resolve canonical type
            $canonical_type = $type;
            try {
                $schema_row = $this->Metadata_schemas_model->get_by_uid($type);
                if ($schema_row && isset($schema_row['uid']) && $schema_row['uid']) {
                    $canonical_type = $schema_row['uid'];
                }
            } catch(Exception $e) {
                // If schema registry lookup fails, use original type
            }

            // Load compiled schema
            $this->load->library('Schema_registry');
            $schema = $this->Metadata_schemas_model->get_by_uid($canonical_type);

            if (!$schema){
                throw new Exception("Schema not found: $canonical_type");
            }

            // Get schema file path using schema registry (handles aliases and custom schemas)
            try {
                $schema_file = $this->Metadata_schemas_model->get_schema_file_path($type);
            } catch(Exception $e) {
                throw new Exception("Schema file not found for type: $type - " . $e->getMessage());
            }

            $schema_dir = $this->Metadata_schemas_model->resolve_schema_path($schema);
            if (!is_dir($schema_dir)) {
                throw new Exception("Schema directory not found: $schema_dir");
            }

            // Get the actual filename from the resolved schema file path
            // This ensures we use the correct filename even if it differs from $schema['filename']
            $actual_filename = basename($schema_file);
            
            // Create a schema object with the correct filename for load_schema_documents
            $schema_for_loading = $schema;
            $schema_for_loading['filename'] = $actual_filename;

            // Get compiled schema
            $documents = $this->schema_registry->load_schema_documents($schema_for_loading, $schema_dir);
            $compiled_schema = $this->schema_registry->inline_schema($actual_filename, $documents, $schema_dir);

            // Build JSON Patch operations for fixing arrays
            $patches = array();
            $fixed_fields = array();
            $errors = array();

            foreach ($paths as $path) {
                try {
                    // Get value from path
                    $value = $this->get_value_by_path($metadata, $path);
                    
                    if ($value !== null) {
                        // Check if it's an object with numeric keys
                        if ($this->is_object_with_numeric_keys($value)) {
                            // Convert to array
                            $converted_value = $this->convert_object_to_array($value);
                            
                            // Add replace operation to patches
                            $patches[] = array(
                                'op' => 'replace',
                                'path' => $path,
                                'value' => $converted_value
                            );
                            
                            $fixed_fields[] = array(
                                'path' => $path,
                                'fixed' => true,
                                'before' => $this->get_value_preview($value),
                                'after' => $this->get_value_preview($converted_value)
                            );
                        } else {
                            $fixed_fields[] = array(
                                'path' => $path,
                                'fixed' => false,
                                'message' => 'Field is not an object with numeric keys'
                            );
                        }
                    } else {
                        $fixed_fields[] = array(
                            'path' => $path,
                            'fixed' => false,
                            'message' => 'Field not found'
                        );
                    }
                } catch(Exception $e) {
                    $errors[] = array(
                        'path' => $path,
                        'error' => $e->getMessage()
                    );
                }
            }

            // Apply patches if any using patch_project method
            if (!empty($patches)) {
                $this->Editor_model->patch_project($type, $sid, array('patches' => $patches), $validate=false);
            }

            $response = array(
                'status' => 'success',
                'fixed_fields' => $fixed_fields,
                'errors' => $errors
            );

            $this->set_response($response, REST_Controller::HTTP_OK);
        }
        catch(Exception $e){
            $error_output = array(
                'status' => 'failed',
                'message' => $e->getMessage()
            );
            $this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Remove extra fields from metadata
     * 
     * @param int $sid Project ID
     */
    function remove_fields_post($sid=null)
    {
        try{
            $sid = $this->get_sid($sid);
            $project = $this->Editor_model->get_row($sid);

            if (!$project){
                throw new Exception("project not found");
            }

            $this->editor_acl->user_has_project_access($sid, $permission='edit');

            $this->Editor_model->check_project_editable($sid);

            $paths = $this->post('paths');
            if (!is_array($paths) || empty($paths)){
                throw new Exception("paths parameter is required and must be an array");
            }

            $metadata = $project['metadata'];

            // Build JSON Patch operations for removing fields
            $patches = array();
            $removed_fields = array();
            $errors = array();

            foreach ($paths as $path) {
                try {
                    // Check if field exists
                    $value = $this->get_value_by_path($metadata, $path);
                    
                    if ($value !== null) {
                        // Add remove operation to patches
                        $patches[] = array(
                            'op' => 'remove',
                            'path' => $path
                        );
                        
                        $removed_fields[] = array(
                            'path' => $path,
                            'removed' => true
                        );
                    } else {
                        $removed_fields[] = array(
                            'path' => $path,
                            'removed' => false,
                            'message' => 'Field not found'
                        );
                    }
                } catch(Exception $e) {
                    $errors[] = array(
                        'path' => $path,
                        'error' => $e->getMessage()
                    );
                }
            }

            // Apply patches if any using patch_project method
            if (!empty($patches)) {
                $type = $project['type'];
                $this->Editor_model->patch_project($type, $sid, array('patches' => $patches), $validate=false);
            }

            $response = array(
                'status' => 'success',
                'removed_fields' => $removed_fields,
                'errors' => $errors
            );

            $this->set_response($response, REST_Controller::HTTP_OK);
        }
        catch(Exception $e){
            $error_output = array(
                'status' => 'failed',
                'message' => $e->getMessage()
            );
            $this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get value from metadata using JSON Pointer path
     * 
     * @param array $data Metadata array
     * @param string $path JSON Pointer path (e.g., /field/nested or /items/0)
     * @return mixed
     */
    private function get_value_by_path($data, $path)
    {
        // Remove leading slash
        $path = ltrim($path, '/');
        if (empty($path)) {
            return $data;
        }

        // Split path into segments
        $parts = explode('/', $path);
        $current = $data;

        foreach ($parts as $part) {
            if (is_null($current)) {
                return null;
            }

            // Handle array indices
            if (is_numeric($part)) {
                $part = (int) $part;
            }

            if (is_array($current) && isset($current[$part])) {
                $current = $current[$part];
            } else {
                return null;
            }
        }

        return $current;
    }

    /**
     * Create a key for additional section from JSON Pointer path
     * Preserves structure but sanitizes for nested storage
     * 
     * @param string $path JSON Pointer path
     * @return string Dot notation key for additional
     */
    private function create_additional_key($path)
    {
        // Remove leading slash
        $path = ltrim($path, '/');
        
        // Replace slashes with dots
        $key = str_replace('/', '.', $path);
        
        // Handle array indices - convert /items/0 to items.0
        // Already handled by str_replace above
        
        return $key;
    }

    /**
     * Get field definition for display in non-fixable issues
     * Extracts relevant information from schema definition
     * 
     * @param array $field_schema Field schema definition
     * @param string $field_path JSON Pointer path to the field
     * @return array Field definition with title, description, type, items, etc.
     */
    private function get_field_definition_for_issue($field_schema, $field_path)
    {
        $definition = array();
        
        // Include full JSON schema definition
        $definition['json_schema'] = $field_schema;
        
        // Extract basic information
        if (isset($field_schema['title'])) {
            $definition['title'] = $field_schema['title'];
        }
        if (isset($field_schema['description'])) {
            $definition['description'] = $field_schema['description'];
        }
        if (isset($field_schema['type'])) {
            $definition['type'] = $field_schema['type'];
        }
        
        // If it's an array, include items schema
        if (isset($field_schema['items'])) {
            $items_schema = $field_schema['items'];
            
            // Resolve $ref if present (basic resolution)
            if (isset($items_schema['$ref'])) {
                // Try to resolve the reference
                $ref = $items_schema['$ref'];
                if (strpos($ref, '#/') === 0) {
                    // Local reference - we'd need the full schema to resolve
                    // For now, just note it's a reference
                    $definition['items'] = array(
                        '$ref' => $ref,
                        'note' => 'Referenced schema definition'
                    );
                } else {
                    $definition['items'] = array('$ref' => $ref);
                }
            } else {
                // Include items type and properties if it's an object
                $items_def = array();
                if (isset($items_schema['type'])) {
                    $items_def['type'] = $items_schema['type'];
                }
                if (isset($items_schema['title'])) {
                    $items_def['title'] = $items_schema['title'];
                }
                if (isset($items_schema['description'])) {
                    $items_def['description'] = $items_schema['description'];
                }
                if (isset($items_schema['properties']) && is_array($items_schema['properties'])) {
                    // Include a summary of properties
                    $items_def['properties'] = array_keys($items_schema['properties']);
                    $items_def['properties_count'] = count($items_schema['properties']);
                }
                if (!empty($items_def)) {
                    $definition['items'] = $items_def;
                }
            }
        }
        
        // Include enum if present
        if (isset($field_schema['enum'])) {
            $definition['enum'] = $field_schema['enum'];
        }
        
        // Include required status
        if (isset($field_schema['required']) && $field_schema['required']) {
            $definition['required'] = true;
        }
        
        return $definition;
    }
}

