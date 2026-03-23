<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Configurations for Metadata editor
|--------------------------------------------------------------------------
|
|
*/

//Storage root folder for editor
$config['editor']['storage_path']='/Volumes/webdev/editor/datafiles/editor';

//Path for storing user-defined schemas
$config['editor']['user_schema_path']=rtrim($config['editor']['storage_path'],'/').'/user-schemas';

//Python fastapi server url; url must end with a slash [default - http://localhost:8000/]
$config['editor']['data_api_url']=getenv('EDITOR_DATA_API_URL') ? getenv('EDITOR_DATA_API_URL') : 'http://localhost:8000/';


//MATHJAX options

//enable mathjax processing [default - false]
$config['editor']['mathjax_enabled']=getenv('EDITOR_MATHJAX_ENABLED') ? getenv('EDITOR_MATHJAX_ENABLED') : true;

//mathjax server url; url must end with a slash [default - http://localhost:3000/]
$config['editor']['mathjax_api_url']=getenv('EDITOR_MATHJAX_API_URL') ? getenv('EDITOR_MATHJAX_API_URL') : 'http://localhost:3000/';

//Project sharing configuration
$config['project_sharing'] = true;

