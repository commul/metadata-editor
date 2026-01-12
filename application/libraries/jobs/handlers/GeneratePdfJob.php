<?php

require_once APPPATH . 'libraries/Jobs/JobHandlerInterface.php';

/**
 * PDF Generation Job Handler
 * 
 * Handles PDF generation jobs for projects
 */
class GeneratePdfJob implements JobHandlerInterface
{
    private $ci;
    
    public function __construct()
    {
        // Get CodeIgniter instance
        $this->ci =& get_instance();
        $this->ci->load->model('Editor_model');
        $this->ci->load->library('Editor_acl');
    }
    
    /**
     * Get the job type this handler processes
     * 
     * @return string
     */
    public function getJobType()
    {
        return 'generate_pdf';
    }
    
    /**
     * Validate the job payload
     * 
     * @param array $payload Job payload data
     * @throws Exception If validation fails
     * @return bool True if valid
     */
    public function validatePayload($payload)
    {
        if (empty($payload['project_id'])) {
            throw new Exception("Missing required parameter: project_id");
        }
        
        if (!is_numeric($payload['project_id'])) {
            throw new Exception("Invalid project_id: must be numeric");
        }
        
        // Validate project exists (optional, but good practice)
        $this->ci->load->model('Editor_model');
        $project = $this->ci->Editor_model->get_row($payload['project_id']);
        
        if (!$project) {
            throw new Exception("Project not found: {$payload['project_id']}");
        }
        
        // Validate options if provided
        if (isset($payload['options']) && !is_array($payload['options'])) {
            throw new Exception("Invalid options: must be an array");
        }
        
        return true;
    }
    
    /**
     * Generate a unique hash for the job based on payload
     * For PDF generation, we only need project_id for idempotency
     * 
     * @param array $payload Job payload data
     * @return string Hash string (SHA256 hex)
     */
    public function generateJobHash($payload)
    {
        // For PDF generation, hash is based on project_id only
        // This ensures that duplicate PDF generation requests for the same project
        // will be detected and prevented
        $hash_data = array(
            'job_type' => $this->getJobType(),
            'project_id' => isset($payload['project_id']) ? (int)$payload['project_id'] : null
        );
        
        // Sort array to ensure consistent hash generation
        ksort($hash_data);
        
        // Generate SHA256 hash
        return hash('sha256', json_encode($hash_data));
    }
    
    /**
     * Process the PDF generation job
     * 
     * @param array $job Full job data from database
     * @param array $payload Decoded payload data
     * @return array Result data
     * @throws Exception If processing fails
     */
    public function process($job, $payload)
    {
        // Validate payload first
        $this->validatePayload($payload);
        
        // Get user_id from job (set when job was enqueued)
        if (empty($job['user_id'])) {
            throw new Exception("User ID is required for PDF generation job");
        }
        
        $user_id = $job['user_id'];
        $project_id = $payload['project_id'];
                        
        // Get user object from user_id
        $user = $this->ci->ion_auth->get_user($user_id);
        
        if (!$user) {
            throw new Exception("User not found: {$user_id}");
        }
        
        // Validate user has access to the project (view permission required)
        $permission = 'view';
        $this->ci->editor_acl->user_has_project_access(
            $project_id,
            $permission,
            $user
        );
        
        $options = isset($payload['options']) ? $payload['options'] : array();
        
        // Generate PDF
        $pdf_path = $this->ci->Editor_model->generate_project_pdf(
            $project_id,
            $options
        );
        
        return array(
            'pdf_path' => $pdf_path,
            'project_id' => $project_id,
            'generated_at' => date('Y-m-d H:i:s')
        );
    }
}

