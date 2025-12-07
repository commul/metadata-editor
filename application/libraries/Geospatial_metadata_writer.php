<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Geospatial Metadata Writer
 * 
 * Merge geospatial feature catalogue metadata
 * from project-level metadata and database tables (geospatial_features, geospatial_feature_chars)
 * 
 */
class Geospatial_metadata_writer
{
	private $ci;
	
	/**
	 * Constructor
	 */
	function __construct()
	{
		$this->ci =& get_instance();
		$this->ci->load->model('Geospatial_features_model');
		$this->ci->load->model('Geospatial_feature_chars_model');
	}

	/**
	 * Get merged feature catalogue metadata
	 * 
	 * Merges project-level feature catalogue metadata with feature types
	 * and characteristics from database tables
	 * 
	 * @param int $sid - Project ID
	 * @return array Merged feature catalogue with featureTypes
	 */
	function get_merged_feature_catalogue($sid)
	{
		// Load project metadata
		$this->ci->load->model('Editor_model');
		$metadata = $this->ci->Editor_model->get_row($sid);

		// Get project-level feature catalogue metadata
		$feature_catalogue = array();
		if (isset($metadata['metadata']['description']['feature_catalogue']) && !empty($metadata['metadata']['description']['feature_catalogue'])){
			$feature_catalogue = $metadata['metadata']['description']['feature_catalogue'];
		}

		// Get features from database
		$features = $this->ci->Geospatial_features_model->select_by_project($sid);
		
		if (empty($feature_catalogue) && empty($features)) {
			return array();
		}
		
		$featureTypes = $this->build_feature_types_from_database($features);
		
		// Merge
		if (!empty($featureTypes)) {
			$feature_catalogue['featureType'] = $featureTypes;
		}

		return $feature_catalogue;
	}


	/**
     * 
	 * Get merged feature catalogue metadata as JSON string
	 * 
	 * @param int $sid - Project ID
	 * @return string JSON encoded feature catalogue
	 */
	function get_merged_feature_catalogue_json($sid)
	{
		$feature_catalogue = $this->get_merged_feature_catalogue($sid);
		return json_encode($feature_catalogue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	}

	/**
     * 
	 * Build feature types array from database features
	 * 
	 * @param array $features - Array of features from geospatial_features table
	 * @return array Array of feature types with characteristics
	 */
	private function build_feature_types_from_database($features)
	{
		$featureTypes = array();
		
		foreach ($features as $feature) {
			$featureType = array(
				'typeName' => $feature['name'],
				'code' => isset($feature['code']) && !empty($feature['code']) ? $feature['code'] : $feature['name'],
				'definition' => isset($feature['metadata']['definition']) ? $feature['metadata']['definition'] : (isset($feature['name']) ? $feature['name'] : ''),
				'isAbstract' => isset($feature['metadata']['isAbstract']) ? $feature['metadata']['isAbstract'] : false
			);
			
			$characteristics = $this->ci->Geospatial_feature_chars_model->select_by_feature_id($feature['id']);
			$carrierOfCharacteristics = $this->build_characteristics_from_database($characteristics);
			
			$featureType['carrierOfCharacteristics'] = $carrierOfCharacteristics;
			$featureTypes[] = $featureType;
		}
		
		return $featureTypes;
	}

	/**
     * 
	 * Build characteristics array from database characteristics
	 * 
	 * @param array $characteristics - Array of characteristics from geospatial_feature_chars table
	 * @return array Array of carrierOfCharacteristics
     * 
	 */
	private function build_characteristics_from_database($characteristics)
	{
		$carrierOfCharacteristics = array();
		
		foreach ($characteristics as $char) {
			$characteristic = array(
				'memberName' => $char['name'],
				'definition' => isset($char['metadata']['definition']) ? $char['metadata']['definition'] : (isset($char['label']) && !empty($char['label']) ? $char['label'] : $char['name']),
				'code' => isset($char['metadata']['code']) ? $char['metadata']['code'] : $char['name'],
				'valueType' => isset($char['data_type']) && !empty($char['data_type']) ? $char['data_type'] : 'string',
				'cardinality' => array(
					'lower' => isset($char['metadata']['cardinality']['lower']) ? $char['metadata']['cardinality']['lower'] : 0,
					'upper' => isset($char['metadata']['cardinality']['upper']) ? $char['metadata']['cardinality']['upper'] : 1
				)
			);
			
			// Add valueMeasurementUnit if available
			if (isset($char['metadata']['valueMeasurementUnit']) && !empty($char['metadata']['valueMeasurementUnit'])) {
				$characteristic['valueMeasurementUnit'] = $char['metadata']['valueMeasurementUnit'];
			}
			
			// Add listedValue if available
			if (isset($char['metadata']['listedValue']) && is_array($char['metadata']['listedValue']) && !empty($char['metadata']['listedValue'])) {
				$characteristic['listedValue'] = $char['metadata']['listedValue'];
			}
			
			$carrierOfCharacteristics[] = $characteristic;
		}
		
		return $carrierOfCharacteristics;
	}
}

