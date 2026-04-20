<?php

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\ClientException;



/**
 * 
 * Editor publish projects to NADA catalogs
 * 
 */
class Editor_publish_model extends ci_model {
 
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Editor_model');
        $this->load->model('Catalog_connections_model');
        $this->load->model('Editor_resource_model');
    }

    function publish_to_catalog($sid,$user_id,$catalog_connection_id,$options=[])
	{
		$conn_info=$this->Catalog_connections_model->get_connection($user_id,$catalog_connection_id);

		if (!$conn_info){
			throw new Exception("Target catalog was not found");
		}

		$project=$this->Editor_model->get_basic_info($sid);

		if (!$project){
			throw new Exception("Project not found");
		}

		$project_type=$project['type'];

		//project mappings for NADA
		$mappings=array(
			'microdata'=>'survey',
			'indicator'=>'timeseries',
			'indicator-db'=>'timeseries-db'			
		);

		if (array_key_exists($project_type, $mappings))
		{
			$project_type=$mappings[$project_type];
		}
		

		$catalog_url=$conn_info['url'].'/index.php/api/datasets/create/'.$project_type;
		$import_ddi_url=$conn_info['url'].'/index.php/api/datasets/import_ddi';
		$catalog_api_key=$conn_info['api_key'];
		
		//project metadata (NADA: JSON create; on failure for survey, fallback to import_ddi)
		return $this->publish_metadata(
			$sid,
			$catalog_url,
			$catalog_api_key,
			$options,
			$project_type,
			$import_ddi_url
		);
	}

	function get_project_metadata_json_path($sid)
	{
		$project=$this->Editor_model->get_basic_info($sid);
		$project_folder=$this->Editor_model->get_project_folder($sid);

		$filename=trim((string)$project['idno'])!=='' ? trim($project['idno']) : nada_hash($project['id']);
		$output_file=$project_folder.'/'.$filename.'.json';

		if (!file_exists($output_file)){
			throw new Exception("JSON metadata file not found" . $output_file);
		}

		return $output_file;
	}


	public function publish_metadata($sid,$catalog_url,$catalog_api_key,$options,$nada_dataset_type=null,$import_ddi_url=null)
	{
		$client = new Client([				
			'base_uri' => $catalog_url,
			'headers' => ['x-api-key' => $catalog_api_key]
		]);
		
		$metadata_json_path=$this->get_project_metadata_json_path($sid);		
		$metadata=json_decode(file_get_contents($metadata_json_path),true);

		if (!$metadata){
			throw new Exception("Failed to load project metadata: ".$metadata_json_path);
		}

		// Convert microdata to survey for NADA catalog compatibility
		if (isset($metadata['type']) && $metadata['type'] == 'microdata') {
			$metadata['type'] = 'survey';
		}

		foreach($options as $key=>$option){
			$metadata[$key]=$option;
		}

		try{
			$api_response = $client->request('POST', '', [
				'json' => $metadata,
				['debug' => false]
			]);

			$response=array(
				'status'=>'success',
				'folder_path'=>$metadata_json_path,
				'code' => $api_response->getStatusCode(),// 200
				'reason' => $api_response->getReasonPhrase(), // OK
				'response_' =>$api_response->getBody()
			);

			$response_text = (string) $api_response->getBody();
			return $this->parse_json_response($response_text);

		} catch (ClientException $e) {
			$resp = $e->getResponse();
			$response_text = (string) $resp->getBody();
			$response_json = null;
			try {
				$response_json = $this->parse_json_response($response_text);
			} catch (Exception $parseEx) {
				// Leave as raw text for message
			}

			if ($nada_dataset_type === 'survey' && !empty($import_ddi_url)) {
				try {
					return $this->publish_metadata_import_ddi($sid, $import_ddi_url, $catalog_api_key, $options);
				} catch (Exception $ddi_ex) {
					throw new ApiRequestException(
						$response_text . ' | DDI import fallback failed: ' . $ddi_ex->getMessage(),
						[
							'status' => $resp->getStatusCode(),
							'reason' => $resp->getReasonPhrase(),
							'response_' => $response_json,
							'api_url' => $catalog_url,
							'ddi_fallback_error' => $ddi_ex->getMessage(),
						]
					);
				}
			}

			throw new ApiRequestException(
				$message = $response_text,
				$details = [
					'status' => $resp->getStatusCode(),// 200
					'reason' => $resp->getReasonPhrase(), // OK
					'response_' =>$response_json,
					'api_url' => $catalog_url
				]
			);
		}
	}

	/**
	 * POST DDI XML to NADA datasets/import_ddi (multipart).
	 * Uses publish option keys: overwrite, repositoryid, access_policy, published, data_remote_url.
	 *
	 * @param string $sid Project id
	 * @param string $import_ddi_url Full URL to .../api/datasets/import_ddi
	 * @param string $catalog_api_key x-api-key value
	 * @param array $options Publish form options from metadata editor
	 * @return mixed Decoded JSON response from NADA
	 */
	private function publish_metadata_import_ddi($sid, $import_ddi_url, $catalog_api_key, $options)
	{
		$ddi_path = $this->Editor_model->generate_project_ddi($sid);

		if (!$ddi_path || !file_exists($ddi_path)) {
			throw new Exception('DDI file was not generated');
		}

		$client = new Client([
			'headers' => ['x-api-key' => $catalog_api_key],
		]);

		$multipart = $this->build_import_ddi_multipart($ddi_path, $options);

		try {
			$api_response = $client->request('POST', $import_ddi_url, [
				'multipart' => $multipart,
			]);
			$response_text = (string) $api_response->getBody();
			$decoded = $this->parse_json_response($response_text);
			if (is_array($decoded)) {
				$decoded['_published_via'] = 'import_ddi';
			}
			return $decoded;
		} catch (ClientException $e) {
			$resp = $e->getResponse();
			throw new Exception((string) $resp->getBody());
		}
	}

	/**
	 * Multipart fields for NADA import_ddi: file, overwrite, repositoryid, access_policy, published, data_remote_url.
	 *
	 * @param string $ddi_path Absolute path to DDI XML
	 * @param array $options Keys from metadata editor publish options
	 * @return array Guzzle multipart array
	 */
	private function build_import_ddi_multipart($ddi_path, $options)
	{
		$file_contents = file_get_contents($ddi_path);
		if ($file_contents === false) {
			throw new Exception('Could not read DDI file');
		}

		$multipart = [
			[
				'name' => 'file',
				'contents' => $file_contents,
				'filename' => basename($ddi_path),
			],
		];

		$overwrite = isset($options['overwrite']) ? strtolower(trim((string) $options['overwrite'])) : 'no';
		if ($overwrite !== 'yes' && $overwrite !== 'no') {
			$overwrite = 'no';
		}
		$multipart[] = ['name' => 'overwrite', 'contents' => $overwrite];

		foreach (['repositoryid', 'access_policy', 'data_remote_url'] as $key) {
			if (!isset($options[$key])) {
				continue;
			}
			$val = $options[$key];
			if ($val === null || $val === '') {
				continue;
			}
			$multipart[] = ['name' => $key, 'contents' => (string) $val];
		}

		if (isset($options['published']) && $options['published'] !== '' && $options['published'] !== null) {
			$multipart[] = ['name' => 'published', 'contents' => (string) (int) $options['published']];
		}

		return $multipart;
	}

	function publish_thumbnail($sid,$user_id,$catalog_connection_id,$options=[])
	{
		$conn_info=$this->Catalog_connections_model->get_connection($user_id,$catalog_connection_id);

		if (!$conn_info){
			throw new Exception("Target catalog was not found");
		}

		$project=$this->Editor_model->get_basic_info($sid);

		if (!$project){
			throw new Exception("Project not found");
		}

		$thumbnail_file=$this->Editor_model->get_thumbnail_file($sid);

		if (!$thumbnail_file){
			throw new Exception("Thumbnail file not found");
		}

		if (!$project['study_idno']){
			throw new Exception("Study IDNO is not set");
		}

		$catalog_url=$conn_info['url'].'/index.php/api/datasets/thumbnail/'.$project['study_idno'];
		$catalog_api_key=$conn_info['api_key'];
		
		$api_response=$this->make_post_file_request($catalog_url, $catalog_api_key, $file_field_name='file', $file_path=$thumbnail_file);
		return $api_response;
	}


	function publish_external_resources($sid,$user_id,$catalog_connection_id,$options=[])
	{
		$conn_info=$this->Catalog_connections_model->get_connection($user_id,$catalog_connection_id);

		if (!$conn_info){
			throw new Exception("Target catalog was not found");
		}

		$project=$this->Editor_model->get_basic_info($sid);

		if (!$project){
			throw new Exception("Project not found");
		}

		$resources=$this->Editor_resource_model->select_all($sid);

		$output=[];

		foreach($resources as $resource){
			$output[]=$this->publish_external_resource_to_catalog($sid, $conn_info, $project, $resource, 'yes');
		}		

		return $output;
	}



	public function publish_external_resource($sid,$user_id,$connection_id,$resource_id,$overwrite="no")
	{
		$conn_info=$this->Catalog_connections_model->get_connection($user_id,$connection_id);

		if (!$conn_info){
			throw new Exception("Target catalog was not found");
		}

		$project=$this->Editor_model->get_basic_info($sid);

		if (!$project){
			throw new Exception("Project not found");
		}

		//get resource
		$resource=$this->Editor_resource_model->select_single($sid,$resource_id);

		if (!$resource){
			throw new Exception("Resource not found");
		}

		return $this->publish_external_resource_to_catalog($sid, $conn_info, $project, $resource, $overwrite);
	}

	/**
	 * POST resource metadata to NADA, then upload attached file when filename is a stored project file (not a URL).
	 *
	 * @param int|string $sid
	 * @param array $conn_info Catalog connection
	 * @param array $project Row from get_basic_info (requires study_idno)
	 * @param array $resource Resource row
	 * @param string $overwrite Passed to NADA (e.g. yes|no)
	 * @return array Keys: resource (catalog JSON response), resource_upload (optional file upload response)
	 */
	private function publish_external_resource_to_catalog($sid, $conn_info, $project, $resource, $overwrite = 'yes')
	{
		if (empty($project['study_idno'])) {
			throw new Exception("Study IDNO is not set");
		}

		$resource_payload = $resource;
		$resource_payload['overwrite'] = $overwrite;

		$catalog_url = $conn_info['url'].'/index.php/api/resources/'.$project['study_idno'];
		$catalog_api_key = $conn_info['api_key'];

		$output = array(
			'resource' => $this->make_post_request($catalog_url, $catalog_api_key, $resource_payload),
		);

		if (!empty((string) $resource_payload['filename']) && !is_url($resource_payload['filename'])) {
			$resource_file_path = $this->Editor_resource_model->get_resource_file_by_name($sid, $resource_payload['filename']);
			if (file_exists($resource_file_path)) {
				$files_url = $conn_info['url'].'/index.php/api/datasets/'.$project['study_idno'].'/files';
				$output['resource_upload'] = $this->make_post_file_request($files_url, $catalog_api_key, 'file', $resource_file_path);
			} else {
				throw new Exception("Resource file not found: " . basename($resource_file_path));
			}
		}

		return $output;
	}

	/**
	 * Fetch catalog info from NADA (collections, data_access_codes) for use in publish form.
	 *
	 * @param int $user_id
	 * @param int $catalog_connection_id
	 * @param int|string $project_id Project ID (sid)
	 * @return array ['collections' => array, 'data_access_codes' => array]
	 */
	public function get_catalog_info($user_id, $catalog_connection_id, $project_id)
	{
		$conn_info = $this->Catalog_connections_model->get_connection($user_id, $catalog_connection_id);
		if (!$conn_info) {
			throw new Exception("Target catalog was not found");
		}

		$project=$this->Editor_model->get_basic_info($project_id);
		if (!$project) {
			throw new Exception("Project not found");
		}

		$study_idno = $project['study_idno'];

		if (!$study_idno) {
			throw new Exception("Study IDNO is not set for project: " . $project_id);
		}

		$base_url = rtrim($conn_info['url'], '/');
		$api_key = $conn_info['api_key'];

		$collections = [];
		$data_access_codes = [];

		try {
			$collections_response = $this->make_get_request(
				$base_url . '/index.php/api/collections',
				$api_key
			);
			$collections = isset($collections_response['collections']) ? $collections_response['collections'] : [];
		} catch (Exception $e) {
			// Return empty so form still works; caller can log if needed
		}

		try {
			$codes_response = $this->make_get_request(
				$base_url . '/index.php/api/catalog/data_access_codes',
				$api_key
			);
			$data_access_codes = isset($codes_response['codes']) ? $codes_response['codes'] : [];
		} catch (Exception $e) {
			// Return empty so form still works
		}

		return [
			'study_info' => $this->get_study_info_from_nada($study_idno, $conn_info),
			'collections_codes' => $collections,
			'data_access_codes' => $data_access_codes,
			'collections_linked' => $this->get_study_collections_from_nada($study_idno, $conn_info)
		];
	}

	private function get_study_info_from_nada($study_idno, $conn_info)
	{
		$base_url = rtrim($conn_info['url'], '/');
		$api_key = $conn_info['api_key'];
		$study_info = null;

		try{
			$study_info = $this->make_get_request(
				$base_url . '/index.php/api/datasets/' . $study_idno,
				$api_key
			);

			if ($study_info && $study_info['dataset']) {
				$study_info = $study_info['dataset'];
				unset($study_info['metadata']);
				$study_info['status'] = 'success';
				return $study_info;
			}

			return [
				'status' => 'failed',
				'error' => 'Study info not found',
				'response' => $study_info
			];
		} catch (Exception $e) {
			return [
				'status' => 'error',
				'error' => $e->getMessage(),
				'response' => $study_info,
				'api_url' => $base_url . '/index.php/api/datasets/' . $study_idno
			];
		}		
	}


	private function get_study_collections_from_nada($study_idno, $conn_info)
	{
		$api_url = rtrim($conn_info['url'], '/') . '/index.php/api/datasets/collections/' . $study_idno;
		$base_url = rtrim($conn_info['url'], '/');
		$api_key = $conn_info['api_key'];

		try {
			$collections = $this->make_get_request($api_url, $api_key);

			$collections_list = [];
			$collections_tmp = [];

			if (isset($collections['datasets']) && is_array($collections['datasets'])) {
				$collections_tmp = $collections['datasets'];
			} elseif (isset($collections['collections']) && is_array($collections['collections'])) {
				$collections_tmp = $collections['collections'];
			}

			foreach ($collections_tmp as $collection) {
				if (!is_array($collection)) {
					continue;
				}
				$owner = isset($collection['collection_owner']) ? $collection['collection_owner'] : null;
				$linked = isset($collection['linked_collection']) ? $collection['linked_collection'] : null;
				if ($owner !== null && $owner !== '') {
					$collections_list[] = $owner;
				}
				if ($linked !== null && $linked !== '' && $linked !== $owner) {
					$collections_list[] = $linked;
				}
			}
			$collections_list = array_values(array_unique($collections_list));

			return [
				'status' => 'success',
				'collections' => $collections_list,
				'api_url' => $api_url
			];
		} catch (Exception $e) {
			return [
				'status' => 'error',
				'error' => $e->getMessage(),
				'collections' => [],
				'api_url' => $api_url
			];
		}
	}

	/**
	 * Parse response body as JSON; throw if not valid JSON.
	 *
	 * @param string $response_text Raw response body
	 * @return mixed Decoded value (array, null, etc.)
	 * @throws Exception When response is not valid JSON
	 */
	private function parse_json_response($response_text)
	{
		$decoded = json_decode($response_text, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$msg = json_last_error_msg();
			$preview = strlen($response_text) > 500 ? substr($response_text, 0, 500) . '...' : $response_text;
			throw new Exception('INVALID_RESPONSE: ' . $preview);
		}
		return $decoded;
	}

	/**
	 * Make GET request to a URL with x-api-key header.
	 *
	 * @param string $url Full URL
	 * @param string $api_key
	 * @return array Decoded JSON response
	 */
	public function make_get_request($url, $api_key)
	{
		$client = new Client([
			'base_uri' => $url,
			'headers' => ['x-api-key' => $api_key],
		]);

		try {
			$api_response = $client->request('GET', '');
			$response_text = (string) $api_response->getBody();
			return $this->parse_json_response($response_text);
		} catch (ClientException $e) {
			$resp = $e->getResponse();
			throw new Exception((string) $resp->getBody());
		} catch (Exception $e) {
			throw new Exception("request failed: " . $e->getMessage());
		}
	}

	public function make_post_request($url, $api_key, $post_body=null, $body_format='json', $headers=null)
	{
		$client = new Client([				
			'base_uri' => $url,
			'headers' => ['x-api-key' => $api_key]
		]);
					
		try{
			$api_response = $client->request('POST', '', [
				'json' => $post_body,
				['debug' => false]
			]);

			$response_text = (string) $api_response->getBody();
			return $this->parse_json_response($response_text);
		} catch (ClientException $e) {
			$resp=$e->getResponse();
			throw new Exception((string) $resp->getBody());			
		}
		catch (Exception $e) {
			throw new Exception("request failed: ". $e->getMessage());
		}
	}

	public function make_post_file_request($url, $api_key, $file_field_name='file', $file_path='')
	{
		$client = new Client([				
			'base_uri' => $url,
			'headers' => ['x-api-key' => $api_key]
		]);
					
		try{	
			$body=[
				'multipart' => [
					[
						'Content-type' => 'multipart/form-data',
						'name'     => $file_field_name,
						'contents' => fopen($file_path, 'r'),
						'filename' => basename($file_path)
					]
				]
			];

			$api_response = $client->request('POST','', $body);
			$response_text = (string) $api_response->getBody();
			return $this->parse_json_response($response_text);
		} catch (ClientException $e) {
			$resp=$e->getResponse();			
			throw new Exception((string) $resp->getBody());
		}
		catch (Exception $e) {
			throw new Exception("request failed: ". $e->getMessage());
		}
	}

	

}    