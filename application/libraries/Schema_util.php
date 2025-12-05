<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');


use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use JsonSchema\Uri\UriResolver;
use JsonSchema\Uri\UriRetriever;
use JsonSchema\Constraints\Factory;
use JsonSchema\Constraints\Constraint;

/**
 *
 * JSON Schema helper class
 * 
 *
 */ 
class Schema_util
{
	/**
	 * Constructor
	 */
	function __construct()
	{
        $this->ci =& get_instance();
        $this->ci->load->helper('array');
		log_message('debug', "Schema_validator Class Initialized.");
		//$this->ci =& get_instance();
	}



    /**
     * 
     * 
     * Return schema version and ID
     * 
     *  - schema ID: $id
     *  - Schema version: version
     * 
     * 
     * 
     */
    function get_schema_version_info($schema_name)
    {
        // Try to get schema from registry first
        try {
            $this->ci->load->model('Metadata_schemas_model');
            
            // Get schema file path (handles aliases and filename mismatches)
            $main_path = $this->ci->Metadata_schemas_model->get_schema_file_path($schema_name);
            
            // Read schema file
            $schema_json = file_get_contents($main_path);
            $schema_data = json_decode($schema_json, true);
            
            if ($schema_data === null) {
                throw new Exception("SCHEMA-INVALID-JSON: " . $main_path);
            }
            
            // Return schema version and id
            return array(
                'version' => isset($schema_data['version']) ? $schema_data['version'] : '0.0.1',
                '$id' => isset($schema_data['$id']) ? $schema_data['$id'] : ''
            );
        } catch (Exception $e) {
            // If schema registry lookup fails, fall back to hard-coded list
            // This maintains backward compatibility
        }

        // Fallback to hard-coded schemas for backward compatibility
        $schemas=array(
            'survey'=>'survey',
            'microdata'=>'survey',
            'table'=>'table',
            'document' => 'document',
            'geospatial'=>'geospatial',
            'image' => 'image',
            'timeseries' => 'timeseries',
            'indicator' => 'timeseries',
            'timeseries-db' =>  'timeseries-db',
            'indicator-db' => 'timeseries-db',
            'resource' => 'resource',
            'video' => 'video',
            'script' => 'script'
        );

        // Check if schema_name is in the hard-coded list or maps to one
        $actual_schema = null;
        if (isset($schemas[$schema_name])) {
            $actual_schema = $schemas[$schema_name];
        } elseif (in_array($schema_name, array_values($schemas))) {
            $actual_schema = $schema_name;
        }

        if ($actual_schema === null) {
            throw new Exception("INVALID_SCHEMA: ".$schema_name);
        }

        $schema_file="application/schemas/".$actual_schema."-schema.json";

		if(!file_exists($schema_file)){
			throw new Exception("SCHEMA-NOT-FOUND: ".$schema_file);
        }

        $schema_file_path='file://' .unix_path(realpath($schema_file));

        //read schema file
        $schema_json = file_get_contents($schema_file_path);

        $schema = json_decode($schema_json);

        //return schema version and id
        return array(
            'version'=>isset($schema->version) ? $schema->version : '0.0.1',
            '$id'=>$schema->{'$id'}            
        );

    }

}//end-class