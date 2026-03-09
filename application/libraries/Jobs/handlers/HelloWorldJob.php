<?php

require_once APPPATH . 'libraries/Jobs/JobHandlerInterface.php';

/**
 * Hello World Job Handler
 * 
 * Example job handler for testing and demonstration
 */
class HelloWorldJob implements JobHandlerInterface
{
    /**
     * Get the job type this handler processes
     * 
     * @return string
     */
    public function getJobType()
    {
        return 'hello_world';
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
        // Hello world job doesn't require any specific payload
        // But we can validate optional parameters if needed
        if (isset($payload['message']) && !is_string($payload['message'])) {
            throw new Exception("Invalid message: must be a string");
        }
        
        return true;
    }
    
    /**
     * Generate a unique hash for the job based on payload
     * This hash is used for idempotency - jobs with the same hash are considered duplicates
     * 
     * @param array $payload Job payload data
     * @return string Hash string (typically SHA256 hex)
     */
    public function generateJobHash($payload)
    {
        $hash_data = array(
            'job_type' => $this->getJobType(),
            'payload' => $payload
        );
        ksort($hash_data);
        return hash('sha256', json_encode($hash_data));
    }
    
    /**
     * Process the hello world job
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
        
        $message = isset($payload['message']) ? $payload['message'] : 'Hello, World!';
        
        // Simulate some work
        sleep(1);
        
        return array(
            'message' => $message,
            'processed_at' => date('Y-m-d H:i:s'),
            'job_id' => $job['id']
        );
    }
}

