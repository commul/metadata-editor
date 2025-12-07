<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Project_json_writer
{
		
	/**
	 * Constructor
	 */
	function __construct()
	{
		$this->ci =& get_instance();
		$this->ci->load->model('Editor_model');
		$this->ci->load->model('Editor_template_model');
		$this->ci->load->model('Editor_datafile_model');
		$this->ci->load->model('Editor_variable_model');
		$this->ci->load->library('schema_util');
	}


	/**
	 * 
	 * Export project metadata as JSON
	 * 
	 * @param int $sid - Survey ID
	 * @param array options - Options
	 * 	- exclude_private_fields - Exclude private fields
	 *  - inc_ext_resources - include external resources
	 *  - inc_adm_meta -include admin metadata
	 * 
	 */
	function download_project_json($sid, $options=array())
	{		
		$json_path=$this->generate_project_json($sid, $options);

		//download json
		if(file_exists($json_path)){
			header("Content-type: application/json; charset=utf-8");
			$stdout = fopen('php://output', 'w');			
			$fh = fopen($json_path, 'r');
			stream_copy_to_stream($fh, $stdout);
			fclose($fh);
			fclose($stdout);
		}
	}

	/**
	 * 
	 * Remove private fields from JSON
	 * 
	 */
	function json_remove_private_fields($sid,&$json)
	{
		//load project template
		$template=$this->ci->Editor_template_model->get_project_template($sid);
		
		$output=array();
		$this->walk_template($template['template']['items'], $output);

		foreach($output as $private_field){
			array_unset_value($json, $private_field, $glue = '.');
		}

		return $json;
	}
	

	/**
	 * 
	 * Walk template and get private fields
	 * 	 
	 */
	function walk_template($items, &$output)
	{		
		foreach($items as $key=>$item){
			$is_private=isset($item['is_private']) ? $item['is_private'] : false;

			if ($is_private){
				$output[]=$item['key'];
			}

			/*if (isset($item['props'])){
				$this->walk_template_props($item['props'], $item_path=$item['key'], $metadata);
			}*/

			if (isset($item['items'])){
				$this->walk_template($item['items'], $output);
			}
		}
	}

	/*function walk_template_props($props, $item_path, &$metadata)
	{
		foreach($props as $key=>$prop){

			$is_private=isset($prop['is_private']) ? $prop['is_private'] : false;

			if ($is_private){
				echo "Key: ".$prop['prop_key']. " - " . $is_private  .$item_path.'.*.'.$prop['key']. "\n";
			}

			if (isset($prop['props'])){
				$this->walk_template_props($prop['props'], $item_path.'.*.'.$prop['key'], $metadata);
			}
			//echo "Prop: ".$prop['key']. "\n";
		}
	}*/



	/**
	 * 
	 * Generete project JSON
	 * 
	 * @param int $sid - Survey ID
	 * @param array options - Options
	 * 	- exclude_private_fields - Exclude private fields
	 *  - external_resources - include external resources
	 *  - admin_metadata -include admin metadata
	 * 
	 */
	function generate_project_json($sid, $options=array())
	{
		$exclude_private_fields=isset($options['exclude_private_fields']) ? $options['exclude_private_fields'] : 0;
		$external_resources=[];
		$admin_metadata=[];

		$project=$this->ci->Editor_model->get_row($sid);
		$project_folder=$this->ci->Editor_model->get_project_folder($sid);

		if (!$project_folder || !file_exists($project_folder)){
			throw new Exception("download_project_json::Project folder not found");
		}

		$filename=trim((string)$project['idno'])!=='' ? trim($project['idno']) : nada_hash($project['id']);
		$output_file=$project_folder.'/'.$filename.'.json';

		$fp = fopen($output_file, 'w');

		$metadata=(array)$project['metadata'];

		//remove fields
		$remove_fields=array('created','changed','created_by','changed_by');
		foreach($remove_fields as $field){
			unset($metadata[$field]);
		}

		if ($exclude_private_fields==1){
			$this->json_remove_private_fields($sid,$metadata);
		}
		
		//external resources
		if (isset($options['external_resources']) && $options['external_resources']==1){
			$this->ci->load->model('Editor_resource_model');
			$metadata['external_resources']=$this->ci->Editor_resource_model->select_all($sid);
		}

		if (isset($options['admin_metadata']) && $options['admin_metadata']==1){
			$this->ci->load->model("Admin_metadata_model");
			
			$user_id=null;
			if(isset($options['user_id'])){
				$user_id=$options['user_id'];
			}
			else{
				$user_id=-1;				
			}

			$metadata['admin_metadata']=$this->ci->Admin_metadata_model->get_project_metadata($sid, $metadata_type_id=null, $output_format='', $user_id);
		}

		array_remove_empty($metadata);

		//get schema version and ID
		$schema_info=$this->ci->schema_util->get_schema_version_info($project['type']);

		if (!$schema_info){
			throw new Exception("download_project_json::Schema info not found");
		}

		$basic_info=array(
			'schema'=>$schema_info['$id'],
			'schema_version'=>$schema_info['version'],
			'type'=>$project['type'],
			'idno'=>$project['idno'],
			'changed'=>$project['changed'],
			'changed_utc'=> $project['changed'] != '' ? date('c', ($project['changed'])) : '',
			'created'=>$project['created'],			
			'created_utc'=> date('c', ($project['created'])),
			'created_by'=>$project['created_by'],			
			'changed_by'=>$project['changed_by']
		);
		
		$output=array_merge($basic_info, $metadata );

		if($project['type']=='survey'){			
			$output['data_files'] = function () use ($sid) {
				$files=$this->ci->Editor_datafile_model->select_all($sid, $include_file_info=false);
				if ($files){
					foreach($files as $file){
						unset($file['id']);
						unset($file['sid']);
						yield $file;
					}
				}
			};

			$output['variables'] = function () use ($sid) {
				foreach($this->ci->Editor_variable_model->chunk_reader_generator($sid) as $variable){
					$variable=$this->transform_variable($variable);
					yield $variable['metadata'];
				}
			};

			/*$output['variable_groups'] = function () use ($sid) {
				$var_groups=$this->Variable_group_model->select_all($sid);
				foreach($var_groups as $var_group){
					yield $var_group;
				}			
			};*/
		}
		
		if($project['type']=='geospatial'){
			// Merge feature catalogue metadata from project-level and database
			$this->ci->load->library('Geospatial_metadata_writer');
			$merged_feature_catalogue = $this->ci->geospatial_metadata_writer->get_merged_feature_catalogue($sid);
			
			if (!empty($merged_feature_catalogue)) {
				if (!isset($output['description'])) {
					$output['description'] = array();
				}
				$output['description']['feature_catalogue'] = $merged_feature_catalogue;
			}
		}
		
		$encoder = new \Violet\StreamingJsonEncoder\StreamJsonEncoder(
			$output,
			function ($json) use ($fp) {
				fwrite($fp, $json);
			}
		);
		//$encoder->setOptions(JSON_PRETTY_PRINT);
		$encoder->encode();
		fclose($fp);
		
		return $output_file;
	}

	function transform_variable($variable)
	{		
		$sid=(int)$variable['sid'];
		unset($variable['metadata']['uid']);
		unset($variable['metadata']['sid']);

		$var_catgry_labels=isset($variable['metadata']["var_catgry_labels"]) 
			? $this->get_indexed_variable_category_labels($variable['metadata']["var_catgry_labels"]) 
			: array();

		//process summary statistics
		$sum_stats_options = isset($variable['metadata']['sum_stats_options']) ? $variable['metadata']['sum_stats_options'] : [];
		$sum_stats_enabled_list=[];
		foreach($sum_stats_options as $option=>$value){
			if ($value===true || $value==1){
				$sum_stats_enabled_list[]=$option;
			}
		}

		//handle summary statistics - remove all if no options set, or filter by enabled options
		if (isset($variable['metadata']['var_sumstat']) && is_array($variable['metadata']['var_sumstat']) ){
			if (count($sum_stats_enabled_list) > 0){
				//filter by enabled options
				foreach($variable['metadata']['var_sumstat'] as $idx=>$sumstat){
					if (!in_array($sumstat['type'], $sum_stats_enabled_list)){
						unset($variable['metadata']['var_sumstat'][$idx]);
					}
				}
			} else {
				//remove all summary statistics if no options are set
				unset($variable['metadata']['var_sumstat']);
			}
			//fix to get a JSON array instead of Object (only if var_sumstat still exists)
			if (isset($variable['metadata']['var_sumstat'])){
				$variable['metadata']['var_sumstat']=array_values((array)$variable['metadata']['var_sumstat']);
			}
		}

		//value ranges [counts, min, max] - remove min and max if not enabled
		if (isset($variable['metadata']['var_valrng']['range']) && is_array($variable['metadata']['var_valrng']['range']) ){
			if (count($sum_stats_enabled_list) > 0){
				//filter by enabled options
				foreach($variable['metadata']['var_valrng']['range'] as $range_key=>$range){
					//only check for min and max
					if (!in_array($range_key, array("min", "max"))){
						continue;
					}
					if (!in_array($range_key, $sum_stats_enabled_list)){
						unset($variable['metadata']['var_valrng']['range'][$range_key]);
					}
				}
			} else {
				//remove all range statistics if no options are set
				unset($variable['metadata']['var_valrng']['range']);
			}
		}

		//handle category frequency statistics
		if (isset($variable['metadata']['var_catgry']) && is_array($variable['metadata']['var_catgry']) ){
			if (count($sum_stats_enabled_list) > 0){
				//remove category freq if not enabled
				if (!in_array('freq', $sum_stats_enabled_list)){
					foreach($variable['metadata']['var_catgry'] as $idx=>$cat){
						//remove freq if not enabled
						if (isset($cat['stats']) && is_array($cat['stats']) ){
							foreach($cat['stats'] as $stat_idx=>$stat){
								if ($stat['type']=='freq'){
									unset($variable['metadata']['var_catgry'][$idx]['stats'][$stat_idx]);
								}
							}						
						}
					}
				}
			} else {
				//remove all category statistics if no options are set
				foreach($variable['metadata']['var_catgry'] as $idx=>$cat){
					if (isset($cat['stats']) && is_array($cat['stats']) ){
						unset($variable['metadata']['var_catgry'][$idx]['stats']);
					}
				}
			}
		}

		//add var_catgry labels
		if (isset($variable['metadata']['var_catgry']) && is_array($variable['metadata']['var_catgry']) ){
			foreach($variable['metadata']['var_catgry'] as $idx=>$cat){
				if (isset($var_catgry_labels[$cat['value']])){
					$variable['metadata']['var_catgry'][$idx]['labl']=$var_catgry_labels[$cat['value']];
				}
			}
		}
		

		//var_std_catgry field - array to object + use first row only
		if (isset($variable['metadata']['var_std_catgry']) && is_array($variable['metadata']['var_std_catgry']) ){
			if (isset($variable['metadata']['var_std_catgry'][0])){
				$variable['metadata']['var_std_catgry']=$variable['metadata']['var_std_catgry'][0];
			}
		}

		//var_wgt_id field - replace UID with VID
		if (isset($variable['metadata']['var_wgt_id']) && $variable['metadata']['var_wgt_id']!==''){
			$variable['metadata']['var_wgt_id']=$this->ci->Editor_variable_model->vid_by_uid($sid,$variable['metadata']['var_wgt_id']);
		}

		//remove update_required field
		if (isset($variable['metadata']['update_required'])){
			unset($variable['metadata']['update_required']);
		}

		//remove sum_stats_options
		if (isset($variable['metadata']['sum_stats_options'])){
			unset($variable['metadata']['sum_stats_options']);
		}

		//var_catgry_labels
		if (isset($variable['metadata']['var_catgry_labels'])){
			unset($variable['metadata']['var_catgry_labels']);
		}

		// Convert numeric strings to actual numbers in statistical fields only
		$variable['metadata'] = $this->convert_statistics_to_numeric($variable['metadata']);
		
		array_remove_empty($variable);
		return $variable;
	}
	
	/**
	 * Convert numeric strings to numbers ONLY in statistical fields
	 * This preserves strings like "01", "02" in category values while fixing numeric stats
	 */
	private function convert_statistics_to_numeric($metadata)
	{
		// Convert summary statistics values
		if (isset($metadata['var_sumstat']) && is_array($metadata['var_sumstat'])) {
			foreach ($metadata['var_sumstat'] as $idx => $stat) {
				if (isset($stat['value'])) {
					$metadata['var_sumstat'][$idx]['value'] = $this->to_number_if_appropriate($stat['value']);
				}
			}
		}
		
		// Convert value ranges (min, max, count)
		if (isset($metadata['var_valrng']['range']) && is_array($metadata['var_valrng']['range'])) {
			foreach ($metadata['var_valrng']['range'] as $key => $value) {
				if (in_array($key, ['min', 'max', 'count'])) {
					$metadata['var_valrng']['range'][$key] = $this->to_number_if_appropriate($value);
				}
			}
		}
		
		// Convert category statistics (freq counts) but NOT category values
		if (isset($metadata['var_catgry']) && is_array($metadata['var_catgry'])) {
			foreach ($metadata['var_catgry'] as $idx => $cat) {
				if (isset($cat['stats']) && is_array($cat['stats'])) {
					foreach ($cat['stats'] as $stat_idx => $stat) {
						if (isset($stat['value'])) {
							$metadata['var_catgry'][$idx]['stats'][$stat_idx]['value'] = 
								$this->to_number_if_appropriate($stat['value']);
						}
					}
				}
			}
		}
		
		return $metadata;
	}
	
	/**
	 * Convert a value to number only if it's truly meant to be numeric
	 * Preserves strings with leading zeros like "01", "001", etc.
	 */
	private function to_number_if_appropriate($value)
	{
		// Not a string? Return as-is
		if (!is_string($value)) {
			return $value;
		}
		
		// Not numeric? Return as-is
		if (!is_numeric($value)) {
			return $value;
		}
		
		// Has leading zero (but not "0" itself or "0.something")? Keep as string
		if (strlen($value) > 1 && $value[0] === '0' && $value[1] !== '.') {
			return $value;
		}
		
		// Convert to appropriate numeric type
		if (strpos($value, '.') !== false) {
			return (float)$value;
		}
		return (int)$value;
	}


	function get_indexed_variable_category_labels($cat_labels)
	{
		$output=array();
		foreach($cat_labels as $cat){
			if (isset($cat['labl']) && isset($cat['value'])){
				$output[$cat['value']]=$cat['labl'];
			}
		}

		return $output;
	}


}

