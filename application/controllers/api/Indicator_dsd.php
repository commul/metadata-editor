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
	 * Query params: detailed (0|1), offset (default: 0), limit (default: null - all),
	 *   resolve_codelists (0|1) — when 1, expand global/local linked codelists into each column's code_list (chart filters)
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

			if ((int) $this->input->get('resolve_codelists') === 1) {
				$columns = $this->Indicator_dsd_model->enrich_columns_resolved_code_lists($sid, $columns);
			}
			
			$response = array(
				'columns' => $columns
			);

			$this->config->load('indicator_dsd', true);
			$response['dictionaries'] = array(
				'time_period_formats' => $this->config->item('dsd_time_period_formats', 'indicator_dsd') ?: array(),
				'freq_codes' => $this->config->item('dsd_freq_codes', 'indicator_dsd') ?: array(),
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

			// Time period / FREQ consistency is enforced by validate_dsd (Validation tab), not on each save,
			// so users can set column_type to time_period and fill format & metadata.freq in any order.
			$this->Indicator_dsd_model->update($sid, $id, $options, false);

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
	 * POST /api/indicator_dsd/dsd_import/{sid}
	 * Body: multipart/form-data
	 *   - file: CSV file
	 *   - column_mappings: JSON string with column mappings
	 *   - overwrite_existing: 0|1
	 *   - skip_existing: 0|1
	 *
	 * When multipart file is omitted, uses data/indicator_staging_upload.csv from the project folder (copy to temp; staging file is not mutated).
	 *
	 * On success (no import errors), drops project_{sid}.staging in DuckDB via FastAPI and deletes data/indicator_staging_upload.csv when present.
	 *
	 */
	function dsd_import_post($sid = null)
	{
		$tmp_staging_copy = null;

		try{
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
				$file = $_FILES['file'];
				$file_name = $file['name'];
				$file_tmp = $file['tmp_name'];

				$file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
				if ($file_ext !== 'csv') {
					throw new Exception("Only CSV files are supported");
				}
			}
			else {
				$src = $this->indicator_staging_upload_realpath($sid);
				if (!$src) {
					throw new Exception("CSV file is required (upload a file, or ensure the staging CSV exists on the server)");
				}
				$tmp_staging_copy = tempnam(sys_get_temp_dir(), 'me_ind_dsd_');
				if ($tmp_staging_copy === false || ! @copy($src, $tmp_staging_copy)) {
					throw new Exception("Could not read saved staging CSV");
				}
				$file_tmp = $tmp_staging_copy;
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

		// After successful DSD import: drop DuckDB staging (data already in timeseries via promote) and remove wizard CSV.
		if (empty($result['errors'])) {
			// Auto-populate local codelists for columns where a label column was mapped.
			// Timeseries is already promoted at this point; errors are non-fatal warnings.
			if (!empty($result['local_codelists_pending'])) {
				$cl_result = $this->Indicator_dsd_model->populate_local_codelists_from_timeseries($sid, $user_id);
				$response['local_codelists_populated'] = isset($cl_result['updated']) ? (int) $cl_result['updated'] : 0;
				$cl_warnings = array();
				if (!empty($cl_result['errors'])) {
					foreach ($cl_result['errors'] as $e) {
						$cl_warnings[] = 'Codelist: ' . $e;
					}
				}
				if (!empty($cl_result['warnings'])) {
					foreach ($cl_result['warnings'] as $w) {
						$cl_warnings[] = 'Codelist: ' . $w;
					}
				}
				if (!empty($cl_warnings)) {
					$response['warnings'] = isset($response['warnings']) ? array_merge($response['warnings'], $cl_warnings) : $cl_warnings;
				}
			}

			$cleanup_warnings = array();
			$this->load->library('indicator_duckdb_service');
			$drop = $this->indicator_duckdb_service->draft_drop($sid);
			if (! is_array($drop) || ! empty($drop['error'])) {
				$cleanup_warnings[] = isset($drop['message'])
					? 'DuckDB staging was not removed: ' . $drop['message']
					: 'DuckDB staging cleanup request failed';
			}
			$csv_path = $this->indicator_staging_upload_realpath($sid);
			if ($csv_path !== null && is_file($csv_path) && ! @unlink($csv_path)) {
				$cleanup_warnings[] = 'Could not delete saved staging CSV file on disk';
			}
			if (! empty($cleanup_warnings)) {
				$response['warnings'] = isset($response['warnings']) ? array_merge($response['warnings'], $cleanup_warnings) : $cleanup_warnings;
			}
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
		finally {
			if ($tmp_staging_copy !== null && is_file($tmp_staging_copy)) {
				@unlink($tmp_staging_copy);
			}
		}
	}

	/**
	 * Validate DSD structure, then data (column presence vs DuckDB: published timeseries or staging) only if structure is valid.
	 * Observation-key uniqueness runs on published timeseries only via DuckDB aggregates (POST …/observation-key-validate); time × geography/dimensions/measure/periodicity; not attributes/annotations.
	 *
	 * GET /api/indicator_dsd/validate/{sid}
	 *
	 * Response includes `structure` and `data_validation` (with `skipped` / `reason` when data checks do not run;
	 * `data_validation.observation_key` describes key columns and unique observation counts when applicable).
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
				'summary' => $result['summary'],
			);
			if (!empty($result['structure'])) {
				$response['structure'] = $result['structure'];
			}
			if (!empty($result['data_validation'])) {
				$response['data_validation'] = $result['data_validation'];
			}

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

			$dimensions_json = $this->input->get('dimensions');
			if ($dimensions_json !== null && $dimensions_json !== '') {
				$decoded = json_decode($dimensions_json, true);
				if (is_array($decoded)) {
					$filters['dimensions'] = $decoded;
				}
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
	 * Chart data (POST JSON for multi-dimension filters).
	 * POST /api/indicator_dsd/chart_data/{sid}
	 * Body: geography[], dimensions{ "COL": ["code"] }, time_period_start/end
	 */
	function chart_data_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			$raw = $this->input->raw_input_stream;
			$data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
			if (!is_array($data)) {
				$data = array();
			}

			$filters = array();
			if (!empty($data['geography']) && is_array($data['geography'])) {
				$filters['geography'] = array_map('trim', $data['geography']);
			}
			if (!empty($data['dimensions']) && is_array($data['dimensions'])) {
				$filters['dimensions'] = $data['dimensions'];
			}
			if (!empty($data['time_period_start'])) {
				$filters['time_period_start'] = trim((string) $data['time_period_start']);
			}
			if (!empty($data['time_period_end'])) {
				$filters['time_period_end'] = trim((string) $data['time_period_end']);
			}
			if (array_key_exists('use_ts_year_for_time_filter', $data)) {
				$filters['use_ts_year_for_time_filter'] = (bool) $data['use_ts_year_for_time_filter'];
			}

			$chart_data = $this->Indicator_dsd_model->get_chart_data($sid, $filters);

			$this->set_response(array(
				'status' => 'success',
				'data' => $chart_data,
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * GET /api/indicator_dsd/chart_facet_counts/{sid}
	 * Dataset-wide row counts per distinct value for chart slice columns (DuckDB); keys = DSD column names.
	 */
	function chart_facet_counts_get($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			$payload = $this->Indicator_dsd_model->get_chart_facet_value_counts($sid);

			$this->set_response(array(
				'status' => 'success',
				'data' => $payload,
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
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

	/**
	 * Populate local_codelist_items from DuckDB for DSD columns with codelist_type = local.
	 * POST /api/indicator_dsd/populate_local_codelists/{sid}
	 */
	function populate_local_codelists_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);
			$user_id = $this->get_api_user_id();
			$result = $this->Indicator_dsd_model->populate_local_codelists_from_timeseries($sid, $user_id);
			$ok = count($result['errors']) === 0;
			$response = array(
				'status' => $ok ? 'success' : 'partial',
				'message' => $ok
					? 'Local codelists updated from timeseries data.'
					: 'Local codelists partially updated; see errors.',
				'updated' => $result['updated'],
				'skipped' => $result['skipped'],
				'errors' => $result['errors'],
				'warnings' => isset($result['warnings']) ? $result['warnings'] : array(),
				'truncated' => isset($result['truncated']) ? $result['truncated'] : array(),
			);
			$this->set_response($response, REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Distinct code/label pairs from published timeseries (for local codelist preview / future UI).
	 * GET /api/indicator_dsd/data_values/{sid}?code_column=COL&label_column=COL2&limit=5000
	 */
	function data_values_get($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);
			$code_column = $this->input->get('code_column');
			if ($code_column === null || trim($code_column) === '') {
				throw new Exception('code_column query parameter is required');
			}
			$label_column = $this->input->get('label_column');
			if ($label_column !== null && trim($label_column) === '') {
				$label_column = null;
			}
			$limit = (int) $this->input->get('limit');
			if ($limit < 1) {
				$limit = 5000;
			}
			if ($limit > 20000) {
				$limit = 20000;
			}
			$this->load->library('indicator_duckdb_service');
			$data = $this->indicator_duckdb_service->timeseries_distinct_pairs($sid, $code_column, $label_column, $limit);
			if (is_array($data) && !empty($data['error'])) {
				throw new Exception(isset($data['message']) ? $data['message'] : 'distinct pairs failed');
			}
			$this->set_response(array(
				'status' => 'success',
				'data' => $data,
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Upload CSV and queue import into the draft buffer (project_{sid}.staging).
	 * POST /api/indicator_dsd/data_draft/{sid}
	 * multipart: file, optional delimiter, optional dsd_columns (JSON array of column name strings)
	 */
	function data_draft_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
				throw new Exception('CSV file is required');
			}

			$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
			if ($ext !== 'csv') {
				throw new Exception('Only CSV files are supported');
			}

			$this->Editor_model->create_project_folder($sid);
			$folder = $this->Editor_model->get_project_folder($sid);
			if (!$folder) {
				throw new Exception('Project folder not available');
			}

			$data_dir = $folder . '/data';
			if (!is_dir($data_dir)) {
				@mkdir($data_dir, 0777, true);
			}

			$dest = $data_dir . '/indicator_staging_upload.csv';
			if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
				throw new Exception('Failed to save uploaded CSV');
			}

			$real_path = realpath($dest);
			if ($real_path === false) {
				throw new Exception('Could not resolve CSV path');
			}

			// Sanitize header row so FastAPI/DuckDB accept field names (e.g. Area.Code -> Area_Code).
			$this->Indicator_dsd_model->rewrite_indicator_csv_headers_for_duckdb($real_path);

			$delimiter = $this->input->post('delimiter');
			if ($delimiter === null || $delimiter === '') {
				$delimiter = ',';
			}
			if (strlen($delimiter) !== 1) {
				$delimiter = ',';
			}

			$dsd_names = null;
			$dsd_raw = $this->input->post('dsd_columns');
			if ($dsd_raw !== null && $dsd_raw !== '') {
				$decoded = json_decode($dsd_raw, true);
				if (is_array($decoded)) {
					$dsd_names = $decoded;
				}
			}
			if (is_array($dsd_names) && count($dsd_names) > 0) {
				$dsd_names = $this->Indicator_dsd_model->normalize_duckdb_staging_column_names($dsd_names);
			}

			$this->load->library('indicator_duckdb_service');
			$queue = $this->indicator_duckdb_service->draft_queue($real_path, $sid, $delimiter, $dsd_names);

			if (is_array($queue) && !empty($queue['error'])) {
				throw new Exception(isset($queue['message']) ? $queue['message'] : 'FastAPI staging request failed');
			}

			if (empty($queue['job_id'])) {
				throw new Exception('FastAPI did not return job_id');
			}

			$this->set_response(array(
				'status' => 'success',
				'job_id' => $queue['job_id'],
				'message' => isset($queue['message']) ? $queue['message'] : 'Staging import queued',
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Draft buffer metadata (resume import UI).
	 * GET /api/indicator_dsd/data_draft_status/{sid}
	 */
	function data_draft_status_get($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			$csv_on_disk = $this->indicator_staging_upload_realpath($sid) !== null;

			$this->load->library('indicator_duckdb_service');
			$raw = $this->indicator_duckdb_service->draft_describe($sid);

			if (is_array($raw) && ! empty($raw['error'])) {
				$this->set_response(array(
					'status' => 'success',
					'data' => array(
						'exists' => false,
						'row_count' => 0,
						'columns' => array(),
						'csv_on_disk' => $csv_on_disk,
						'describe_error' => isset($raw['message']) ? $raw['message'] : 'FastAPI staging describe failed',
					),
				), REST_Controller::HTTP_OK);

				return;
			}

			$exists = ! empty($raw['exists']);
			$row_count = isset($raw['row_count']) ? (int) $raw['row_count'] : 0;
			$columns = array();

			if (isset($raw['columns']) && is_array($raw['columns'])) {
				foreach ($raw['columns'] as $c) {
					if (is_string($c)) {
						$columns[] = array('name' => $c);
					}
					elseif (is_array($c) && isset($c['name'])) {
						$columns[] = array('name' => (string) $c['name']);
					}
				}
			}

			$this->set_response(array(
				'status' => 'success',
				'data' => array(
					'exists' => $exists,
					'row_count' => $row_count,
					'columns' => $columns,
					'csv_on_disk' => $csv_on_disk,
				),
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Preview rows from the draft buffer. GET /api/indicator_dsd/data_draft_preview/{sid}?limit=20
	 */
	function data_draft_preview_get($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			$limit = (int) $this->input->get('limit');
			if ($limit < 1) {
				$limit = 20;
			}
			if ($limit > 500) {
				$limit = 500;
			}

			$this->load->library('indicator_duckdb_service');
			$data = $this->indicator_duckdb_service->draft_sample($sid, $limit);

			if (is_array($data) && ! empty($data['error'])) {
				throw new Exception(isset($data['message']) ? $data['message'] : 'FastAPI staging sample failed');
			}

			$this->set_response(array(
				'status' => 'success',
				'data' => $data,
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Paginated rows from published timeseries (data explorer).
	 * GET /api/indicator_dsd/data_rows/{sid}?offset=0&limit=50&filters={"COL":["a","b"]} (limit max 200)
	 */
	function data_rows_get($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			$offset = (int) $this->input->get('offset');
			if ($offset < 0) {
				$offset = 0;
			}
			$limit = (int) $this->input->get('limit');
			if ($limit < 1) {
				$limit = 50;
			}
			if ($limit > 200) {
				$limit = 200;
			}

			$filters = null;
			$filters_raw = $this->input->get('filters');
			if ($filters_raw !== null && $filters_raw !== '') {
				$decoded = json_decode($filters_raw, true);
				if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
					throw new Exception('Invalid filters JSON');
				}
				if (! is_array($decoded)) {
					throw new Exception('filters must be a JSON object');
				}
				$sanitized = array();
				foreach ($decoded as $k => $v) {
					$col = trim((string) $k);
					if ($col === '') {
						continue;
					}
					if (! is_array($v)) {
						throw new Exception('Each filter value must be a JSON array');
					}
					$vals = array();
					foreach ($v as $item) {
						$vals[] = trim((string) $item);
					}
					if (count($vals) > 0) {
						$sanitized[$col] = $vals;
					}
				}
				if (count($sanitized) > 0) {
					$filters = $sanitized;
				}
			}

			$this->load->library('indicator_duckdb_service');
			$data = $this->indicator_duckdb_service->timeseries_page($sid, $offset, $limit, $filters);

			if (is_array($data) && ! empty($data['error'])) {
				$code = isset($data['http_code']) ? (int) $data['http_code'] : 0;
				$msg = isset($data['message']) ? $data['message'] : 'Timeseries page request failed';
				if ($code === 404) {
					throw new Exception($msg, REST_Controller::HTTP_NOT_FOUND);
				}
				throw new Exception($msg);
			}

			$this->set_response(array(
				'status' => 'success',
				'data' => $data,
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$msg = $e->getMessage();
			$code = $e->getCode();
			if ($code === REST_Controller::HTTP_NOT_FOUND) {
				$this->set_response(array(
					'status' => 'failed',
					'message' => $msg,
				), REST_Controller::HTTP_NOT_FOUND);
				return;
			}
			$this->set_response(array(
				'status' => 'failed',
				'message' => $msg,
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Compute column summary stats in DuckDB and persist JSON to indicator_dsd.sum_stats.
	 * POST /api/indicator_dsd/sum_stats_refresh/{sid}
	 * Body (optional): { "columns": ["PhysicalCol", ...] } — limit refresh to these DSD names; omit to refresh all DSD rows.
	 */
	function sum_stats_refresh_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$body = (array) $this->raw_json_input();
			$only = null;
			if (! empty($body['columns']) && is_array($body['columns'])) {
				$only = array_values(array_filter(array_map('trim', array_map('strval', $body['columns']))));
			}

			$dsd = $this->Indicator_dsd_model->select_all($sid, false);
			$to_process = $dsd;
			$want = array();

			if ($only !== null && count($only) > 0) {
				$set = array_flip(array_map('strtoupper', $only));
				$to_process = array();
				foreach ($dsd as $row) {
					if (isset($set[strtoupper($row['name'])])) {
						$to_process[] = $row;
					}
				}
				$want = $only;
			} else {
				foreach ($dsd as $row) {
					if (! empty($row['name'])) {
						$want[] = $row['name'];
					}
				}
			}

			$to_process = array_values(array_filter($to_process, function ($r) {
				return ! empty($r['name']);
			}));

			if (count($to_process) === 0) {
				throw new Exception('No DSD columns match this request');
			}

			$this->load->library('indicator_duckdb_service');
			$res = $this->indicator_duckdb_service->timeseries_column_stats($sid, count($want) > 0 ? $want : null);

			if (! is_array($res) || ! empty($res['error'])) {
				$msg = isset($res['message']) ? $res['message'] : 'Column stats request failed';
				$hc = isset($res['http_code']) ? (int) $res['http_code'] : 0;
				if ($hc === 404) {
					throw new Exception($msg, REST_Controller::HTTP_NOT_FOUND);
				}
				throw new Exception($msg);
			}

			$computed_at = isset($res['computed_at']) ? $res['computed_at'] : null;
			$source = isset($res['source']) ? $res['source'] : 'timeseries';

			$by_upper = array();
			if (! empty($res['columns']) && is_array($res['columns'])) {
				foreach ($res['columns'] as $c) {
					if (! empty($c['field'])) {
						$by_upper[strtoupper($c['field'])] = $c;
					}
				}
			}

			$updated = 0;
			$missing = array();
			foreach ($to_process as $row) {
				$id = (int) $row['id'];
				$uname = strtoupper($row['name']);
				if (! isset($by_upper[$uname])) {
					$missing[] = $row['name'];
					$err_payload = array(
						'schema_version' => 1,
						'field' => $row['name'],
						'computed_at' => $computed_at,
						'source' => $source,
						'compute' => array(
							'ok' => false,
							'error_code' => 'column_not_in_timeseries',
							'message' => 'Column not found in DuckDB timeseries or no stats returned',
						),
					);
					$this->Indicator_dsd_model->update($sid, $id, array('sum_stats' => $err_payload), false);
					++$updated;
					continue;
				}
				$payload = $by_upper[$uname];
				$payload['computed_at'] = $computed_at;
				$payload['source'] = $source;
				$payload['schema_version'] = 1;
				$this->Indicator_dsd_model->update($sid, $id, array('sum_stats' => $payload), false);
				++$updated;
			}

			$this->set_response(array(
				'status' => 'success',
				'updated_columns' => $updated,
				'computed_at' => $computed_at,
				'not_found_in_timeseries' => $missing,
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$msg = $e->getMessage();
			$code = $e->getCode();
			if ($code === REST_Controller::HTTP_NOT_FOUND) {
				$this->set_response(array(
					'status' => 'failed',
					'message' => $msg,
				), REST_Controller::HTTP_NOT_FOUND);
				return;
			}
			$this->set_response(array(
				'status' => 'failed',
				'message' => $msg,
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Download full published timeseries as CSV.
	 * GET /api/indicator_dsd/data_export/{sid}
	 */
	function data_export_get($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			$this->load->library('indicator_duckdb_service');
			$body = $this->indicator_duckdb_service->timeseries_export_csv_body($sid);

			$this->load->helper('download');
			$name = 'indicator_timeseries_' . (int) $sid . '.csv';
			if (! force_download2($name, $body)) {
				throw new Exception('Could not start CSV download');
			}
			exit;
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Delete all published indicator data (drops project_{sid}.timeseries).
	 * POST /api/indicator_dsd/data_delete/{sid}
	 * Requires edit permission. Returns { status, dropped, row_count }.
	 */
	function data_delete_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$this->load->library('indicator_duckdb_service');
			$result = $this->indicator_duckdb_service->timeseries_drop($sid);

			if (is_array($result) && !empty($result['error'])) {
				throw new Exception(isset($result['message']) ? $result['message'] : 'Failed to drop timeseries table');
			}

			$this->set_response(array(
				'status'    => 'success',
				'dropped'   => isset($result['dropped']) ? (bool) $result['dropped'] : true,
				'row_count' => isset($result['row_count']) ? (int) $result['row_count'] : 0,
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status'  => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Distinct values in a draft buffer column (indicator id picker).
	 * GET /api/indicator_dsd/data_draft_values/{sid}?column=COL&limit= (max 3000)
	 */
	function data_draft_values_get($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			$column = $this->input->get('column');
			if ($column === null || trim($column) === '') {
				throw new Exception('Query parameter column is required');
			}

			$limit = (int) $this->input->get('limit');
			if ($limit < 1) {
				$limit = 3000;
			}
			if ($limit > 3000) {
				$limit = 3000;
			}

			$this->load->library('indicator_duckdb_service');
			$data = $this->indicator_duckdb_service->draft_distinct($sid, $column, $limit);

			if (is_array($data) && !empty($data['error'])) {
				throw new Exception(isset($data['message']) ? $data['message'] : 'FastAPI distinct request failed');
			}

			$this->set_response(array(
				'status' => 'success',
				'data' => $data,
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Publish draft data → timeseries for one indicator value.
	 * POST /api/indicator_dsd/data_import/{sid}
	 * JSON: { "indicator_column": "...", "indicator_value": "..." }
	 */
	function data_import_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$body = (array) $this->raw_json_input();
			$indicator_column = isset($body['indicator_column']) ? trim((string) $body['indicator_column']) : '';
			$indicator_value = isset($body['indicator_value']) ? $body['indicator_value'] : null;

			if ($indicator_column === '') {
				throw new Exception('indicator_column is required');
			}
			if ($indicator_value === null || $indicator_value === '') {
				throw new Exception('indicator_value is required');
			}

			$this->load->library('indicator_duckdb_service');
			$time_spec = $this->Indicator_dsd_model->build_duckdb_promote_time_spec($sid);
			$queue = $this->indicator_duckdb_service->timeseries_import_queue($sid, $indicator_column, (string) $indicator_value, $time_spec);

			if (is_array($queue) && !empty($queue['error'])) {
				throw new Exception(isset($queue['message']) ? $queue['message'] : 'FastAPI promote request failed');
			}

			if (empty($queue['job_id'])) {
				throw new Exception('FastAPI did not return job_id');
			}

			$this->set_response(array(
				'status' => 'success',
				'job_id' => $queue['job_id'],
				'message' => isset($queue['message']) ? $queue['message'] : 'Promote queued',
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Queue FastAPI job to recompute _ts_year / _ts_freq on published timeseries from current DSD.
	 * POST /api/indicator_dsd/data_recompute/{sid}
	 * No body; time_spec is built from MySQL indicator_dsd (same as data_import).
	 */
	function data_recompute_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$time_spec = $this->Indicator_dsd_model->build_duckdb_promote_time_spec($sid);
			if (empty($time_spec['time_column'])) {
				throw new Exception('No time_period column in the data structure; nothing to recompute.');
			}

			$this->load->library('indicator_duckdb_service');
			$queue = $this->indicator_duckdb_service->recompute_queue($sid, $time_spec);

			if (is_array($queue) && !empty($queue['error'])) {
				$msg = isset($queue['message']) ? $queue['message'] : 'FastAPI recompute request failed';
				$hc = isset($queue['http_code']) ? (int) $queue['http_code'] : 0;
				if ($hc === 404) {
					$this->set_response(array(
						'status' => 'failed',
						'message' => $msg,
					), REST_Controller::HTTP_NOT_FOUND);
					return;
				}
				throw new Exception($msg);
			}

			if (empty($queue['job_id'])) {
				throw new Exception('FastAPI did not return job_id');
			}

			$this->set_response(array(
				'status' => 'success',
				'job_id' => $queue['job_id'],
				'message' => isset($queue['message']) ? $queue['message'] : 'Time-derived columns recompute queued',
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Poll async job status (import, recompute, etc.).
	 * GET /api/indicator_dsd/job/{sid}?job_id=...
	 */
	function job_get($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			$job_id = $this->input->get('job_id');
			if ($job_id === null || $job_id === '') {
				throw new Exception('Query parameter job_id is required');
			}

			$this->load->library('indicator_duckdb_service');
			$res = $this->indicator_duckdb_service->get_job($job_id);

			if ($res['http_code'] === 200 && is_array($res['body']) && isset($res['body']['info']['project_id'])) {
				if ((string) (int) $res['body']['info']['project_id'] !== (string) (int) $sid) {
					throw new Exception('Job does not belong to this project');
				}
			}

			$out_status = ($res['http_code'] === 200) ? 'success' : 'failed';
			$this->set_response(array(
				'status' => $out_status,
				'http_code' => $res['http_code'],
				'job' => $res['body'],
				'message' => $res['message'],
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Import an SDMX-ML DataStructureDefinition (DSD) to replace the project's data structure.
	 *
	 * WARNING: Importing replaces ALL existing DSD columns and drops all published timeseries
	 * data for the project. This action cannot be undone.
	 *
	 * POST /api/indicator_dsd/import_sdmx_dsd/{sid}
	 * Body: multipart/form-data
	 *   - file:      SDMX-ML structure XML file (.xml)   — mutually exclusive with sdmx_url
	 *   - sdmx_url:  URL of an SDMX REST structure endpoint — mutually exclusive with file
	 *
	 * Response on success:
	 *   { status, created, codelists_created, timeseries_dropped, sdmx_version, warnings }
	 */
	function import_sdmx_dsd_post($sid = null)
	{
		$tmp_path = null;

		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$this->load->library('SDMX/SdmxDsdImporter');

			if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
				$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
				if ($ext !== 'xml') {
					throw new Exception('Only XML files are supported for SDMX DSD import');
				}
				$result = $this->sdmxdsdimporter->parseFile($_FILES['file']['tmp_name']);
			} else {
				$url = $this->input->post('sdmx_url');
				if (empty($url)) {
					throw new Exception('Either a file upload or sdmx_url is required');
				}
				$result = $this->sdmxdsdimporter->parseUrl(trim($url));
			}

			if ($result['status'] !== 'success') {
				throw new Exception('Could not parse SDMX file: ' . $result['message']);
			}

			$user_id    = $this->get_api_user_id();
			$import_res = $this->Indicator_dsd_model->import_sdmx_dsd($sid, $result['dsd'], $user_id);

			$warnings = array_merge(
				isset($result['warnings']) ? $result['warnings'] : array(),
				$import_res['warnings']
			);

			$this->set_response(array(
				'status'             => 'success',
				'message'            => 'DSD imported successfully',
				'sdmx_version'       => $result['sdmx_version'],
				'created'            => $import_res['created'],
				'codelists_created'  => $import_res['codelists_created'],
				'timeseries_dropped' => $import_res['timeseries_dropped'],
				'warnings'           => $warnings,
			), REST_Controller::HTTP_OK);
		}
		catch (Exception $e) {
			$this->set_response(array(
				'status'  => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
		finally {
			if ($tmp_path !== null && is_file($tmp_path)) {
				@unlink($tmp_path);
			}
		}
	}

	/**
	 * @param int $sid
	 * @return string|null Absolute path to data/indicator_staging_upload.csv when readable
	 */
	protected function indicator_staging_upload_realpath($sid)
	{
		$this->Editor_model->create_project_folder($sid);
		$folder = $this->Editor_model->get_project_folder($sid);
		if (! $folder) {
			return null;
		}
		$dest = $folder . '/data/indicator_staging_upload.csv';
		if (! is_file($dest) || ! is_readable($dest)) {
			return null;
		}
		$r = realpath($dest);

		return $r ? $r : null;
	}
}
