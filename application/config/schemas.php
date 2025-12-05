<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Schema Registry Configuration
|--------------------------------------------------------------------------
|
| This configuration file contains mappings for core data types (schemas)
| including icons and other metadata. This replaces hard-coded data types
| throughout the application.
|
*/

// Base path for schema icons (relative to base_url)
$config['schemas']['icon_path'] = 'images';

// Default icon for custom/user-defined schemas
$config['schemas']['default_icon'] = 'file-outline.svg';

// Icon file extension (assumes all icons are SVG)
$config['schemas']['icon_extension'] = '.svg';

// Core schema mappings
// Maps schema UID to icon filename (without extension)
$config['schemas']['icons'] = array(
    'survey'          => 'microdata.svg',
    'microdata'       => 'microdata.svg',
    'geospatial'      => 'geospatial.svg',
    'timeseries'      => 'indicator.svg',
    'indicator'       => 'indicator.svg',
    'timeseries-db'   => 'database.svg',
    'indicator-db'   => 'database.svg',
    'document'        => 'document.svg',
    'image'           => 'image.svg',
    'video'           => 'video.svg',
    'table'           => 'table.svg',
    'script'          => 'script.svg',
    'visualization'   => 'resource.svg', // Using resource.svg as fallback
    'admin_meta'      => 'admin_meta.svg',
    'custom'          => 'custom.svg',
    'resource'        => 'resource.svg',
);

// Reserved schema UIDs (cannot be used by custom schemas)
$config['schemas']['reserved_uids'] = array(
    'survey',
    'microdata',
    'geospatial',
    'timeseries',
    'timeseries-db',
    'document',
    'image',
    'video',
    'table',
    'script',
    'admin_meta',
    'custom',
);

