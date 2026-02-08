<?php

defined('BASEPATH') OR exit('No direct script access allowed');

require(APPPATH . '/libraries/MY_REST_Controller.php');

/**
 * 
 * Tags API.
 * 
 */
class Tags extends MY_REST_Controller {

    private $api_user;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Tags_model');
        $this->load->library('Editor_acl');
        $this->is_authenticated_or_die();
        $this->api_user = $this->api_user();
    }

    public function _auth_override_check()
    {
        if ($this->session->userdata('user_id')) {
            return true;
        }
        parent::_auth_override_check();
    }

    /**
     * List tags
     * GET /api/tags?is_core=0|1&search=...&limit=...&offset=...
     */
    public function index_get()
    {
        try {
            $this->has_access($resource_ = 'editor', $privilege = 'view');

            $filters = array();
            if ($this->input->get('is_core') !== null && $this->input->get('is_core') !== '') {
                $filters['is_core'] = (int) $this->input->get('is_core');
            }
            if ($this->input->get('search') !== null && trim($this->input->get('search')) !== '') {
                $filters['search'] = $this->input->get('search');
            }
            $limit = $this->input->get('limit') ? (int) $this->input->get('limit') : null;
            $offset = (int) $this->input->get('offset');
            $with_counts = $this->input->get('with_counts') === '1' || $this->input->get('with_counts') === 'true';

            $result = $with_counts
                ? $this->Tags_model->get_all_with_counts($filters, $limit, $offset)
                : $this->Tags_model->get_all($filters, $limit, $offset);

            $response = array(
                'status'  => 'success',
                'total'   => $result['total'],
                'tags'    => $result['tags'],
                'offset'  => $result['offset'],
                'limit'   => $result['limit'],
            );
            $this->set_response($response, REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get tags for a project.
     * GET /api/tags/project/{sid}
     */
    public function project_get($sid = null)
    {
        try {
            $sid = $this->get_sid($sid);
            $this->editor_acl->user_has_project_access($sid, $permission = 'view', $this->api_user);

            $tags = $this->Tags_model->get_tags_by_project($sid);

            $response = array(
                'status' => 'success',
                'tags'   => $tags,
            );
            $this->set_response($response, REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Add tags to a project. Accepts tag IDs and/or tag names; names are created if they do not exist.
     * POST /api/tags/project/{sid}
     * Body: { "tags": [12, 15, 12, "A new tag"] }
     */
    public function project_post($sid = null)
    {
        try {
            $sid = $this->get_sid($sid);
            $this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

            $input = $this->raw_json_input();
            if (empty($input) || !isset($input['tags']) || !is_array($input['tags'])) {
                throw new Exception('Body must contain "tags" array (tag IDs and/or tag names)');
            }

            $tag_ids = $this->Tags_model->add_tags_to_project($sid, $input['tags']);

            $response = array(
                'status'  => 'success',
                'message' => count($tag_ids) ? count($tag_ids) . ' tag(s) added' : 'No valid tags',
                'tags'    => $this->Tags_model->get_tags_by_project($sid),
            );
            $this->set_response($response, REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Remove tags from a project. Accepts tag IDs and/or tag names.
     * POST /api/tags/remove_project_tags/{sid}
     * Body: { "tags": [12, 15, "survey"] }
     */
    public function remove_project_tags_post($sid = null)
    {
        try {
            $sid = $this->get_sid($sid);
            $this->editor_acl->user_has_project_access($sid, $permission = 'edit', $this->api_user);

            $input = $this->raw_json_input();
            if (empty($input) || !isset($input['tags']) || !is_array($input['tags'])) {
                throw new Exception('Body must contain "tags" array (tag IDs and/or tag names)');
            }

            $tag_ids = $this->Tags_model->resolve_to_tag_ids($input['tags']);
            foreach ($tag_ids as $tag_id) {
                $this->Tags_model->remove_tag_from_project($sid, $tag_id);
            }

            $response = array(
                'status'  => 'success',
                'message' => count($tag_ids) ? count($tag_ids) . ' tag(s) removed' : 'No valid tags to remove',
                'tags'    => $this->Tags_model->get_tags_by_project($sid),
            );
            $this->set_response($response, REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Delete a tag by ID. Removes the tag and all project assignments.
     * POST /api/tags/delete/{id}
     */
    public function delete_tag_post($id = null)
    {
        try {
            $this->has_access($resource_ = 'editor', $privilege = 'edit');
            $id = (int) $id;

            if ($id < 1) {
                throw new Exception('Invalid tag ID');
            }

            if (!$this->Tags_model->tag_exists($id)) {
                throw new Exception('Tag not found');
            }
            
            $this->Tags_model->delete($id);
            $this->set_response(array(
                'status'  => 'success',
                'message' => 'Tag deleted',
            ), REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Delete all tags that are not used by any project.
     * POST /api/tags/remove_unused
     */
    public function remove_unused_post()
    {
        try {
            $this->has_access($resource_ = 'editor', $privilege = 'edit');
            $deleted = $this->Tags_model->delete_unused();
            $this->set_response(array(
                'status'  => 'success',
                'message' => $deleted ? $deleted . ' unused tag(s) removed' : 'No unused tags',
                'deleted' => $deleted,
            ), REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }
}
