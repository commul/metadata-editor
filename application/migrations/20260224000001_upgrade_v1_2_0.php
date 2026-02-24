<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once(APPPATH . 'core/MY_Migration.php');

class Migration_Upgrade_v1_2_0 extends MY_Migration {

    public function up()
    {
        log_message('info', 'Migration_Upgrade_v1_2_0::up() called');
        
        $sql_file = $this->get_sql_file_path('schema.mysql-update-1.2');
        
        if (!file_exists($sql_file)) {
            throw new Exception("SQL file not found: " . $sql_file);
        }
        
        log_message('info', 'Starting schemas migration...');
        $this->execute_sql_file($sql_file);
        log_message('info', 'Schemas migration completed successfully');
    }

    public function down()
    {
        throw new Exception("Rollback not supported - this is a one-way migration. Restore from database backup if needed.");
    }
}

