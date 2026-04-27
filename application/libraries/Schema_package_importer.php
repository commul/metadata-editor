<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Import a schema package from a local manifest stored inside the app tree.
 */
class Schema_package_importer
{
    protected $ci;

    public function __construct()
    {
        $this->ci =& get_instance();
        $this->ci->load->helper('file');
        $this->ci->load->model('Metadata_schemas_model');
        $this->ci->load->model('Editor_template_model');
        $this->ci->load->library('Schema_registry');
        $this->ci->load->library('Structured_schema_manifest_builder');
        $this->ci->load->library('Structured_template_manifest_builder');
    }

    public function import($package_name, $options = array())
    {
        $this->assert_required_tables();

        $manifest_path = $this->resolve_manifest_path($package_name);
        $manifest = $this->read_manifest($manifest_path);
        $schema_document = $this->ci->structured_schema_manifest_builder->build($manifest);

        $uid = $manifest['uid'];
        $filename = !empty($manifest['filename']) ? $manifest['filename'] : $uid . '-schema.json';
        $schema_dir = $this->normalize_path($this->ci->Metadata_schemas_model->get_custom_base_path() . '/' . $uid);

        $this->ensure_directory($schema_dir);
        $this->cleanup_schema_dir($schema_dir, array($filename));

        $schema_json = json_encode($schema_document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($schema_json === false) {
            throw new Exception('Failed to encode schema JSON for package: ' . $package_name);
        }

        $main_path = $schema_dir . '/' . $filename;
        if (file_put_contents($main_path, $schema_json) === false) {
            throw new Exception('Failed to write schema file: ' . $main_path);
        }

        $documents = array(
            $filename => json_decode($schema_json, true)
        );

        $this->ci->schema_registry->assert_valid_json_schema($documents[$filename], $filename);
        $this->ci->schema_registry->validate_schema_documents($documents, $filename, $schema_dir);

        $existing = $this->ci->Metadata_schemas_model->get_by_uid($uid);
        if ($existing && !empty($existing['is_core'])) {
            throw new Exception('Cannot overwrite core schema: ' . $uid);
        }

        $payload = array(
            'uid' => $uid,
            'title' => $manifest['title'],
            'agency' => isset($manifest['agency']) ? $manifest['agency'] : '',
            'description' => isset($manifest['description']) ? $manifest['description'] : '',
            'is_core' => 0,
            'status' => !empty($manifest['status']) ? $manifest['status'] : 'active',
            'storage_path' => $uid,
            'filename' => $filename,
            'schema_files' => array(),
            'metadata_options' => isset($manifest['metadata_options']) && is_array($manifest['metadata_options']) ? $manifest['metadata_options'] : array(),
            'alias' => isset($manifest['alias']) ? $manifest['alias'] : '',
            'updated' => date('U')
        );

        if ($existing) {
            $this->ci->Metadata_schemas_model->update($existing['id'], $payload);
            $schema = $this->ci->Metadata_schemas_model->get_by_id($existing['id']);
            $action = 'updated';
        } else {
            $payload['created'] = date('U');
            $schema_id = $this->ci->Metadata_schemas_model->insert($payload);
            $schema = $this->ci->Metadata_schemas_model->get_by_id($schema_id);
            $action = 'created';
        }

        $template_payload = $this->ci->structured_template_manifest_builder->build($manifest);
        $template_uid = $this->ci->Editor_template_model->upsert_generated_template($schema, $template_payload);
        $schema = $this->ci->Metadata_schemas_model->get_by_uid($uid);

        return array(
            'status' => 'success',
            'action' => $action,
            'manifest_path' => $manifest_path,
            'schema' => $schema,
            'template_uid' => $template_uid,
            'schema_file' => $main_path
        );
    }

    protected function assert_required_tables()
    {
        $required_tables = array(
            'metadata_schemas',
            'editor_templates',
            'editor_templates_default'
        );

        $missing = array();
        foreach ($required_tables as $table) {
            if (!$this->ci->db->table_exists($table)) {
                $missing[] = $table;
            }
        }

        if (!empty($missing)) {
            throw new Exception(
                'Missing required database tables: ' . implode(', ', $missing) .
                '. Run `php index.php cli/migrate latest` before importing schema packages.'
            );
        }
    }

    protected function resolve_manifest_path($package_name)
    {
        if (!$package_name) {
            throw new Exception('Package name is required.');
        }

        $root = $this->normalize_path(dirname(APPPATH) . '/schema-packages');
        $manifest_path = $root . '/' . trim($package_name, '/') . '/manifest.json';

        if (!is_file($manifest_path)) {
            throw new Exception('Schema package manifest not found: ' . $manifest_path);
        }

        return $manifest_path;
    }

    protected function read_manifest($manifest_path)
    {
        $json = file_get_contents($manifest_path);
        if ($json === false) {
            throw new Exception('Failed to read manifest: ' . $manifest_path);
        }

        $manifest = json_decode($json, true);
        if (!is_array($manifest)) {
            throw new Exception('Invalid manifest JSON: ' . $manifest_path);
        }

        return $manifest;
    }

    protected function ensure_directory($path)
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0777, true)) {
            throw new Exception('Failed to create schema directory: ' . $path);
        }
    }

    protected function cleanup_schema_dir($dir, $keep_files = array())
    {
        if (!is_dir($dir)) {
            return;
        }

        $keep_lookup = array_fill_keys($keep_files, true);
        $entries = scandir($dir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                continue;
            }

            if (isset($keep_lookup[$entry])) {
                continue;
            }

            @unlink($path);
        }
    }

    protected function normalize_path($path)
    {
        return str_replace('\\', '/', $path);
    }
}
