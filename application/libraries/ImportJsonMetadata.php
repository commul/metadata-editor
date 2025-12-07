<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');


/**
 * 
 * 
 * Import project metadata from JSON file
 * 
 * 
 */
class ImportJsonMetadata
{

	/**
	 * Constructor
	 */
	function __construct()
	{
		log_message('debug', "ImportJsonMetadata Class Initialized.");
		$this->ci =& get_instance();

		$this->ci->load->model("Editor_model");
        $this->ci->load->model("Editor_datafile_model");
        $this->ci->load->model("Editor_variable_model");
        $this->ci->load->model("Editor_variable_groups_model");
        $this->ci->load->model("Geospatial_features_model");
        $this->ci->load->model("Geospatial_feature_chars_model");
	}
    
    function import($sid,$json_file_path,$validate=true,$options=array())
    {
        $json = file_get_contents($json_file_path);
        $json_data = json_decode($json, true);

        if (!isset($json_data['type'])){
            throw new Exception("Invalid JSON file. Missing 'type' field.");
        }

        $type=$json_data['type'];

        if (isset($options['type']) && $options['type']!=$type){
            throw new Exception("Project type mismatched. The metadata 'type' is set to '".$json_data['type']."', but the project type is set to '".$options['type']."'.");
        }

        $json_data=array_merge($json_data, $options);

        //fix for geospatial
        if ($type=='geospatial'){
			if (isset($json_data['description']['identificationInfo']) && 
                    is_array($json_data['description']['identificationInfo']) &&
                    isset($json_data['description']['identificationInfo'][0])
                ){
				$json_data['description']['identificationInfo']=$json_data['description']['identificationInfo'][0];
			}
		}

        if ($type=='survey'){
            $this->import_microdata_project($type, $sid,$json_data,$validate);
        }
        else if ($type=='geospatial'){
            $this->import_geospatial_project($type, $sid,$json_data,$validate,$options);
        }
        else{
            //import project metadata
            $this->import_project_metadata($type, $sid,$json_data,$validate);
        }

        return true;
    }


    function import_microdata_project($type, $sid,$json_data,$validate=true)
    {
        $datafiles=[];
        $variables=[];
        $variable_groups=[];

        //get data files, variables and variable groups
        if (isset($json_data['data_files'])){
            $datafiles=$json_data['data_files'];
            unset($json_data['data_files']);
        }

        if (isset($json_data['variables'])){
            $variables=$json_data['variables'];
            unset($json_data['variables']);
        }

        if (isset($json_data['variable_groups'])){
            $variable_groups=$json_data['variable_groups'];
            unset($json_data['variable_groups']);
        }

        $this->import_project_metadata($type, $sid,$json_data, $validate);

        //import data file metadata
        $file_id_mappings= $this->import_datafile_metadata($sid,$datafiles, $validate);

        //import variable metadata
        $this->import_variable_metadata($sid,$variables, $file_id_mappings, $validate);

        //import variable groups
        //$this->import_variable_groups($sid,$variable_groups, $validate);
    }

    /**
     * 
     * Import project metadata
     * 
     */
    function import_project_metadata($type,$sid,$json_data,$validate=true)
    {
        $this->ci->Editor_model->update_project($type,$sid,$json_data,$validate);
    }
    

    /**
     * 
     * Import data file metadata
     * 
     */
    function import_datafile_metadata($sid,$datafiles,$validate=true)
    {
        $file_id_mapping=[];//need these to map file_id in variables

        foreach($datafiles as $datafile)
        {
            //check if file exists
            $file_info=$this->ci->Editor_datafile_model->data_file_by_name($sid,$datafile['file_name']);

            if ($file_info){
                $file_id_mapping[$datafile['file_id']]=$file_info['file_id'];
                $datafile['file_id']=$file_info['file_id'];

                if ($validate){
                    $this->ci->Editor_datafile_model->validate($datafile);
                }            
    
                $this->ci->Editor_datafile_model->update($file_info['id'],$datafile);
            }
            else{
                $file_id=$this->ci->Editor_datafile_model->generate_fileid($sid);
                $file_id_mapping[$datafile['file_id']]=$file_id;
                $datafile['file_id']=$file_id;

                if ($validate){
                    $this->ci->Editor_datafile_model->validate($datafile);
                }
                
                $this->ci->Editor_datafile_model->insert($sid,$datafile);
            }
        }
        return $file_id_mapping;
    }


    /**
     * 
     * Import variable metadata
     * 
     */
    function import_variable_metadata($sid,$variables, $file_id_mappings, $validate=true)
    {
        foreach($variables as $variable){

            if ($validate){
                $this->ci->Editor_variable_model->validate($variable);
            }

            $fid=$file_id_mappings[$variable['fid']];
            $variable['fid']=$fid;

            //check if variable exists
            $variable_info=$this->ci->Editor_variable_model->variable_by_name($sid,$fid, $variable['name']);
            if (!isset($variable['var_catgry_labels'])){
                $variable['var_catgry_labels']=$this->get_variable_category_value_labels($variable);
            }

            //remove fields
            $exclude=array("uid","sid");
            foreach($exclude as $field)
            {
                if (isset($variable[$field])){
                    unset($variable[$field]);
                }
            }            
            
            $variable['metadata']=$variable;

            if ($variable_info){
                $this->ci->Editor_variable_model->update($sid, $variable_info['uid'],$variable);
            }
            else{
                //if not exists, insert
                $this->ci->Editor_variable_model->insert($sid,$variable);
            }
        }
    }


    function get_variable_category_value_labels($variable)
    {
        $labels=[];

        if (isset($variable['var_catgry'])){
            foreach($variable['var_catgry'] as $category){
                if (isset($category['value'])){                    
                    $labels[]=array(
                        'value'=>$category['value'],
                        'labl'=>isset($category['labl']) ? $category['labl'] : ''
                    );
                }
            }
        }

        return $labels;
    }

    /**
     * 
     * Import geospatial project metadata
     * Extracts feature catalogue featureType and imports into database tables
     * 
     */
    function import_geospatial_project($type, $sid, $json_data, $validate=true, $options=array())
    {
        $feature_types = array();
        
        log_message('debug', "import_geospatial_project called for sid: {$sid}");
        
        // Extract featureType from feature_catalogue
        // Check both locations: description.feature_catalogue.featureType (new structure) and feature_catalogue.featureType (root level)
        if (isset($json_data['description']['feature_catalogue']['featureType']) && 
            is_array($json_data['description']['feature_catalogue']['featureType'])) {
            $feature_types = $json_data['description']['feature_catalogue']['featureType'];
            log_message('debug', "Found featureType in description.feature_catalogue, count: " . count($feature_types));
            // Remove from json_data before storing in project metadata
            unset($json_data['description']['feature_catalogue']['featureType']);
        }
        else if (isset($json_data['feature_catalogue']) && 
                 isset($json_data['feature_catalogue']['featureType']) && 
                 is_array($json_data['feature_catalogue']['featureType'])) {
            $feature_types = $json_data['feature_catalogue']['featureType'];
            log_message('debug', "Found featureType in root feature_catalogue, count: " . count($feature_types));
            log_message('debug', "First featureType sample: " . json_encode(isset($feature_types[0]) ? $feature_types[0] : 'empty'));
            
            // Move feature_catalogue to description.feature_catalogue if it doesn't exist there
            if (!isset($json_data['description']['feature_catalogue'])) {
                if (!isset($json_data['description'])) {
                    $json_data['description'] = array();
                }
                // Copy all feature_catalogue data except featureType
                $feature_catalogue_data = $json_data['feature_catalogue'];
                unset($feature_catalogue_data['featureType']);
                $json_data['description']['feature_catalogue'] = $feature_catalogue_data;
            } else {
                // If description.feature_catalogue already exists, just remove featureType from root level
                unset($json_data['feature_catalogue']['featureType']);
            }
            // Remove root level feature_catalogue (it's been moved or featureType removed)
            unset($json_data['feature_catalogue']);
        } else {
            log_message('debug', "No featureType found. Checking structure: " . json_encode(array(
                'has_description_feature_catalogue' => isset($json_data['description']['feature_catalogue']),
                'has_feature_catalogue' => isset($json_data['feature_catalogue']),
                'feature_catalogue_keys' => isset($json_data['feature_catalogue']) ? array_keys($json_data['feature_catalogue']) : array()
            )));
        }
        
        // Import project metadata (without featureType)
        $this->import_project_metadata($type, $sid, $json_data, $validate);
        
        // Import feature types and characteristics
        if (!empty($feature_types)) {
            $user_id = isset($options['user_id']) ? $options['user_id'] : (isset($options['created_by']) ? $options['created_by'] : null);
            log_message('debug', "Calling import_feature_catalogue with " . count($feature_types) . " feature types, user_id: {$user_id}");
            try {
                $result = $this->import_feature_catalogue($sid, $feature_types, $user_id, $validate);
                log_message('info', "Geospatial feature catalogue import completed: " . json_encode($result));
            } catch (Exception $e) {
                log_message('error', "Error importing feature catalogue: " . $e->getMessage());
                log_message('error', "Stack trace: " . $e->getTraceAsString());
                throw $e;
            }
        } else {
            log_message('warning', "No feature types found in feature_catalogue for project {$sid}");
        }
    }

    /**
     * 
     * Import feature catalogue (feature types and characteristics) into database
     * 
     * @param int $sid - Project ID
     * @param array $feature_types - Array of featureType objects from JSON
     * @param int $user_id - User ID for created_by/changed_by
     * @param bool $validate - Whether to validate data
     * 
     */
    function import_feature_catalogue($sid, $feature_types, $user_id=null, $validate=true)
    {
        $features_imported = 0;
        $characteristics_imported = 0;
        
        log_message('debug', "import_feature_catalogue called with " . count($feature_types) . " feature types for sid: {$sid}");
        
        foreach ($feature_types as $idx => $feature_type) {
            try {
                log_message('debug', "Processing feature type #{$idx}: " . json_encode(array(
                    'typeName' => isset($feature_type['typeName']) ? $feature_type['typeName'] : 'MISSING',
                    'code' => isset($feature_type['code']) ? $feature_type['code'] : 'MISSING',
                    'has_carrierOfCharacteristics' => isset($feature_type['carrierOfCharacteristics'])
                )));
                // Extract feature type data
                $type_name = isset($feature_type['typeName']) ? $feature_type['typeName'] : '';
                $code = isset($feature_type['code']) ? $feature_type['code'] : '';
                $definition = isset($feature_type['definition']) ? $feature_type['definition'] : '';
                $is_abstract = isset($feature_type['isAbstract']) ? $feature_type['isAbstract'] : false;
                $carrier_of_characteristics = isset($feature_type['carrierOfCharacteristics']) && is_array($feature_type['carrierOfCharacteristics']) 
                    ? $feature_type['carrierOfCharacteristics'] 
                    : array();
                
                if (empty($type_name)) {
                    log_message('warning', 'Skipping feature type with empty typeName');
                    continue;
                }
                
                // Prepare feature metadata
                $feature_metadata = array();
                if (!empty($definition)) {
                    $feature_metadata['definition'] = $definition;
                }
                if ($is_abstract !== false) {
                    $feature_metadata['isAbstract'] = $is_abstract;
                }
                
                // Check if feature exists by code or name within the project
                $existing_feature = null;
                
                // Check by code within project first (if code is provided)
                if (!empty($code)) {
                    $existing_feature = $this->ci->Geospatial_features_model->select_by_code_and_project($code, $sid);
                    if ($existing_feature) {
                        log_message('debug', "Found existing feature by code '{$code}' in project {$sid}");
                    }
                }
                
                // If not found by code, check by name within project
                if (!$existing_feature) {
                    $project_features = $this->ci->Geospatial_features_model->select_by_project($sid);
                    foreach ($project_features as $proj_feature) {
                        if (isset($proj_feature['name']) && $proj_feature['name'] == $type_name) {
                            $existing_feature = $proj_feature;
                            log_message('debug', "Found existing feature by name '{$type_name}' in project {$sid}");
                            break;
                        }
                    }
                }
                
                // Prepare feature data
                $feature_data = array(
                    'sid' => $sid,
                    'name' => $type_name,
                    'code' => !empty($code) ? $code : null,
                    'metadata' => !empty($feature_metadata) ? $feature_metadata : null
                );
                
                if ($user_id) {
                    $feature_data['created_by'] = $user_id;
                    $feature_data['changed_by'] = $user_id;
                }
                
                // Insert or update feature
                if ($existing_feature) {
                    // Update existing feature
                    log_message('debug', "Updating existing feature: {$type_name} (ID: {$existing_feature['id']})");
                    $this->ci->Geospatial_features_model->update($existing_feature['id'], $feature_data);
                    $feature_id = $existing_feature['id'];
                    log_message('info', "Updated existing feature: {$type_name} (ID: {$feature_id})");
                } else {
                    // Insert new feature
                    log_message('debug', "Inserting new feature: {$type_name} with data: " . json_encode($feature_data));
                    try {
                        $feature_id = $this->ci->Geospatial_features_model->insert($feature_data);
                        if (!$feature_id) {
                            throw new Exception("Insert returned no ID");
                        }
                        log_message('info', "Inserted new feature: {$type_name} (ID: {$feature_id})");
                        $features_imported++;
                    } catch (Exception $e) {
                        log_message('error', "Failed to insert feature {$type_name}: " . $e->getMessage());
                        log_message('error', "Feature data: " . json_encode($feature_data));
                        throw $e;
                    }
                }
                
                // Import characteristics
                if (!empty($carrier_of_characteristics) && $feature_id) {
                    foreach ($carrier_of_characteristics as $characteristic) {
                        try {
                            $char_member_name = isset($characteristic['memberName']) ? $characteristic['memberName'] : '';
                            if (empty($char_member_name)) {
                                log_message('warning', "Skipping characteristic with empty memberName for feature: {$type_name}");
                                continue;
                            }
                            
                            // Prepare characteristic metadata
                            $char_metadata = array();
                            if (isset($characteristic['definition']) && !empty($characteristic['definition'])) {
                                $char_metadata['definition'] = $characteristic['definition'];
                            }
                            if (isset($characteristic['code']) && !empty($characteristic['code'])) {
                                $char_metadata['code'] = $characteristic['code'];
                            }
                            if (isset($characteristic['cardinality']) && is_array($characteristic['cardinality'])) {
                                $char_metadata['cardinality'] = $characteristic['cardinality'];
                            }
                            if (isset($characteristic['valueMeasurementUnit']) && !empty($characteristic['valueMeasurementUnit'])) {
                                $char_metadata['valueMeasurementUnit'] = $characteristic['valueMeasurementUnit'];
                            }
                            if (isset($characteristic['listedValue']) && is_array($characteristic['listedValue']) && !empty($characteristic['listedValue'])) {
                                $char_metadata['listedValue'] = $characteristic['listedValue'];
                            }
                            
                            // Check if characteristic exists
                            $existing_char = $this->ci->Geospatial_feature_chars_model->select_by_name_and_feature($char_member_name, $feature_id);
                            
                            // Prepare characteristic data
                            $char_data = array(
                                'sid' => $sid,
                                'feature_id' => $feature_id,
                                'name' => $char_member_name,
                                'label' => isset($characteristic['definition']) ? $characteristic['definition'] : null,
                                'data_type' => isset($characteristic['valueType']) ? $characteristic['valueType'] : 'string',
                                'metadata' => !empty($char_metadata) ? $char_metadata : null
                            );
                            
                            if ($user_id) {
                                $char_data['created_by'] = $user_id;
                                $char_data['changed_by'] = $user_id;
                            }
                            
                            // Insert or update characteristic
                            if ($existing_char) {
                                // Update existing characteristic
                                $this->ci->Geospatial_feature_chars_model->update($existing_char['id'], $char_data);
                                log_message('info', "Updated existing characteristic: {$char_member_name} for feature: {$type_name}");
                            } else {
                                // Insert new characteristic
                                $this->ci->Geospatial_feature_chars_model->insert($char_data);
                                log_message('info', "Inserted new characteristic: {$char_member_name} for feature: {$type_name}");
                                $characteristics_imported++;
                            }
                        } catch (Exception $e) {
                            // Log error but continue with other characteristics
                            log_message('error', "Failed to import characteristic {$char_member_name} for feature {$type_name}: " . $e->getMessage());
                        }
                    }
                }
            } catch (Exception $e) {
                // Log error but continue with other features
                log_message('error', "Failed to import feature type {$type_name}: " . $e->getMessage());
            }
        }
        
        log_message('info', "Feature catalogue import completed. Features: {$features_imported} new, Characteristics: {$characteristics_imported} new");
        
        return array(
            'features_imported' => $features_imported,
            'characteristics_imported' => $characteristics_imported
        );
    }

}


