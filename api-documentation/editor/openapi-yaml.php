<?php

// Read the openapi.yaml file
$openapi_yaml = file_get_contents('openapi.yaml');

// Build concrete server URL by stripping everything from /api-documentation/ onwards
$scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'];
$app_path = preg_replace('/\/api-documentation\/.*$/', '/', $_SERVER['REQUEST_URI']);
$base_url = $scheme . '://' . $host . $app_path . 'index.php/api/';

$servers_block = "servers:\n  - url: " . $base_url . "\n    description: API Server\n";

// Replace the templated servers block (from 'servers:' up to the first tag/blank-line boundary)
$openapi_yaml = preg_replace('/^servers:.*?(?=^\S)/ms', $servers_block . "\n", $openapi_yaml);

// Return the YAML with appropriate content type
header('Content-Type: application/yaml');
echo $openapi_yaml;
