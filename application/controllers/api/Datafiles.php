<?php

require(APPPATH.'/libraries/MY_REST_Controller.php');

class Datafiles extends MY_REST_Controller
{
	public function __construct()
	{
		parent::__construct();
		$this->load->helper("date");
		$this->load->model("Editor_model");
		$this->load->model("Editor_datafile_model");
		$this->load->model("Editor_variable_model");
		
		$this->load->library("Editor_acl");
		$this->is_authenticated_or_die();
		$this->api_user=$this->api_user();
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
	 * list study data files
	 * 
	 */
	function index_get($id=null, $file_id=null)
	{
		try{

			if (!$id){
				throw new Exception("Missing required parameter: id");
			}

			if ($file_id){
				$this->file_get($id,$file_id);
				return;
			}

			$this->editor_acl->user_has_project_access($id,$permission='view', $this->api_user);
			
			$user_id=$this->get_api_user_id();
			$survey_datafiles=$this->Editor_datafile_model->select_all($id,true);
			
			$response=array(
				'datafiles'=>$survey_datafiles
			);

			$this->set_response($response, REST_Controller::HTTP_OK);
		}
		catch(Exception $e){
			$error_output=array(
				'status'=>'failed',
				'message'=>$e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}


	function file_get($sid=null,$file_id=null)
	{
		try{
			$sid=$this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid,$permission='view', $this->api_user);
			
			$user_id=$this->get_api_user_id();
			$survey_datafiles=$this->Editor_datafile_model->data_file_by_id($sid,$file_id);
			
			$response=array(
				'datafile'=>$survey_datafiles
			);

			$this->set_response($response, REST_Controller::HTTP_OK);
		}
		catch(Exception $e){
			$error_output=array(
				'status'=>'failed',
				'message'=>$e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Get columns diff between DB variables and CSV for a data file.
	 * 
	 * GET api/datafiles/columns_diff/{sid}/{file_id}
	 * 
	 * @param bool $include_names Include all variable names from db and csv in the result
	 */
	function columns_diff_get($sid=null, $file_id=null, $include_names=false)
	{
		try{
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission='view', $this->api_user);

			if (!$file_id) {
				throw new Exception("Missing required parameter: file_id");
			}

			$datafile = $this->Editor_datafile_model->data_file_by_id($sid, $file_id);
			if (!$datafile) {
				throw new Exception("Data file not found");
			}

			$result=$this->Editor_datafile_model->get_columns_out_of_sync($sid, $file_id, $include_names);
			$result= array(
				'status'=>'success',
				'columns_diff'=>$result
			);			 
			
			$this->set_response($result, REST_Controller::HTTP_OK);
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
	 * 
	 * Create or update a data file
	 * 
	 */
	function index_post($sid=null)
	{
		try{
			$sid=$this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid,$permission='edit',$this->api_user);

			$options=$this->raw_json_input();
			$user_id=$this->get_api_user_id();
			$options['created_by']=$user_id;
			$options['changed_by']=$user_id;
			$options['sid']=$sid;			

			/*$required_fields=array("file_id","file_name");

			foreach($required_fields as $field_){
				if(!isset($options[$field_])){
					throw new Exception("Required field is missing: ".$field_);
				}
			}*/

			//validate 
			if ($this->Editor_datafile_model->validate_data_file($options)){
				$options['file_uri']=$options['file_name'];
				$options['file_name']=$this->Editor_datafile_model->data_file_filename_part($options['file_name']);

				if (isset($options['id'])){
					$data_file=$this->Editor_datafile_model->data_file_by_pk_id($options['id'],$sid);

					if (!$data_file){
						throw new Exception("Data file not found");
					}

					$data_file_by_name=$this->Editor_datafile_model->data_file_by_name($sid,$options['file_name']);

					if($data_file_by_name && $data_file_by_name['id']!=$options['id']){
						throw new Exception("Data file name already exists");
					}

					$this->Editor_datafile_model->data_file_update($data_file["id"],$options);
				}else{

					//check if file name exists
					$data_file=$this->Editor_datafile_model->data_file_by_name($sid,$options['file_name']);

					if ($data_file){
						throw new Exception("Data file name already exists");
					}

					$this->Editor_datafile_model->data_file_insert($sid,$options);					
				}
				
				$response=array(
					'status'=>'success',
					'datafile'=>$options
				);

				$this->set_response($response, REST_Controller::HTTP_OK);
			}
		}
		catch(ValidationException $e){
			$error_output=array(
				'status'=>'failed',
				'message'=>$e->getMessage(),
				'errors'=>$e->GetValidationErrors()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
		catch(Exception $e){
			$error_output=array(
				'status'=>'failed',
				'message'=>$e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}


	/**
	 * 
	 * 
	 * Update data files sequence
	 * 
	 */
	function sequence_post($sid=null)
	{
		try{
			$sid=$this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid,$permission='edit', $this->api_user);

			$options=$this->raw_json_input();
			$user_id=$this->get_api_user_id();			
			$options['sid']=$sid;

			$required_fields=array("wght","id");

			if (!isset($options['options'])){
				throw new Exception("Required field is missing: options");
			}

			$options=$options['options'];

			for($i=0;$i<count($options);$i++){			
				$row=$options[$i];

				if (!isset($row['id'])){
					throw new Exception("Required field is missing: id");
				}

				if (!isset($row['wght'])){
					throw new Exception("Required field is missing: wght");
				}

				$update_options=array(
					'wght'=>$row['wght']
				);

				$this->Editor_datafile_model->data_file_update($row['id'],$update_options);
			}
			
				
			$response=array(
				'status'=>'success',
				'datafile'=>$options
			);

			$this->set_response($response, REST_Controller::HTTP_OK);
		}
		catch(ValidationException $e){
			$error_output=array(
				'status'=>'failed',
				'message'=>$e->getMessage(),
				'errors'=>$e->GetValidationErrors()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
		catch(Exception $e){
			$error_output=array(
				'status'=>'failed',
				'message'=>$e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Duplicate a data file: creates a new data file with a new file_id and file_name,
	 * copies all variables, and copies the CSV data file.
	 *
	 * POST api/datafiles/duplicate/{sid}/{file_id}
	 */
	function duplicate_post($sid = null, $file_id = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			if (!$file_id) {
				throw new Exception("Missing required parameter: file_id");
			}

			$user_id = $this->get_api_user_id();
			$new_datafile = $this->Editor_datafile_model->duplicate_datafile($sid, $file_id, $user_id);

			$response = array(
				'status' => 'success',
				'datafile' => $new_datafile
			);
			$this->set_response($response, REST_Controller::HTTP_OK);
		} catch (Exception $e) {
			$error_output = array(
				'status' => 'failed',
				'message' => $e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * 
	 * Export metadata for a single data file and its variables as JSON download.
	 * 
	 * 
	 * Response: attachment {file_name}_metadata.json
	 */
	function export_metadata_get($sid = null, $file_id = null)
	{
		try {
			$sid = $this->get_sid($sid);
			if (!$file_id) {
				throw new Exception("Missing required parameter: file_id");
			}
			$this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

			$this->load->library('Project_json_writer');
			$this->project_json_writer->download_datafile_metadata_json($sid, $file_id);			
			return;
		} catch (Exception $e) {
			$error_output = array(
				'status' => 'failed',
				'message' => $e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Replace metadata for an existing data file and its variables.
	 * POST api/datafiles/replace_metadata/{sid}/{file_id}
	 * Body: multipart with 'file' = JSON file, or raw JSON { "datafile": {...}, "variables": [...] }
	 */
	function replace_metadata_post($sid = null, $file_id = null)
	{
		try {
			$sid = $this->get_sid($sid);
			if (!$file_id) {
				throw new Exception("Missing required parameter: file_id");
			}
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$payload = null;
			if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
				$this->load->model('Editor_resource_model');
				$allowed = 'json';
				$uploaded = $this->Editor_resource_model->upload_temporary_file($allowed, $file_field_name = 'file', $temp_upload_folder = null);
				if (!file_exists($uploaded)) {
					throw new Exception("Failed to upload file");
				}
				$json = file_get_contents($uploaded);
				$payload = json_decode($json, true);
			} else {
				$payload = $this->raw_json_input();
			}

			if (!$payload || !is_array($payload)) {
				throw new Exception("Invalid or missing JSON payload. Expected { \"datafile\": {...}, \"variables\": [...] }");
			}

			$this->load->library('ImportJsonMetadata');
			$user_id = $this->get_api_user_id();
			$result = $this->importjsonmetadata->replace_datafile_metadata($sid, $file_id, $payload, $validate = true, $user_id);

			$this->set_response(array(
				'status' => 'success',
				'datafile' => $result['datafile'],
				'variables_count' => $result['variables_count']
			), REST_Controller::HTTP_OK);
		} catch (ValidationException $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage(),
				'errors' => $e->GetValidationErrors()
			), REST_Controller::HTTP_BAD_REQUEST);
		} catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage()
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * Delete only the physical CSV/data file; keep the datafile record (definition).
	 * Use this to clear data while keeping the file entry in the project.
	 *
	 * POST /api/datafiles/delete_file/{sid}/{file_id}
	 */
	function delete_file_post($sid = null, $file_id = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			if ($this->Editor_datafile_model->delete_physical_file($sid, $file_id) === false) {
				throw new Exception("Failed to delete the data file or file not found.");
			}

			$response = array(
				'status' => 'success',
				'message' => 'Data file (CSV) deleted. Definition kept.'
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
	 * Delete a data file (physical file + database record and variables).
	 *
	 * POST /api/datafiles/delete/{sid}/{file_id}
	 */
	function delete_post($sid=null,$file_id=null)
	{
		try{
			$sid=$this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid,$permission='edit',$this->api_user);
			//$this->Editor_datafile_model->cleanup($sid,$file_id);
			$this->Editor_datafile_model->delete_physical_file($sid,$file_id);
			$this->Editor_datafile_model->delete($sid,$file_id);
				
			$response=array(
				'status'=>'success'					
			);

			$this->set_response($response, REST_Controller::HTTP_OK);
		}
		catch(Exception $e){
			$error_output=array(
				'status'=>'failed',
				'message'=>$e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}
	


	function download_get($sid=null,$fid=null,$type=null)
	{
		try{			
			if (!$sid || !$fid){
				throw new Exception("Missing required parameters");
			}

			$this->load->helper("download");
			$valid_types=array('original','csv');

			$this->editor_acl->user_has_project_access($sid,$permission='edit', $this->api_user);
			$files=$this->Editor_datafile_model->get_files_info($sid,$fid);

			if (!$type || !in_array($type,$valid_types)){
				$type='original';
			}

			if (!isset($files[$type]['filepath'])){
				throw new Exception("File not found");
			}

			$file_path=$files[$type]['filepath'];

			if (file_exists($file_path)){				
				force_download2($file_path);
			}
			else{
				throw new Exception("File not found");
			}
			
		}
		catch(Exception $e){
			$error=array(
				'error'=>$e->getMessage()
			);
			$this->set_response($error, REST_Controller::HTTP_BAD_REQUEST);
		}
	}



	/**
	 * 
	 * Exported temporary files
	 * 
	 */
	function download_tmp_file_get($sid=null,$fid=null,$type=null)
	{
		try{			
			if (!$sid || !$fid || !$type){
				throw new Exception("Missing required parameters");
			}

			$this->load->helper("download");
			$valid_types=array('dta','csv','sav','json', 'sas','xpt');

			if (!in_array($type,$valid_types)){
				throw new Exception("Invalid file type");
			}

			$this->editor_acl->user_has_project_access($sid,$permission='edit',$this->api_user);
			$tmp_file_info=$this->Editor_datafile_model->get_tmp_file_info($sid,$fid,$type);

			if (file_exists($tmp_file_info['filepath'])){
				force_download2($tmp_file_info['filepath']);
				#unlink($tmp_file_info['filepath']);
			}
			else{
				throw new Exception("File not found");
			}
			
		}
		catch(Exception $e){
			$error=array(
				'error'=>$e->getMessage()
			);
			$this->set_response($error, REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * 
	 * Create a zip of exported tmp files in project data/tmp.
	 * 
	 * POST /api/datafiles/batch_export_zip/{sid}
	 * Body: { "filenames": [ "survey1.csv", "survey1.dta", ... ], "zip_filename": "optional.zip" }
	 * zip_filename: optional target zip name (e.g. example_file1.zip for single file). Default: batch_export_YYYYMMDD_HHMMSS.zip
	 * 
	 */
	function batch_export_zip_post($sid = null)
	{
		try {
			$sid = $this->get_sid($sid);
			$exists = $this->Editor_model->check_id_exists($sid);
			if (!$exists) {
				throw new Exception("Project not found");
			}
			$this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

			$body = $this->raw_json_input();
			if (!is_array($body)) {
				$body = array();
			}
			$filenames = isset($body['filenames']) ? $body['filenames'] : array();
			if (!is_array($filenames) || empty($filenames)) {
				throw new Exception("Missing or empty filenames array");
			}

			$project_folder = $this->Editor_model->get_project_folder($sid);
			$tmp_folder = rtrim($project_folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
			$tmp_folder_real = realpath($tmp_folder);
			if ($tmp_folder_real === false || !is_dir($tmp_folder_real)) {
				throw new Exception("Project tmp folder not found");
			}

			// Set time limit to 5 minutes (300 seconds)
			set_time_limit(300);

			$files_to_add = array();
			foreach ($filenames as $name) {
				$base = basename($name);
				if ($base === '' || strpos($name, '..') !== false) {
					continue;
				}
				$full = $tmp_folder_real . DIRECTORY_SEPARATOR . $base;
				if (!file_exists($full) || !is_file($full)) {
					throw new Exception("File not found in tmp: " . $base);
				}
				$resolved = realpath($full);
				if ($resolved === false || strpos($resolved, $tmp_folder_real) !== 0) {
					throw new Exception("Invalid path: " . $base);
				}
				$files_to_add[] = array('full' => $resolved, 'entry' => $base);
			}
			if (empty($files_to_add)) {
				throw new Exception("No valid files to add to zip");
			}

			$zip_filename = isset($body['zip_filename']) && is_string($body['zip_filename']) && trim($body['zip_filename']) !== ''
				? trim($body['zip_filename'])
				: '';
			if ($zip_filename !== '') {
				$zip_filename = basename($zip_filename);
				if ($zip_filename === '' || strpos($body['zip_filename'], '..') !== false) {
					$zip_filename = '';
				}
				if ($zip_filename !== '' && strtolower(pathinfo($zip_filename, PATHINFO_EXTENSION)) !== 'zip') {
					$zip_filename = $zip_filename . '.zip';
				}
			}
			if ($zip_filename === '') {
				$zip_filename = 'batch_export_' . date('Ymd_His') . '.zip';
			}
			$zip_path_full = $tmp_folder_real . DIRECTORY_SEPARATOR . $zip_filename;

			$zip = new ZipArchive();
			if ($zip->open($zip_path_full, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
				throw new Exception("Could not create zip file");
			}
			foreach ($files_to_add as $f) {
				$zip->addFile($f['full'], $f['entry']);
			}
			$zip->close();

			$zip_path_relative = 'data/tmp/' . $zip_filename;
			$output = array(
				'status' => 'success',
				'zip_path' => $zip_path_relative,
				'zip_filename' => $zip_filename
			);
			$this->set_response($output, REST_Controller::HTTP_OK);
		} catch (Exception $e) {
			$this->set_response(array(
				'status' => 'failed',
				'message' => $e->getMessage()
			), REST_Controller::HTTP_BAD_REQUEST);
		}
	}

	/**
	 * 
	 * Get data file by name
	 * 
	 */
	function by_name_get($sid=null)
	{
		try{
			$sid=$this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid,$permission='view', $this->api_user);
			$filename=$this->input->get("filename");

			if(!$filename){
				throw new Exception("Missing required parameter: filename");
			}
			
			$user_id=$this->get_api_user_id();
			$survey_datafiles=$this->Editor_datafile_model->data_file_by_name($sid,$filename);

			if (!$survey_datafiles){
				throw new Exception("Data file not found");
			}
			
			$response=array(
				'datafile'=>$survey_datafiles
			);

			$this->set_response($response, REST_Controller::HTTP_OK);
		}
		catch(Exception $e){
			$error_output=array(
				'status'=>'failed',
				'message'=>$e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}



	/**
	 * 
	 * Get a new file id 
	 * 
	 */
	function generate_fid_get($sid=null)
	{
		try{
			$sid=$this->get_sid($sid);
			$this->editor_acl->user_has_project_access($sid,$permission='view',$this->api_user);
			
			$user_id=$this->get_api_user_id();
			$file_id=$this->Editor_datafile_model->data_file_generate_fileid($sid);

			$response=array(
				'file_id'=>$file_id
			);

			$this->set_response($response, REST_Controller::HTTP_OK);
		}
		catch(Exception $e){
			$error_output=array(
				'status'=>'failed',
				'message'=>$e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}



	/**
	 * 
	 * Clean up temporary data files
	 * 
	 *  - removes original data files [keep only the csv version]
	 * 
	 */
	function cleanup_post($sid=null, $file_id=null)
	{
		try{
			$sid=$this->get_sid($sid);
			$user_id=$this->get_api_user_id();

			$this->editor_acl->user_has_project_access($sid,$permission='edit', $this->api_user);
			$result=$this->Editor_datafile_model->cleanup($sid, $file_id);

			$response=array(
				'status'=>'success',
				'files_removed'=>$result
			);

			$this->set_response($response, REST_Controller::HTTP_OK);
		}
		catch(Exception $e){
			$error_output=array(
				'status'=>'failed',
				'message'=>$e->getMessage()
			);
			$this->set_response($error_output, REST_Controller::HTTP_BAD_REQUEST);
		}
	}

}
