<?php

require(APPPATH . '/libraries/MY_REST_Controller.php');

/**
 * Project-scoped local codelists (local_codelists / local_codelist_items).
 *
 * Lists (headers):
 *   GET    /api/local_codelists/lists/{sid}
 *   GET    /api/local_codelists/list/{sid}/{list_id}
 *   POST   /api/local_codelists/list/{sid}                    body: { field_id, name?, description? }
 *   POST   /api/local_codelists/list_update/{sid}/{list_id}   body: { name?, description? }
 *   DELETE /api/local_codelists/list_delete/{sid}/{list_id}
 *   POST   /api/local_codelists/list_delete/{sid}/{list_id}   (alias for clients that cannot send DELETE)
 *
 * Items (code / label / sort_order):
 *   GET    /api/local_codelists/items/{sid}/{list_id}?offset=&limit=&sort=code|label|sort_order&order=asc|desc&search=
 *   POST   /api/local_codelists/items/{sid}/{list_id}         body: { code, label?, sort_order? }
 *   POST   /api/local_codelists/item_update/{sid}/{item_id}   body: { code?, label?, sort_order? }
 *   DELETE /api/local_codelists/item_delete/{sid}/{item_id}
 *   POST   /api/local_codelists/item_delete/{sid}/{item_id}   (alias)
 */
class Local_codelists extends MY_REST_Controller {

    const ITEMS_DEFAULT_LIMIT = 100;
    const ITEMS_MAX_LIMIT = 500;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Local_codelists_model');
        $this->load->library('Editor_acl');
        $this->is_authenticated_or_die();
        $this->api_user = $this->api_user();
    }

    function _auth_override_check()
    {
        if ($this->session->userdata('user_id')) {
            return true;
        }
        parent::_auth_override_check();
    }

    /**
     * GET /api/local_codelists/lists/{sid}
     */
    function lists_get($sid = null)
    {
        try {
            $sid = $this->get_sid($sid);
            $this->editor_acl->user_has_project_access($sid, 'view', $this->api_user);

            $lists = $this->Local_codelists_model->get_lists_for_project($sid);
            $this->set_response(array(
                'status' => 'success',
                'lists' => $lists,
            ), REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status' => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * GET /api/local_codelists/list/{sid}/{list_id}
     */
    function list_get($sid = null, $list_id = null)
    {
        try {
            $sid = $this->get_sid($sid);
            $this->editor_acl->user_has_project_access($sid, 'view', $this->api_user);
            $list_id = $this->_require_positive_int($list_id, 'list_id');

            $list = $this->Local_codelists_model->get_list($sid, $list_id);
            if (!$list) {
                throw new Exception('Local codelist not found');
            }
            $this->set_response(array(
                'status' => 'success',
                'list' => $list,
            ), REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status' => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * POST /api/local_codelists/list/{sid}
     * Body: { "field_id": number, "name"?: string, "description"?: string }
     */
    function list_post($sid = null)
    {
        try {
            $sid = $this->get_sid($sid);
            $this->editor_acl->user_has_project_access($sid, 'edit', $this->api_user);

            $body = (array) $this->raw_json_input();
            if (!isset($body['field_id'])) {
                throw new Exception('field_id is required');
            }
            $field_id = (int) $body['field_id'];
            $opts = array();
            if (isset($body['name'])) {
                $opts['name'] = $body['name'];
            }
            if (isset($body['description'])) {
                $opts['description'] = $body['description'];
            }
            $user_id = $this->get_api_user_id();
            $id = $this->Local_codelists_model->insert_list($sid, $field_id, $opts, $user_id ? (int) $user_id : null);
            $list = $this->Local_codelists_model->get_list($sid, $id);

            $this->set_response(array(
                'status' => 'success',
                'id' => $id,
                'list' => $list,
                'message' => 'Local codelist created',
            ), REST_Controller::HTTP_CREATED);
        } catch (Exception $e) {
            $this->set_response(array(
                'status' => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * POST /api/local_codelists/list_update/{sid}/{list_id}
     */
    function list_update_post($sid = null, $list_id = null)
    {
        try {
            $sid = $this->get_sid($sid);
            $this->editor_acl->user_has_project_access($sid, 'edit', $this->api_user);
            $list_id = $this->_require_positive_int($list_id, 'list_id');

            $body = (array) $this->raw_json_input();
            $opts = array();
            if (array_key_exists('name', $body)) {
                $opts['name'] = $body['name'];
            }
            if (array_key_exists('description', $body)) {
                $opts['description'] = $body['description'];
            }
            $user_id = $this->get_api_user_id();
            $ok = $this->Local_codelists_model->update_list($sid, $list_id, $opts, $user_id ? (int) $user_id : null);
            if (!$ok) {
                throw new Exception('Local codelist not found');
            }
            $list = $this->Local_codelists_model->get_list($sid, $list_id);
            $this->set_response(array(
                'status' => 'success',
                'list' => $list,
                'message' => 'Local codelist updated',
            ), REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status' => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * DELETE /api/local_codelists/list_delete/{sid}/{list_id}
     */
    function list_delete_delete($sid = null, $list_id = null)
    {
        $this->list_delete_post($sid, $list_id);
    }

    /**
     * POST /api/local_codelists/list_delete/{sid}/{list_id}
     */
    function list_delete_post($sid = null, $list_id = null)
    {
        try {
            $this->_delete_list_or_fail($sid, $list_id);
            $this->set_response(array(
                'status' => 'success',
                'message' => 'Local codelist deleted',
            ), REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status' => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * GET /api/local_codelists/items/{sid}/{list_id}
     */
    function items_get($sid = null, $list_id = null)
    {
        try {
            $sid = $this->get_sid($sid);
            $this->editor_acl->user_has_project_access($sid, 'view', $this->api_user);
            $list_id = $this->_require_positive_int($list_id, 'list_id');

            $list = $this->Local_codelists_model->get_list($sid, $list_id);
            if (!$list) {
                throw new Exception('Local codelist not found');
            }

            $offset = (int) $this->input->get('offset');
            if ($offset < 0) {
                $offset = 0;
            }
            $limit_in = $this->input->get('limit');
            if ($limit_in === null || $limit_in === '') {
                $limit = self::ITEMS_DEFAULT_LIMIT;
            } else {
                $limit = (int) $limit_in;
                if ($limit <= 0) {
                    $limit = self::ITEMS_DEFAULT_LIMIT;
                }
                if ($limit > self::ITEMS_MAX_LIMIT) {
                    $limit = self::ITEMS_MAX_LIMIT;
                }
            }

            $sort = $this->input->get('sort');
            $sort = is_string($sort) ? trim($sort) : '';
            $order_in = $this->input->get('order');
            $order_in = is_string($order_in) ? strtolower(trim($order_in)) : '';
            $order_dir = $order_in === 'desc' ? 'DESC' : 'ASC';
            $order_by = null;
            if ($sort !== '' && in_array($sort, array('code', 'label', 'sort_order'), true)) {
                $order_by = $sort;
            }

            $search_raw = $this->input->get('search');
            $search = is_string($search_raw) ? trim($search_raw) : '';
            if (strlen($search) > 200) {
                $search = substr($search, 0, 200);
            }

            $total = $this->Local_codelists_model->count_items($list_id, $search !== '' ? $search : null);
            $items = $this->Local_codelists_model->get_items($list_id, $offset, $limit, $order_by, $order_dir, $search !== '' ? $search : null);

            $this->set_response(array(
                'status' => 'success',
                'list' => $list,
                'items' => $items,
                'total' => $total,
                'offset' => $offset,
                'limit' => $limit,
                'sort' => $order_by,
                'order' => strtolower($order_dir),
                'search' => $search,
            ), REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status' => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * POST /api/local_codelists/items/{sid}/{list_id}
     * Body: { "code": string, "label"?: string, "sort_order"?: number }
     */
    function items_post($sid = null, $list_id = null)
    {
        try {
            $sid = $this->get_sid($sid);
            $this->editor_acl->user_has_project_access($sid, 'edit', $this->api_user);
            $list_id = $this->_require_positive_int($list_id, 'list_id');

            $body = (array) $this->raw_json_input();
            $user_id = $this->get_api_user_id();
            $item_id = $this->Local_codelists_model->insert_item(
                $sid,
                $list_id,
                $body,
                $user_id ? (int) $user_id : null
            );
            $item = $this->Local_codelists_model->get_item_for_sid($sid, $item_id);

            $this->set_response(array(
                'status' => 'success',
                'id' => $item_id,
                'item' => $item,
                'message' => 'Item created',
            ), REST_Controller::HTTP_CREATED);
        } catch (Exception $e) {
            $this->set_response(array(
                'status' => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * POST /api/local_codelists/item_update/{sid}/{item_id}
     */
    function item_update_post($sid = null, $item_id = null)
    {
        try {
            $sid = $this->get_sid($sid);
            $this->editor_acl->user_has_project_access($sid, 'edit', $this->api_user);
            $item_id = $this->_require_positive_int($item_id, 'item_id');

            $body = (array) $this->raw_json_input();
            $user_id = $this->get_api_user_id();
            $ok = $this->Local_codelists_model->update_item($sid, $item_id, $body, $user_id ? (int) $user_id : null);
            if (!$ok) {
                throw new Exception('Item not found');
            }
            $item = $this->Local_codelists_model->get_item_for_sid($sid, $item_id);
            $this->set_response(array(
                'status' => 'success',
                'item' => $item,
                'message' => 'Item updated',
            ), REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status' => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * DELETE /api/local_codelists/item_delete/{sid}/{item_id}
     */
    function item_delete_delete($sid = null, $item_id = null)
    {
        $this->item_delete_post($sid, $item_id);
    }

    /**
     * POST /api/local_codelists/item_delete/{sid}/{item_id}
     */
    function item_delete_post($sid = null, $item_id = null)
    {
        try {
            $this->_delete_item_or_fail($sid, $item_id);
            $this->set_response(array(
                'status' => 'success',
                'message' => 'Item deleted',
            ), REST_Controller::HTTP_OK);
        } catch (Exception $e) {
            $this->set_response(array(
                'status' => 'failed',
                'message' => $e->getMessage(),
            ), REST_Controller::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @param mixed $sid
     * @param mixed $list_id
     * @return void
     * @throws Exception
     */
    private function _delete_list_or_fail($sid, $list_id)
    {
        $sid = $this->get_sid($sid);
        $this->editor_acl->user_has_project_access($sid, 'edit', $this->api_user);
        $list_id = $this->_require_positive_int($list_id, 'list_id');
        if (!$this->Local_codelists_model->delete_list($sid, $list_id)) {
            throw new Exception('Local codelist not found');
        }
    }

    /**
     * @param mixed $sid
     * @param mixed $item_id
     * @return void
     * @throws Exception
     */
    private function _delete_item_or_fail($sid, $item_id)
    {
        $sid = $this->get_sid($sid);
        $this->editor_acl->user_has_project_access($sid, 'edit', $this->api_user);
        $item_id = $this->_require_positive_int($item_id, 'item_id');
        if (!$this->Local_codelists_model->delete_item($sid, $item_id)) {
            throw new Exception('Item not found');
        }
    }

    /**
     * @param mixed $value
     * @param string $label
     * @return int
     * @throws Exception
     */
    private function _require_positive_int($value, $label)
    {
        if ($value === null || $value === '') {
            throw new Exception($label . ' is required');
        }
        $n = (int) $value;
        if ($n <= 0) {
            throw new Exception('Invalid ' . $label);
        }

        return $n;
    }
}
