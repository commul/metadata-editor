<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Schema_import extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();

        if (!$this->input->is_cli_request()) {
            show_error('This controller can only be accessed via the command line');
        }

        $this->load->library('Schema_package_importer');
    }

    public function index()
    {
        echo "Schema Package Importer\n";
        echo "=======================\n\n";
        echo "Usage:\n";
        echo "  php index.php cli/schema_import import <package-name>\n\n";
        echo "Example:\n";
        echo "  php index.php cli/schema_import import lc-meta-minimal\n";
    }

    public function import($package_name = null)
    {
        if (!$package_name) {
            echo "Error: package name is required.\n";
            echo "Usage: php index.php cli/schema_import import <package-name>\n";
            exit(1);
        }

        try {
            $result = $this->schema_package_importer->import($package_name);

            echo "Schema package imported successfully.\n";
            echo "Package: " . $package_name . "\n";
            echo "Action: " . $result['action'] . "\n";
            echo "Schema UID: " . $result['schema']['uid'] . "\n";
            echo "Schema file: " . $result['schema_file'] . "\n";
            echo "Template UID: " . $result['template_uid'] . "\n";
        } catch (Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}
