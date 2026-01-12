<?php

/**
 * Job Handler Interface
 * 
 * All job handlers must implement this interface
 */
interface JobHandlerInterface
{
    /**
     * Get the job type this handler processes
     * 
     * @return string Job type (e.g., 'pdf_generation')
     */
    public function getJobType();
    
    /**
     * Validate the job payload
     * 
     * @param array $payload Job payload data
     * @throws Exception If validation fails
     * @return bool True if valid
     */
    public function validatePayload($payload);
    
    /**
     * Generate a unique hash for the job based on payload
     * This hash is used for idempotency - jobs with the same hash are considered duplicates
     * 
     * @param array $payload Job payload data
     * @return string Hash string (typically SHA256 hex)
     */
    public function generateJobHash($payload);
    
    /**
     * Process the job
     * 
     * @param array $job Full job data from database
     * @param array $payload Decoded payload data
     * @return array Result data (will be JSON encoded and stored in job.result)
     * @throws Exception If processing fails
     */
    public function process($job, $payload);
}
