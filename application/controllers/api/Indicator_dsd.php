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
	 *   - file: CSV file (direct upload), or
	 *   - upload_id: completed resumable upload from /api/uploads/* (do not send both)
	 *   - column_mappings: JSON string with column mappings
	 *   - overwrite_existing: 0|1
	 *   - skip_existing: 0|1
	 *
	 * When neither file nor upload_id is provided, uses data/indicator_staging_upload.csv from the project folder (copy to temp; staging file is not mutated).
	 *
	 * On success (no import errors), drops project_{sid}.staging in DuckDB via FastAPI and deletes data/indicator_staging_upload.csv when present.
	 *
	 */
	function dsd_import_post($sid = null)
	{
		$tmp_staging_copy = null;
		$resumable_upload_id = null;

		try{
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$upload_id_raw = $this->input->post('upload_id');
			$upload_id = is_string($upload_id_raw) ? trim($upload_id_raw) : '';
			$has_file = isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name']);

			if ($upload_id !== '' && $has_file) {
				throw new Exception('Provide either a file upload or upload_id, not both');
			}

			if ($has_file) {
				$file_ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
				if ($file_ext !== 'csv') {
					throw new Exception("Only CSV files are supported");
				}
				$file_tmp = $_FILES['file']['tmp_name'];
			} elseif ($upload_id !== '') {
				// Resumable upload path
				$this->load->library('Resumable_upload', null, 'uploader');
				$completed = $this->uploader->get_completed_upload($upload_id);
				if (!$completed) {
					throw new Exception('Resumable upload not found or not yet complete');
				}
				$file_ext = strtolower(pathinfo($completed['filename'], PATHINFO_EXTENSION));
				if ($file_ext !== 'csv') {
					throw new Exception('Only CSV files are supported');
				}
				$file_tmp = $completed['file_path'];
				$resumable_upload_id = $upload_id;
			} else {
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

		// keep_staging=1: caller will run promote AFTER dsd_import (Workflow 1 ordering fix).
		// When set, staging and the on-disk CSV are preserved so the subsequent promote can use them.
		$keep_staging = $this->input->post('keep_staging') === '1';

		// After successful DSD import: drop DuckDB staging (data already in timeseries via promote) and remove wizard CSV.
		if (empty($result['errors']) && !$keep_staging) {
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
			if ($resumable_upload_id !== null) {
				$this->uploader->delete_upload($resumable_upload_id);
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
	 * Full reset for Workflow 1 (full replace): deletes all MySQL DSD column rows and drops the
	 * DuckDB timeseries table so the project can be reimported from scratch.
	 * POST /api/indicator_dsd/reset/{sid}
	 * No body required. Returns { dsd_columns_deleted, timeseries_dropped }.
	 */
	function reset_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$deleted = $this->Indicator_dsd_model->delete_all_for_project($sid);

			$this->load->library('indicator_duckdb_service');
			$drop = $this->indicator_duckdb_service->timeseries_drop($sid);

			$ts_dropped = true;
			$warnings   = array();
			if (is_array($drop) && !empty($drop['error'])) {
				$hc = isset($drop['http_code']) ? (int) $drop['http_code'] : 0;
				if ($hc !== 404) {
					$ts_dropped = false;
					$warnings[] = isset($drop['message']) ? $drop['message'] : 'Timeseries drop failed';
				}
			}

			$response = array(
				'status'              => 'success',
				'dsd_columns_deleted' => isset($deleted['rows']) ? (int) $deleted['rows'] : 0,
				'timeseries_dropped'  => $ts_dropped,
			);
			if (!empty($warnings)) {
				$response['warnings'] = $warnings;
			}

			$this->set_response($response, REST_Controller::HTTP_OK);
		}
		catch (Throwable $e) {
			$this->set_response(array(
				'status'  => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Add extra CSV columns (by name) to the DSD as attribute-type rows (Workflow 2).
	 * POST /api/indicator_dsd/add_attributes/{sid}
	 * JSON body: { "columns": ["COL_A", "COL_B", ...] }
	 *
	 * Adds new DSD columns without a column_type so the user can classify them via the UI.
	 * - Columns already in the DSD are skipped (no overwrite).
	 * - Columns with invalid names (special chars, starts with _, too long) are skipped with a warning.
	 * Returns { added: [], skipped_existing: [], skipped_invalid: [] }
	 */
	function add_attributes_post($sid = null)
	{
		try {
			$sid  = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$body    = (array) $this->raw_json_input();
			$columns = isset($body['columns']) && is_array($body['columns']) ? $body['columns'] : array();

			if (empty($columns)) {
				$this->set_response(array(
					'status'           => 'success',
					'added'            => array(),
					'skipped_existing' => array(),
					'skipped_invalid'  => array(),
				), REST_Controller::HTTP_OK);
				return;
			}

			$existing     = $this->Indicator_dsd_model->select_all($sid, false);
			$exist_upper  = array_map(function ($c) {
				return strtoupper(trim((string) $c['name']));
			}, $existing);

			$user_id      = $this->get_api_user_id();
			$name_pattern = '/^[a-zA-Z0-9_]+$/';

			$added            = array();
			$skipped_existing = array();
			$skipped_invalid  = array();

			foreach ($columns as $raw) {
				$name  = trim((string) $raw);
				$upper = strtoupper($name);

				if (in_array($upper, $exist_upper)) {
					$skipped_existing[] = $upper;
					continue;
				}

				if (
					$name === ''
					|| !preg_match($name_pattern, $name)
					|| $name[0] === '_'
					|| strlen($name) > 255
				) {
					$skipped_invalid[] = $name;
					continue;
				}

				try {
				$this->Indicator_dsd_model->insert($sid, array(
					'name'        => $upper,
					'label'       => '',
					'description' => '',
				), false);
					$added[]      = $upper;
					$exist_upper[] = $upper;
				}
				catch (Throwable $e) {
					$skipped_invalid[] = $name . ' (' . $e->getMessage() . ')';
				}
			}

			$this->set_response(array(
				'status'           => 'success',
				'added'            => $added,
				'skipped_existing' => $skipped_existing,
				'skipped_invalid'  => $skipped_invalid,
			), REST_Controller::HTTP_OK);
		}
		catch (Throwable $e) {
			$this->set_response(array(
				'status'  => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Pre-flight: compare staging CSV columns against the saved DSD structure.
	 * GET /api/indicator_dsd/validate_draft/{sid}
	 *
	 * Useful before a data-only import to confirm that the CSV covers the required DSD columns.
	 *
	 * Response fields:
	 *   staging_exists  bool    — whether project_{sid}.staging exists in DuckDB
	 *   dsd_exists      bool    — whether MySQL indicator_dsd has rows for this project
	 *   matched         string[]  — DSD column names present in draft (normalised uppercase)
	 *   missing_dsd     string[]  — DSD column names NOT in draft (data will be null for these)
	 *   extra_csv       string[]  — draft columns not in DSD (will be ignored in data-only mode)
	 *   required_missing array   — required-role DSD columns (geography/time_period/etc.) missing from draft
	 *   has_errors      bool    — true if any required-role DSD column is absent from draft
	 */
	function validate_draft_get($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			$this->load->library('indicator_duckdb_service');
			$staging_meta = $this->indicator_duckdb_service->draft_describe($sid);

			$staging_exists = is_array($staging_meta) && !empty($staging_meta['exists']);
			$staging_col_upper = array();
			if ($staging_exists && !empty($staging_meta['columns']) && is_array($staging_meta['columns'])) {
				foreach ($staging_meta['columns'] as $col) {
					$raw = is_array($col) && isset($col['name']) ? (string) $col['name'] : (string) $col;
					$u = strtoupper(trim($raw));
					if ($u !== '') {
						$staging_col_upper[] = $u;
					}
				}
			}

			$dsd_columns = $this->Indicator_dsd_model->select_all($sid, false);
			$dsd_exists = !empty($dsd_columns);

			$matched = array();
			$missing_dsd = array();
			foreach ($dsd_columns as $col) {
				$name_u = strtoupper(trim((string) $col['name']));
				if (in_array($name_u, $staging_col_upper)) {
					$matched[] = $name_u;
				} else {
					$missing_dsd[] = $name_u;
				}
			}

			$dsd_names_upper = array_map(function ($c) {
				return strtoupper(trim((string) $c['name']));
			}, $dsd_columns);

			$extra_csv = array();
			foreach ($staging_col_upper as $name_u) {
				if (!in_array($name_u, $dsd_names_upper)) {
					$extra_csv[] = $name_u;
				}
			}

			// Required-role columns: must be present in staging for a valid promote
			$required_types = array('geography', 'time_period', 'indicator_id', 'observation_value');
			$required_missing = array();
			foreach ($dsd_columns as $col) {
				$name_u = strtoupper(trim((string) $col['name']));
				if (in_array($col['column_type'], $required_types) && !in_array($name_u, $staging_col_upper)) {
					$required_missing[] = array('name' => $name_u, 'type' => $col['column_type']);
				}
			}

			$this->set_response(array(
				'status' => 'success',
				'staging_exists' => $staging_exists,
				'dsd_exists' => $dsd_exists,
				'matched' => $matched,
				'missing_dsd' => $missing_dsd,
				'extra_csv' => $extra_csv,
				'required_missing' => $required_missing,
				'has_errors' => !empty($required_missing),
			), REST_Controller::HTTP_OK);
		}
		catch (Throwable $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Upload CSV and queue import into the draft buffer (project_{sid}.staging).
	 * POST /api/indicator_dsd/data_draft/{sid}
	 * Accepts one of:
	 *   - multipart file field "file" (direct upload), or
	 *   - form field "upload_id" referencing a completed resumable upload from /api/uploads/*
	 * Optional: delimiter, dsd_columns (JSON array of column name strings)
	 */
	function data_draft_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$upload_id_raw = $this->input->post('upload_id');
			$upload_id = is_string($upload_id_raw) ? trim($upload_id_raw) : '';
			$has_file = isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name']);

			if ($upload_id !== '' && $has_file) {
				throw new Exception('Provide either a file upload or upload_id, not both');
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

			if ($upload_id !== '') {
				// Resumable upload path
				$this->load->library('Resumable_upload', null, 'uploader');
				$completed = $this->uploader->get_completed_upload($upload_id);
				if (!$completed) {
					throw new Exception('Resumable upload not found or not yet complete');
				}
				$ext = strtolower(pathinfo($completed['filename'], PATHINFO_EXTENSION));
				if ($ext !== 'csv') {
					throw new Exception('Only CSV files are supported');
				}
				if (!@copy($completed['file_path'], $dest)) {
					throw new Exception('Failed to save uploaded CSV');
				}
				$this->uploader->delete_upload($upload_id);
			} elseif ($has_file) {
				// Direct multipart upload path
				$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
				if ($ext !== 'csv') {
					throw new Exception('Only CSV files are supported');
				}
				if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
					throw new Exception('Failed to save uploaded CSV');
				}
			} else {
				throw new Exception('CSV file is required');
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
			$this->set_response(array(
				'status'  => 'failed',
				'message' => $e->getMessage(),
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Delete timeseries rows for a specific indicator value (Workflow 2 — always replace).
	 * DELETE /api/indicator_dsd/timeseries_delete_by_indicator/{sid}
	 * Query params: indicator_column, indicator_value
	 *
	 * Called before promoting staging rows so that existing data for the chosen indicator is
	 * fully replaced rather than appended/upserted.
	 * A 404 from FastAPI (no rows existed yet) is treated as success.
	 */
	function timeseries_delete_by_indicator_delete($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$indicator_column = trim((string) ($this->input->get('indicator_column') ?? ''));
			$indicator_value  = $this->input->get('indicator_value');

			if ($indicator_column === '') {
				throw new Exception('indicator_column query param is required');
			}
			if ($indicator_value === null || $indicator_value === '') {
				throw new Exception('indicator_value query param is required');
			}

			$this->load->library('indicator_duckdb_service');
			$result = $this->indicator_duckdb_service->timeseries_delete_by_indicator($sid, $indicator_column, (string) $indicator_value);

			// 404 = table / rows did not exist yet — not an error
			if (is_array($result) && !empty($result['error'])) {
				$hc = isset($result['http_code']) ? (int) $result['http_code'] : 0;
				if ($hc !== 404) {
					throw new Exception(isset($result['message']) ? $result['message'] : 'FastAPI filtered delete failed');
				}
			}

			$this->set_response(array(
				'status'  => 'success',
				'message' => 'Deleted',
			), REST_Controller::HTTP_OK);
		}
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
		catch (Throwable $e) {
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
