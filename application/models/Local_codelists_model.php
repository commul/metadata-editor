<?php

/**
 * Local (project-scoped) codelists: header row in local_codelists, codes in local_codelist_items.
 * field_id identifies the owning structural row (e.g. indicator_dsd.id); uniqueness is (sid, field_id).
 */
class Local_codelists_model extends CI_Model {

    private $table_lists = 'local_codelists';
    private $table_items = 'local_codelist_items';

    /** Updatable list columns (not sid / field_id / id / timestamps) */
    private $list_fields = array('name', 'description', 'created_by', 'changed_by');

    private $item_fields = array('code', 'label', 'sort_order', 'created_by', 'changed_by');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Editor_model');
    }

    /**
     * All local codelist headers for a project.
     *
     * @param int $sid
     * @return array
     */
    public function get_lists_for_project($sid)
    {
        $this->db->where('sid', (int) $sid);
        $this->db->order_by('id', 'ASC');

        return $this->db->get($this->table_lists)->result_array();
    }

    /**
     * @param int $sid
     * @param int $list_id
     * @return array|false
     */
    public function get_list($sid, $list_id)
    {
        $this->db->where('sid', (int) $sid);
        $this->db->where('id', (int) $list_id);
        $row = $this->db->get($this->table_lists)->row_array();

        return $row ? $row : false;
    }

    /**
     * @param int $sid
     * @param int $field_id e.g. indicator_dsd.id
     * @return array|false
     */
    public function get_list_by_field($sid, $field_id)
    {
        $this->db->where('sid', (int) $sid);
        $this->db->where('field_id', (int) $field_id);
        $row = $this->db->get($this->table_lists)->row_array();

        return $row ? $row : false;
    }

    /**
     * @param int $sid
     * @param int $field_id
     * @param array $options name, description
     * @param int|null $user_id
     * @return int new list id
     * @throws Exception if a list already exists for (sid, field_id)
     */
    public function insert_list($sid, $field_id, $options = array(), $user_id = null)
    {
        $this->Editor_model->check_project_editable($sid);
        $field_id = (int) $field_id;
        if ($field_id <= 0) {
            throw new Exception('field_id is required');
        }
        if ($this->get_list_by_field($sid, $field_id)) {
            throw new Exception('A local codelist already exists for this field in this project.');
        }
        $row = array(
            'sid' => (int) $sid,
            'field_id' => $field_id,
        );
        foreach ($this->list_fields as $f) {
            if (array_key_exists($f, $options)) {
                $row[$f] = $options[$f];
            }
        }
        if ($user_id !== null) {
            $row['created_by'] = (int) $user_id;
            $row['changed_by'] = (int) $user_id;
        }
        $this->db->insert($this->table_lists, $row);

        return (int) $this->db->insert_id();
    }

    /**
     * @param int $sid
     * @param int $list_id
     * @param array $options
     * @param int|null $user_id
     * @return bool false if list missing
     */
    public function update_list($sid, $list_id, $options = array(), $user_id = null)
    {
        $this->Editor_model->check_project_editable($sid);
        if (!$this->get_list($sid, $list_id)) {
            return false;
        }
        $update = array();
        foreach ($this->list_fields as $f) {
            if (array_key_exists($f, $options)) {
                $update[$f] = $options[$f];
            }
        }
        if ($user_id !== null) {
            $update['changed_by'] = (int) $user_id;
        }
        if (empty($update)) {
            return true;
        }
        $this->db->where('sid', (int) $sid);
        $this->db->where('id', (int) $list_id);
        $this->db->update($this->table_lists, $update);

        return true;
    }

    /**
     * Deletes list and items (FK CASCADE on items).
     *
     * @param int $sid
     * @param int $list_id
     * @return bool
     */
    public function delete_list($sid, $list_id)
    {
        $this->Editor_model->check_project_editable($sid);
        if (!$this->get_list($sid, $list_id)) {
            return false;
        }
        $this->db->where('sid', (int) $sid);
        $this->db->where('id', (int) $list_id);
        $this->db->delete($this->table_lists);

        return $this->db->affected_rows() > 0;
    }

    /**
     * Delete the local list for a field if present (e.g. when removing an indicator_dsd row).
     *
     * @param int $sid
     * @param int $field_id
     * @return bool true if a list existed and was deleted
     */
    public function delete_list_by_field($sid, $field_id)
    {
        $list = $this->get_list_by_field($sid, $field_id);
        if (!$list) {
            return false;
        }

        return $this->delete_list($sid, (int) $list['id']);
    }

    /**
     * @param int $sid
     * @param int $item_id
     * @return array|false
     */
    public function get_item_for_sid($sid, $item_id)
    {
        $this->db->select('i.*');
        $this->db->from($this->table_items . ' i');
        $this->db->join($this->table_lists . ' l', 'l.id = i.local_codelist_id');
        $this->db->where('l.sid', (int) $sid);
        $this->db->where('i.id', (int) $item_id);
        $row = $this->db->get()->row_array();

        return $row ? $row : false;
    }

    /**
     * @param int $local_codelist_id
     * @param string $code
     * @param int|null $exclude_item_id
     * @return bool
     */
    public function item_code_exists($local_codelist_id, $code, $exclude_item_id = null)
    {
        $this->db->where('local_codelist_id', (int) $local_codelist_id);
        $this->db->where('code', $code);
        if ($exclude_item_id !== null && (int) $exclude_item_id > 0) {
            $this->db->where('id !=', (int) $exclude_item_id);
        }

        return (int) $this->db->count_all_results($this->table_items) > 0;
    }

    /**
     * Optional filter: code OR label contains search string (LIKE %…%).
     *
     * @param string|null $search
     */
    protected function apply_local_items_search($search)
    {
        $s = trim((string) $search);
        if ($s === '') {
            return;
        }
        if (strlen($s) > 200) {
            $s = substr($s, 0, 200);
        }
        $this->db->group_start();
        $this->db->like('code', $s, 'both');
        $this->db->or_like('label', $s, 'both');
        $this->db->group_end();
    }

    /**
     * @param int $local_codelist_id
     * @param string|null $search optional substring filter on code/label
     * @return int
     */
    public function count_items($local_codelist_id, $search = null)
    {
        $this->db->where('local_codelist_id', (int) $local_codelist_id);
        $this->apply_local_items_search($search);

        return (int) $this->db->count_all_results($this->table_items);
    }

    /**
     * @param int $local_codelist_id
     * @param int $offset
     * @param int|null $limit null = all
     * @param string|null $order_by whitelist: code, label, sort_order; null = sort_order then id
     * @param string $order_dir ASC or DESC
     * @param string|null $search optional substring filter on code/label
     * @return array
     */
    public function get_items($local_codelist_id, $offset = 0, $limit = null, $order_by = null, $order_dir = 'ASC', $search = null)
    {
        $this->db->where('local_codelist_id', (int) $local_codelist_id);
        $this->apply_local_items_search($search);
        $allowed = array('code' => 'code', 'label' => 'label', 'sort_order' => 'sort_order');
        $dir = strtoupper((string) $order_dir) === 'DESC' ? 'DESC' : 'ASC';
        if ($order_by !== null && $order_by !== '' && isset($allowed[$order_by])) {
            $this->db->order_by($allowed[$order_by], $dir);
            $this->db->order_by('id', 'ASC');
        } else {
            $this->db->order_by('sort_order', 'ASC');
            $this->db->order_by('id', 'ASC');
        }
        if ($limit !== null && (int) $limit > 0) {
            $this->db->limit((int) $limit, (int) $offset);
        }

        return $this->db->get($this->table_items)->result_array();
    }

    /**
     * @param int $sid
     * @param int $local_codelist_id
     * @param array $options code, label, sort_order
     * @param int|null $user_id
     * @return int new item id
     * @throws Exception if list not in project
     */
    public function insert_item($sid, $local_codelist_id, $options, $user_id = null)
    {
        $this->Editor_model->check_project_editable($sid);
        if (!$this->get_list($sid, $local_codelist_id)) {
            throw new Exception('Local codelist not found for this project.');
        }
        if (!is_array($options) || !isset($options['code']) || trim((string) $options['code']) === '') {
            throw new Exception('Item code is required');
        }
        $code = trim((string) $options['code']);
        if (strlen($code) > 150) {
            throw new Exception('Item code exceeds maximum length of 150 characters');
        }
        if ($this->item_code_exists($local_codelist_id, $code)) {
            throw new Exception('Duplicate code in this local codelist');
        }
        $label = array_key_exists('label', $options) ? (string) $options['label'] : '';
        $sort_order = 0;
        if (array_key_exists('sort_order', $options) && $options['sort_order'] !== '' && $options['sort_order'] !== null) {
            $sort_order = (int) $options['sort_order'];
        }
        $row = array(
            'local_codelist_id' => (int) $local_codelist_id,
            'code' => $code,
            'label' => $label,
            'sort_order' => $sort_order,
        );
        if ($user_id !== null) {
            $row['created_by'] = (int) $user_id;
            $row['changed_by'] = (int) $user_id;
        }
        $this->db->insert($this->table_items, $row);

        return (int) $this->db->insert_id();
    }

    /**
     * @param int $sid
     * @param int $item_id
     * @param array $options
     * @param int|null $user_id
     * @return bool false if item not found in project
     */
    public function update_item($sid, $item_id, $options, $user_id = null)
    {
        $this->Editor_model->check_project_editable($sid);
        $existing = $this->get_item_for_sid($sid, $item_id);
        if (!$existing) {
            return false;
        }
        $list_id = (int) $existing['local_codelist_id'];
        $update = array();
        if (array_key_exists('code', $options)) {
            $code = trim((string) $options['code']);
            if ($code === '') {
                throw new Exception('Item code cannot be empty');
            }
            if (strlen($code) > 150) {
                throw new Exception('Item code exceeds maximum length of 150 characters');
            }
            if ($this->item_code_exists($list_id, $code, $item_id)) {
                throw new Exception('Duplicate code in this local codelist');
            }
            $update['code'] = $code;
        }
        if (array_key_exists('label', $options)) {
            $update['label'] = (string) $options['label'];
        }
        if (array_key_exists('sort_order', $options) && $options['sort_order'] !== '' && $options['sort_order'] !== null) {
            $update['sort_order'] = (int) $options['sort_order'];
        }
        if ($user_id !== null) {
            $update['changed_by'] = (int) $user_id;
        }
        if (empty($update)) {
            return true;
        }
        $this->db->where('id', (int) $item_id);
        $this->db->update($this->table_items, $update);

        return true;
    }

    /**
     * @param int $sid
     * @param int $item_id
     * @return bool
     */
    public function delete_item($sid, $item_id)
    {
        $this->Editor_model->check_project_editable($sid);
        if (!$this->get_item_for_sid($sid, $item_id)) {
            return false;
        }
        $this->db->where('id', (int) $item_id);
        $this->db->delete($this->table_items);

        return $this->db->affected_rows() > 0;
    }

    /**
     * Replace all items in a list (delete then insert). Used when rebuilding from DuckDB distincts.
     *
     * @param int $sid
     * @param int $local_codelist_id
     * @param array $pairs list of [ 'code' => string, 'label' => string ]
     * @param int|null $user_id
     * @return int number of items inserted
     */
    public function replace_all_items($sid, $local_codelist_id, array $pairs, $user_id = null)
    {
        $this->Editor_model->check_project_editable($sid);
        if (!$this->get_list($sid, $local_codelist_id)) {
            throw new Exception('Local codelist not found for this project.');
        }
        $local_codelist_id = (int) $local_codelist_id;
        $this->db->trans_start();
        $this->db->where('local_codelist_id', $local_codelist_id);
        $this->db->delete($this->table_items);
        $sort = 0;
        $inserted = 0;
        foreach ($pairs as $p) {
            if (!is_array($p)) {
                continue;
            }
            $code = isset($p['code']) ? trim((string) $p['code']) : '';
            if ($code === '' || strlen($code) > 150) {
                continue;
            }
            $label = array_key_exists('label', $p) ? (string) $p['label'] : '';
            $row = array(
                'local_codelist_id' => $local_codelist_id,
                'code' => $code,
                'label' => $label,
                'sort_order' => $sort,
            );
            if ($user_id !== null) {
                $row['created_by'] = (int) $user_id;
                $row['changed_by'] = (int) $user_id;
            }
            $this->db->insert($this->table_items, $row);
            $sort++;
            $inserted++;
        }
        $this->db->trans_complete();
        if ($this->db->trans_status() === false) {
            throw new Exception('Failed to replace local codelist items.');
        }

        return $inserted;
    }
}
