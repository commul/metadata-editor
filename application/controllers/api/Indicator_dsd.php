<?php

require(APPPATH.'/libraries/MY_REST_Controller.php');

class Indicator_dsd extends MY_REST_Controller
{
	public function __construct()
	{
		parent::__construct();
		$this->load->helper("date");
		$this->load->model("Editor_model");
		$this->load->model("Indicator_dsd_model");
		
		$this->load->library("Editor_acl");
		$this->is_authenticated_or_die();
		$this->api_user = $this->api_user();
	}

	//override authentication to support both session authentication + api keys
	function _auth_override_check()
	{
		if ($this->session->userdata('user_id')){
			return true;
		}
		parent::_auth_override_check();
	}

	/**
	 * 
	 * List all DSD columns for a project
	 * 
	 * GET /api/indicator_dsd/{sid}
	 * Query params: detailed (0|1), offset (default: 0), limit (default: null - all)
	 * 
	 */
	function index_get($sid = null)
	{
		try{
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);
			
			$detailed = (int)$this->input->get("detailed");
			$offset = (int)$this->input->get("offset");
			$limit = $this->input->get("limit");

			if ($limit !== null) {
				$limit = (int)$limit;
			}

			$columns = $this->Indicator_dsd_model->select_all($sid, $detailed, $offset, $limit);
			
			$response = array(
				'columns' => $columns
			);

			// Include total count if pagination is used
			if ($limit !== null && $limit > 0) {
				// Note: count method can be added to model later if needed
				$response['offset'] = $offset;
				$response['limit'] = $limit;
			}

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
	 * 
	 * Get single DSD column by ID
	 * 
	 * GET /api/indicator_dsd/{sid}/{id}
	 * 
	 */
	function single_get($sid = null, $id = null)
	{
		try{
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			if (!$id) {
				throw new Exception("Column ID is required");
			}

			$column = $this->Indicator_dsd_model->get_row($sid, $id);

			if (!$column) {
				throw new Exception("Column not found");
			}

			$response = array(
				'column' => $column
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
	 * 
	 * Create new DSD column
	 * 
	 * POST /api/indicator_dsd/{sid}
	 * 
	 */
	function index_post($sid = null)
	{
		try{
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);
			
			$options = (array)$this->raw_json_input();
			$user_id = $this->get_api_user_id();

			if (empty($options)) {
				throw new Exception("Column data is required");
			}

			// Set created_by if not provided
			if (!isset($options['created_by'])) {
				$options['created_by'] = $user_id;
			}

			// Validate (optional validation - no required fields)
			// Validation will be done via separate endpoint
			// $this->Indicator_dsd_model->validate($options, $is_new = true);

			// Insert
			$id = $this->Indicator_dsd_model->insert($sid, $options);

			$response = array(
				'status' => 'success',
				'id' => $id,
				'message' => 'Column created successfully'
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
	 * 
	 * Update existing DSD column
	 * 
	 * POST /api/indicator_dsd/{sid}/{id}
	 * 
	 */
	function update_post($sid = null, $id = null)
	{
		try{
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);
			
			if (!$id) {
				throw new Exception("Column ID is required");
			}

			// Verify the column exists before attempting update
			$existing = $this->Indicator_dsd_model->get_row($sid, $id, false);
			if (!$existing) {
				throw new Exception("Column with ID {$id} not found for project {$sid}");
			}

			$options = (array)$this->raw_json_input();
			$user_id = $this->get_api_user_id();

			if (empty($options)) {
				throw new Exception("Column data is required");
			}

			// Set changed_by if not provided
			if (!isset($options['changed_by'])) {
				$options['changed_by'] = $user_id;
			}

			// Validate (optional validation - no required fields)
			// Validation will be done via separate endpoint
			// $this->Indicator_dsd_model->validate($options, $is_new = false);

			// Update
			$this->Indicator_dsd_model->update($sid, $id, $options);

			$response = array(
				'status' => 'success',
				'id' => $id,
				'message' => 'Column updated successfully'
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
	 * 
	 * Delete DSD columns
	 * 
	 * DELETE /api/indicator_dsd/{sid}
	 * Body: { "sid": 1, "ids": [1, 2, 3] }
	 * 
	 */
	function index_delete($sid = null)
	{
		$this->delete_columns($sid);
	}

	/**
	 * 
	 * Delete DSD columns (POST)
	 * 
	 * POST /api/indicator_dsd/delete/{sid}
	 * Body: { "sid": 1, "ids": [1, 2, 3] }
	 * 
	 */
	function delete_post($sid = null)
	{
		$this->delete_columns($sid);
	}

	/**
	 * 
	 * Internal method to handle column deletion
	 * 
	 */
	private function delete_columns($sid = null)
	{
		try{
			$data = (array)$this->raw_json_input();
			
			// Get sid from payload (required)
			if (!isset($data['sid'])) {
				throw new Exception("Project ID (sid) is required in request body");
			}
			
			$body_sid = (int)$data['sid'];
			
			// Validate URL parameter matches body if URL parameter is provided
			if ($sid !== null && (int)$sid !== $body_sid) {
				throw new Exception("Project ID in URL must match Project ID in request body");
			}
			
			$sid = $body_sid;
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);
			
			if (!isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
				throw new Exception("Column IDs array is required");
			}

			$result = $this->Indicator_dsd_model->delete($sid, $data['ids']);

			$response = array(
				'status' => 'success',
				'message' => 'Columns deleted successfully',
				'rows_deleted' => $result['rows']
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
	 * 
	 * Import CSV file to create/update DSD columns
	 * 
	 * POST /api/indicator_dsd/import/{sid}
	 * Body: multipart/form-data
	 *   - file: CSV file
	 *   - column_mappings: JSON string with column mappings
	 *   - overwrite_existing: 0|1
	 *   - skip_existing: 0|1
	 * 
	 */
	function import_post($sid = null)
	{
		try{
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);
			
			// Validate file upload
			if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
				throw new Exception("CSV file is required");
			}

			$file = $_FILES['file'];
			$file_name = $file['name'];
			$file_tmp = $file['tmp_name'];

			// Validate file type
			$file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
			if ($file_ext !== 'csv') {
				throw new Exception("Only CSV files are supported");
			}

			// Get column mappings
			$column_mappings_json = $this->input->post('column_mappings');
			if (empty($column_mappings_json)) {
				throw new Exception("Column mappings are required");
			}

			$column_mappings = json_decode($column_mappings_json, true);
			if (!is_array($column_mappings)) {
				throw new Exception("Invalid column mappings format");
			}

			$overwrite_existing = (int)$this->input->post('overwrite_existing');
			$skip_existing = (int)$this->input->post('skip_existing');
			$indicator_idno = $this->input->post('indicator_idno');
			$required_field_label_columns_json = $this->input->post('required_field_label_columns');
			$required_field_label_columns = array();
			if (!empty($required_field_label_columns_json)) {
				$decoded = json_decode($required_field_label_columns_json, true);
				if (is_array($decoded)) {
					$required_field_label_columns = $decoded;
				}
			}
			$user_id = $this->get_api_user_id();

			// Process CSV and create/update columns
			// This will also upload and store the CSV file with standard name
			$result = $this->Indicator_dsd_model->import_csv(
				$sid,
				$file_tmp,
				$column_mappings,
				$overwrite_existing,
				$skip_existing,
				$user_id,
				$indicator_idno,
				$required_field_label_columns
			);

			$response = array(
				'status' => 'success',
				'sid' => $sid,
				'message' => 'CSV imported successfully',
				'created' => $result['created'],
				'updated' => $result['updated'],
				'skipped' => $result['skipped'],
				'errors' => $result['errors']
			);
			if (isset($result['rows_imported'])) {
				$response['rows_imported'] = (int) $result['rows_imported'];
			}

			// Include file information if file was stored
			if (isset($result['file_id'])) {
				$response['file_id'] = $result['file_id'];
				$response['file_name'] = $result['file_name'];
			}

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
	 * 
	 * Validate DSD structure
	 * 
	 * GET /api/indicator_dsd/validate/{sid}
	 * 
	 */
	function validate_get($sid = null)
	{
		try{
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);
			
			$result = $this->Indicator_dsd_model->validate_dsd($sid);
			
			$response = array(
				'status' => $result['valid'] ? 'success' : 'failed',
				'valid' => $result['valid'],
				'errors' => $result['errors'],
				'warnings' => $result['warnings'],
				'summary' => $result['summary']
			);

			// Always return 200 for validation report, regardless of validation result
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
	 * 
	 * Get chart data for visualization
	 * 
	 * GET /api/indicator_dsd/chart-data/{sid}
	 * Query params: geography (comma-separated), time_period_start, time_period_end
	 * 
	 */
	function chart_data_get($sid = null)
	{
		try{
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);
			
			// Get filter parameters
			$filters = array();
			
			$geography = $this->input->get('geography');
			if ($geography) {
				$filters['geography'] = is_array($geography) ? $geography : explode(',', $geography);
				// Trim whitespace
				$filters['geography'] = array_map('trim', $filters['geography']);
			}
			
			$time_period_start = $this->input->get('time_period_start');
			if ($time_period_start) {
				$filters['time_period_start'] = trim($time_period_start);
			}
			
			$time_period_end = $this->input->get('time_period_end');
			if ($time_period_end) {
				$filters['time_period_end'] = trim($time_period_end);
			}

			$chart_data = $this->Indicator_dsd_model->get_chart_data($sid, $filters);
			
			$response = array(
				'status' => 'success',
				'data' => $chart_data
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
	 * Populate code_list for all DSD columns from the indicator CSV file.
	 * For each column: code = value from CSV; label = value_label_column if set, else code.
	 *
	 * POST /api/indicator_dsd/populate_code_lists/{sid}
	 */
	function populate_code_lists_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$user_id = $this->get_api_user_id();
			$result = $this->Indicator_dsd_model->populate_code_lists_from_csv($sid, $user_id);

			$response = array(
				'status' => count($result['errors']) === 0 ? 'success' : 'partial',
				'message' => count($result['errors']) === 0
					? 'Code lists populated from CSV.'
					: 'Code lists updated with some errors.',
				'updated' => $result['updated'],
				'skipped' => $result['skipped'],
				'errors' => $result['errors']
			);

			$this->set_response($response, REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$error_output = array(
				'status' => 'failed',
				'message' => $e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}
}
