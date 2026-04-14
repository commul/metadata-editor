<?php

require_once APPPATH . 'libraries/Jobs/JobHandlerInterface.php';

/**
 * Import Indicator Data Job Handler
 *
 * Handles the full end-to-end indicator data import workflow as a single background job:
 *   1. Copy the uploaded CSV to the project folder
 *   2. Normalize CSV headers for DuckDB
 *   3. Load CSV into the draft (staging) buffer via FastAPI
 *   4. Validate that the draft covers the required DSD columns
 *   5. Discover all distinct indicator values in the draft
 *   6. For each indicator value: replace existing timeseries rows and promote from draft
 *
 * Payload fields:
 *   project_id       (int)    required — indicator/timeseries project
 *   upload_id        (string) required — completed resumable upload ID (from POST /api/uploads/*)
 *   delimiter        (string) optional — CSV delimiter character (default: ',')
 *   indicator_value  (string) required — only import rows whose indicator_id column matches this value
 */
class ImportIndicatorDataJob implements JobHandlerInterface
{
    private $ci;

    public function __construct()
    {
        $this->ci =& get_instance();
        $this->ci->load->model('Editor_model');
        $this->ci->load->model('Indicator_dsd_model');
    }

    public function getJobType()
    {
        return 'import_indicator_data';
    }

    public function validatePayload($payload)
    {
        if (empty($payload['project_id'])) {
            throw new Exception('Missing required parameter: project_id');
        }

        if (empty($payload['upload_id'])) {
            throw new Exception('Missing required parameter: upload_id (use POST /api/uploads/* to upload the CSV first)');
        }

        if (!isset($payload['indicator_value']) || trim((string) $payload['indicator_value']) === '') {
            throw new Exception('Missing required parameter: indicator_value');
        }

        $project = $this->ci->Editor_model->get_row((int) $payload['project_id']);
        if (!$project) {
            throw new Exception('Project not found: ' . $payload['project_id']);
        }

        $allowed_types = array('indicator', 'timeseries');
        if (!in_array($project['type'], $allowed_types, true)) {
            throw new Exception('Project must be of type indicator or timeseries, got: ' . $project['type']);
        }

        return true;
    }

    public function generateJobHash($payload)
    {
        $hash_data = array(
            'job_type'   => $this->getJobType(),
            'project_id' => isset($payload['project_id']) ? (int) $payload['project_id'] : null,
            'upload_id'  => isset($payload['upload_id']) ? (string) $payload['upload_id'] : null,
        );
        ksort($hash_data);
        return hash('sha256', json_encode($hash_data));
    }

    public function process($job, $payload)
    {
        $sid             = (int) $payload['project_id'];
        $upload_id       = (string) $payload['upload_id'];
        $delimiter       = isset($payload['delimiter']) && strlen((string) $payload['delimiter']) === 1
            ? (string) $payload['delimiter']
            : ',';
        $filter_value    = isset($payload['indicator_value']) && trim((string) $payload['indicator_value']) !== ''
            ? trim((string) $payload['indicator_value'])
            : null;

        $this->ci->load->library('indicator_duckdb_service');

        // ── Step 1: Resolve upload and copy CSV to project folder ─────────────────

        $this->ci->Editor_model->create_project_folder($sid);
        $folder = $this->ci->Editor_model->get_project_folder($sid);
        if (!$folder) {
            throw new Exception('Project folder not available for project ' . $sid);
        }

        $data_dir = $folder . '/data';
        if (!is_dir($data_dir)) {
            @mkdir($data_dir, 0777, true);
        }

        $dest = $data_dir . '/indicator_staging_upload.csv';

        $this->ci->load->library('Resumable_upload', null, 'uploader');
        $completed = $this->ci->uploader->get_completed_upload($upload_id);
        if (!$completed) {
            throw new Exception('Resumable upload not found or not yet complete: ' . $upload_id);
        }

        $ext = strtolower(pathinfo($completed['filename'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new Exception('Only CSV files are supported, got: ' . $ext);
        }

        if (!@copy($completed['file_path'], $dest)) {
            throw new Exception('Failed to copy uploaded CSV to project folder');
        }

        $this->ci->uploader->delete_upload($upload_id);

        $real_path = realpath($dest);
        if ($real_path === false) {
            throw new Exception('Could not resolve CSV path after copy');
        }

        // ── Step 2: Normalize CSV headers for DuckDB ──────────────────────────────

        $this->ci->Indicator_dsd_model->rewrite_indicator_csv_headers_for_duckdb($real_path);

        // ── Step 3: Load CSV into draft (staging) buffer ──────────────────────────

        $queue = $this->ci->indicator_duckdb_service->draft_queue($real_path, $sid, $delimiter);

        if (is_array($queue) && !empty($queue['error'])) {
            throw new Exception('Failed to queue staging import: ' . (isset($queue['message']) ? $queue['message'] : 'unknown error'));
        }

        if (empty($queue['job_id'])) {
            throw new Exception('FastAPI did not return a job_id for staging import');
        }

        $staging_result = $this->ci->indicator_duckdb_service->poll_job(
            $queue['job_id'],
            $max_wait_seconds = 1800,
            $interval_seconds = 3
        );

        if (!is_array($staging_result) || ($staging_result['status'] ?? '') !== 'done') {
            $err = isset($staging_result['error']) ? $staging_result['error'] : 'Staging import did not complete';
            throw new Exception('Staging import failed: ' . $err);
        }

        // ── Step 4: Validate draft columns against DSD ────────────────────────────

        $staging_meta = $this->ci->indicator_duckdb_service->draft_describe($sid);

        if (!is_array($staging_meta) || !empty($staging_meta['error'])) {
            throw new Exception('Could not read draft metadata after staging import');
        }

        $staging_col_upper = array();
        if (!empty($staging_meta['columns']) && is_array($staging_meta['columns'])) {
            foreach ($staging_meta['columns'] as $col) {
                $raw = is_array($col) && isset($col['name']) ? (string) $col['name'] : (string) $col;
                $u = strtoupper(trim($raw));
                if ($u !== '') {
                    $staging_col_upper[] = $u;
                }
            }
        }

        $dsd_columns = $this->ci->Indicator_dsd_model->select_all($sid, false);
        if (empty($dsd_columns)) {
            throw new Exception('No DSD columns defined for this project. Create the data structure before importing data.');
        }

        $required_types  = array('geography', 'time_period', 'indicator_id', 'observation_value');
        $required_missing = array();
        $indicator_id_column = null;

        foreach ($dsd_columns as $col) {
            $name_u = strtoupper(trim((string) $col['name']));
            if (in_array($col['column_type'], $required_types, true) && !in_array($name_u, $staging_col_upper, true)) {
                $required_missing[] = $col['column_type'] . ' (' . $name_u . ')';
            }
            if ($col['column_type'] === 'indicator_id') {
                $indicator_id_column = $col['name'];
            }
        }

        if (!empty($required_missing)) {
            throw new Exception(
                'Required DSD columns missing from CSV — import blocked. Missing: ' .
                implode(', ', $required_missing)
            );
        }

        if ($indicator_id_column === null) {
            throw new Exception('DSD has no indicator_id column. Define one before importing data.');
        }

        // ── Step 5: Resolve indicator values to import ────────────────────────────

        // Always fetch the actual distinct values from the draft so we can validate.
        $distinct = $this->ci->indicator_duckdb_service->draft_distinct($sid, $indicator_id_column, 3000);

        if (is_array($distinct) && !empty($distinct['error'])) {
            throw new Exception('Failed to read indicator values from draft: ' . (isset($distinct['message']) ? $distinct['message'] : 'unknown error'));
        }

        $all_values = array();
        if (!empty($distinct['values']) && is_array($distinct['values'])) {
            $all_values = array_map('strval', $distinct['values']);
        } elseif (!empty($distinct['data']['values']) && is_array($distinct['data']['values'])) {
            $all_values = array_map('strval', $distinct['data']['values']);
        }

        if (empty($all_values)) {
            throw new Exception('No indicator values found in the draft CSV column "' . $indicator_id_column . '"');
        }

        if ($filter_value !== null) {
            // Verify the requested indicator value is actually present in the draft.
            if (!in_array($filter_value, $all_values, true)) {
                throw new Exception(
                    'Indicator value "' . $filter_value . '" not found in the draft CSV column "' . $indicator_id_column . '"'
                );
            }
            $indicator_values = array($filter_value);
        } else {
            $indicator_values = $all_values;
        }

        // ── Step 6: Replace + promote data for each indicator value ───────────────

        $time_spec = $this->ci->Indicator_dsd_model->build_duckdb_promote_time_spec($sid);

        $imported      = array();
        $failed        = array();

        foreach ($indicator_values as $indicator_value) {
            $indicator_value = (string) $indicator_value;

            // Delete existing rows for this indicator (404 = none existed yet, not an error)
            $del = $this->ci->indicator_duckdb_service->timeseries_delete_by_indicator(
                $sid,
                $indicator_id_column,
                $indicator_value
            );
            if (is_array($del) && !empty($del['error'])) {
                $hc = isset($del['http_code']) ? (int) $del['http_code'] : 0;
                if ($hc !== 404) {
                    $failed[] = array(
                        'indicator' => $indicator_value,
                        'error' => 'Delete failed: ' . (isset($del['message']) ? $del['message'] : 'unknown'),
                    );
                    continue;
                }
            }

            // Queue promotion from draft → timeseries
            $promote = $this->ci->indicator_duckdb_service->timeseries_import_queue(
                $sid,
                $indicator_id_column,
                $indicator_value,
                $time_spec
            );

            if (is_array($promote) && !empty($promote['error'])) {
                $failed[] = array(
                    'indicator' => $indicator_value,
                    'error' => 'Promote queue failed: ' . (isset($promote['message']) ? $promote['message'] : 'unknown'),
                );
                continue;
            }

            if (empty($promote['job_id'])) {
                $failed[] = array('indicator' => $indicator_value, 'error' => 'No job_id returned from FastAPI');
                continue;
            }

            $promote_result = $this->ci->indicator_duckdb_service->poll_job(
                $promote['job_id'],
                $max_wait_seconds = 1800,
                $interval_seconds = 3
            );

            if (!is_array($promote_result) || ($promote_result['status'] ?? '') !== 'done') {
                $err = isset($promote_result['error']) ? $promote_result['error'] : 'Promote did not complete';
                $failed[] = array('indicator' => $indicator_value, 'error' => $err);
                continue;
            }

            $imported[] = $indicator_value;
        }

        if (!empty($failed) && empty($imported)) {
            throw new Exception(
                'All indicator imports failed. First error: ' . $failed[0]['error']
            );
        }

        return array(
            'project_id'           => $sid,
            'indicator_id_column'  => $indicator_id_column,
            'indicators_total'     => count($indicator_values),
            'indicators_imported'  => $imported,
            'indicators_failed'    => $failed,
            'message'              => empty($failed)
                ? 'All ' . count($imported) . ' indicator(s) imported successfully.'
                : count($imported) . ' of ' . count($indicator_values) . ' indicator(s) imported; ' . count($failed) . ' failed.',
        );
    }
}
