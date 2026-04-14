<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Indicator_util
 *
 * Utilities for indicator/timeseries projects, including DSD (data structure definition)
 * import/export and mapping between DB rows and timeseries-schema data_structure format.
 */
class Indicator_util
{
	private $ci;

	function __construct()
	{
		$this->ci =& get_instance();
		$this->ci->load->model('Indicator_dsd_model');
	}

	/**
	 * Export data structure for a project (DSD columns → timeseries-schema data_structure array).
	 *
	 * @param int $sid Project ID
	 * @return array data_structure array for project JSON
	 */
	public function get_data_structure_for_project($sid)
	{
		$columns = $this->ci->Indicator_dsd_model->select_all($sid, $metadata_detailed = true);
		$items   = array_map(array($this, 'dsd_row_to_schema_item'), $columns);

		// Embed codelist items so the export is fully self-contained.
		// DB-specific FK integers (local_codelist_id, global_codelist_id) are never exported;
		// codes are always inlined under "code_list" — the standard schema field name.
		$this->ci->load->model('Local_codelists_model');
		$this->ci->load->model('Codelists_model');
		foreach ($columns as $i => $column) {
			$ctype = isset($column['codelist_type']) ? $column['codelist_type'] : 'none';

			if ($ctype === 'local') {
				$lid = isset($column['local_codelist_id']) ? (int) $column['local_codelist_id'] : 0;
				if ($lid <= 0) {
					continue;
				}
				$raw_items = $this->ci->Local_codelists_model->get_items($lid);
				if (empty($raw_items)) {
					continue;
				}
				$codes = array();
				foreach ($raw_items as $it) {
					$entry = array('code' => (string) $it['code']);
					if (!empty($it['label'])) {
						$entry['label'] = (string) $it['label'];
					}
					$codes[] = $entry;
				}
				$items[$i]['code_list'] = $codes;

			} elseif ($ctype === 'global') {
				$gid = isset($column['global_codelist_id']) ? (int) $column['global_codelist_id'] : 0;
				if ($gid <= 0) {
					continue;
				}
				$raw_codes = $this->ci->Codelists_model->get_codes($gid, null, true);
				if (empty($raw_codes)) {
					continue;
				}
				$codes = array();
				foreach ($raw_codes as $rc) {
					$entry = array('code' => (string) $rc['code']);
					// Prefer English label; fall back to the first available language.
					$label = '';
					if (!empty($rc['labels']) && is_array($rc['labels'])) {
						foreach ($rc['labels'] as $lbl) {
							if (isset($lbl['language']) && $lbl['language'] === 'en') {
								$label = (string) $lbl['label'];
								break;
							}
						}
						if ($label === '') {
							$label = (string) $rc['labels'][0]['label'];
						}
					}
					if ($label !== '') {
						$entry['label'] = $label;
					}
					$codes[] = $entry;
				}
				$items[$i]['code_list'] = $codes;
			}
		}

		return $items;
	}

	/**
	 * Import data_structure into DSD table (replace existing columns).
	 * Caller should remove data_structure from metadata after calling.
	 *
	 * @param int $sid Project ID
	 * @param array $data_structure Array of schema-shaped items
	 * @param int $user_id User ID for created_by/changed_by
	 * @return void
	 */
	public function import_data_structure_for_project($sid, $data_structure, $user_id)
	{
		$existing = $this->ci->Indicator_dsd_model->select_all($sid, false);
		$existing_ids = array_column($existing, 'id');
		if (!empty($existing_ids)) {
			$this->ci->Indicator_dsd_model->delete($sid, $existing_ids);
		}

		$this->ci->load->model('Local_codelists_model');

		$sort_order = 0;
		foreach ($data_structure as $item) {
			$row        = $this->schema_item_to_dsd_row($item, $sort_order, $user_id);
			$new_col_id = (int) $this->ci->Indicator_dsd_model->insert($sid, $row);
			$sort_order++;

			// Re-create local codelist from the standard "code_list" schema field.
			// Both the export output and any third-party JSON use this same field name.
			// local_codelist_id is never trusted across projects; a fresh list is always created.
			$local_codes = isset($item['code_list']) && is_array($item['code_list']) ? $item['code_list'] : array();
			if ($new_col_id > 0 && !empty($local_codes)) {
				try {
					$list_id = (int) $this->ci->Local_codelists_model->insert_list($sid, $new_col_id, array(), $user_id);
					$s = 0;
					foreach ($local_codes as $code_item) {
						$code = isset($code_item['code']) ? trim((string) $code_item['code']) : '';
						if ($code === '') {
							continue;
						}
						$label = isset($code_item['label']) ? (string) $code_item['label'] : '';
						try {
							$this->ci->Local_codelists_model->insert_item($sid, $list_id, array(
								'code'       => $code,
								'label'      => $label,
								'sort_order' => $s++,
							), $user_id);
						} catch (Throwable $e) {
							// Skip duplicate or invalid codes silently during import.
						}
					}
					// Link the new list back to the DSD column.
					$this->ci->Indicator_dsd_model->update($sid, $new_col_id, array('local_codelist_id' => $list_id), false);
				} catch (Throwable $e) {
					// If the list already exists or creation fails, skip rather than aborting the whole import.
				}
			}
		}
	}

	/**
	 * Map a DSD table row to timeseries-schema data_structure item shape.
	 *
	 * @param array $column Row from Indicator_dsd_model (indicator_dsd table)
	 * @return array Item suitable for data_structure array in project JSON
	 */
	public function dsd_row_to_schema_item($column)
	{
		$allowed = array(
			'name', 'label', 'description', 'data_type', 'column_type', 'time_period_format',
			'code_list', 'code_list_reference',
			'codelist_type',
			// local_codelist_id and global_codelist_id are DB-specific integers and are NOT exported.
			// Both local and global codelist codes are embedded as "code_list" by
			// get_data_structure_for_project() instead.
		);
		$item = array();
		foreach ($allowed as $key) {
			if (!array_key_exists($key, $column)) {
				continue;
			}
			$val = $column[$key];
			if ($key === 'code_list_reference' && (is_array($val) || is_object($val))) {
				$val = (array) $val;
				$val = array_intersect_key($val, array_flip(array('id', 'name', 'version', 'uri', 'note')));
				$val = array_filter($val, function ($v) { return $v !== null && $v !== ''; });
				if (!empty($val)) {
					$item[$key] = $val;
				}
			} elseif ($val !== null && $val !== '') {
				// Skip null/empty scalars to keep the exported JSON clean
				$item[$key] = $val;
			}
		}
		// Persist metadata fields on the schema item for a clean export/import round-trip.
		// Top-level functional fields + SDMX attribute descriptors.
		$metadata_fields = array(
			'paired_time_column_id', 'value_label_column', 'freq',
			'attachment_level', 'assignment_status',
		);
		if (!empty($column['metadata']) && is_array($column['metadata'])) {
			foreach ($metadata_fields as $mk) {
				if (array_key_exists($mk, $column['metadata']) && $column['metadata'][$mk] !== '' && $column['metadata'][$mk] !== null) {
					$item[$mk] = $column['metadata'][$mk];
				}
			}
			// Legacy key: import_freq_code → freq
			if (!isset($item['freq']) && array_key_exists('import_freq_code', $column['metadata']) && $column['metadata']['import_freq_code'] !== '' && $column['metadata']['import_freq_code'] !== null) {
				$item['freq'] = $column['metadata']['import_freq_code'];
			}
		}
		return $item;
	}

	/**
	 * Map a data_structure item (from project JSON/timeseries schema) to a row for indicator_dsd table.
	 *
	 * @param array $item One element from metadata data_structure array
	 * @param int $sort_order Position for this column
	 * @param int $user_id User ID for created_by/changed_by
	 * @return array Row suitable for Indicator_dsd_model->insert($sid, $row)
	 */
	public function schema_item_to_dsd_row($item, $sort_order, $user_id)
	{
		$allowed = array(
			'name', 'label', 'description', 'data_type', 'column_type', 'time_period_format',
			'code_list', 'code_list_reference',
			'codelist_type', 'global_codelist_id',
			// local_codelist_id is not imported from JSON — the list is re-created from
			// "code_list" in import_data_structure_for_project() with a fresh DB id.
		);
		$row = array(
			'sort_order' => (int) $sort_order,
			'created_by' => $user_id,
			'changed_by' => $user_id
		);
		foreach ($allowed as $key) {
			if (array_key_exists($key, $item)) {
				$row[$key] = $item[$key];
			}
		}
		$meta_patch = array();
		foreach (array('paired_time_column_id', 'value_label_column', 'freq', 'attachment_level', 'assignment_status') as $mk) {
			if (array_key_exists($mk, $item)) {
				$meta_patch[$mk] = $item[$mk];
			}
		}
		if (array_key_exists('import_freq_code', $item) && !array_key_exists('freq', $item)) {
			$meta_patch['freq'] = $item['import_freq_code'];
		}
		if (!empty($meta_patch)) {
			$existing = (isset($item['metadata']) && is_array($item['metadata'])) ? $item['metadata'] : array();
			$row['metadata'] = array_merge($existing, $meta_patch);
		} elseif (isset($item['metadata']) && is_array($item['metadata'])) {
			$row['metadata'] = $item['metadata'];
		}

		// When code_list carries actual codes, promote the column to a local codelist.
		// The codes themselves are written to the local_codelists table by
		// import_data_structure_for_project(); the inline code_list field is not stored on the row.
		$inline_codes = isset($item['code_list']) && is_array($item['code_list']) ? $item['code_list'] : array();
		$inline_codes = array_filter($inline_codes, function ($c) {
			return is_array($c) && isset($c['code']) && trim((string) $c['code']) !== '';
		});
		if (!empty($inline_codes)) {
			$row['codelist_type'] = 'local';
			unset($row['code_list']); // will be stored in local_codelists table instead
		}

		return $row;
	}
}
