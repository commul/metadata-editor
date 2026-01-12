<?php

require_once APPPATH . 'libraries/jobs/JobHandlerInterface.php';

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
    }
    
    /**
     * Get the job type this handler processes
     * 
     * @return string
     */
    public function getJobType()
    {
        return 'pdf_generation';
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
        
        $options = isset($payload['options']) ? $payload['options'] : array();
        
        // Generate PDF
        $pdf_path = $this->ci->Editor_model->generate_project_pdf(
            $payload['project_id'],
            $options
        );
        
        return array(
            'pdf_path' => $pdf_path,
            'project_id' => $payload['project_id'],
            'generated_at' => date('Y-m-d H:i:s')
        );
    }
}

