<?php
/**
 * Schemas page controller
 */
class Schemas extends MY_Controller {

    public function __construct()
    {
        parent::__construct();
        $this->load->library('Editor_acl');
        $this->lang->load('general');
        $this->lang->load('schemas');
    }

    public function index()
    {
        $this->editor_acl->has_access_or_die($resource_='schema',$privilege='view');
        $options = array(
            'translations' => $this->lang->language
        );
        echo $this->load->view('schemas/index',$options,true);
    }

    public function preview($uid = null)
    {
        $this->editor_acl->has_access_or_die($resource_='schema',$privilege='view');

        if (!$uid) {
            show_404();
            return;
        }

        $this->load->model('Metadata_schemas_model');

        $schema = $this->Metadata_schemas_model->get_by_uid($uid);

        if (!$schema) {
            show_404();
            return;
        }

        $data = array(
            'schema' => $schema,
            'spec_url' => site_url('api/schemas/openapi/' . rawurlencode($uid)),
            'spec_yaml_url' => site_url('api/schemas/openapi/' . rawurlencode($uid) . '?format=yaml'),
            'files_endpoint' => site_url('api/schemas/files/' . rawurlencode($uid)),
            'translations' => $this->lang->language
        );

        echo $this->load->view('schemas/preview_redoc', $data, true);
    }
}

