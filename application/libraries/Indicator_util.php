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
		return array_map(array($this, 'dsd_row_to_schema_item'), $columns);
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
		$sort_order = 0;
		foreach ($data_structure as $item) {
			$row = $this->schema_item_to_dsd_row($item, $sort_order, $user_id);
			$this->ci->Indicator_dsd_model->insert($sid, $row);
			$sort_order++;
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
		$allowed = array('name', 'label', 'description', 'data_type', 'column_type', 'time_period_format', 'code_list', 'code_list_reference');
		$item = array();
		foreach ($allowed as $key) {
			if (!array_key_exists($key, $column)) {
				continue;
			}
			$val = $column[$key];
			if ($key === 'code_list_reference' && (is_array($val) || is_object($val))) {
				$val = (array) $val;
				$item[$key] = array_intersect_key($val, array_flip(array('id', 'name', 'version', 'uri', 'note')));
				$item[$key] = array_filter($item[$key], function ($v) { return $v !== null && $v !== ''; });
				if (empty($item[$key])) {
					unset($item[$key]);
				}
			} else {
				$item[$key] = $val;
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
		$allowed = array('name', 'label', 'description', 'data_type', 'column_type', 'time_period_format', 'code_list', 'code_list_reference');
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
		return $row;
	}
}
