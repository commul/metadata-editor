<?php

defined('BASEPATH') OR exit('No direct script access allowed');


/*
||--------------------------------------------------------------------------
|| Authentication providers
||--------------------------------------------------------------------------
||
|| List of supported authentication providers
||
||
||
*/
$config['authentication_drivers'] = array(
    'DefaultAuth'   => 'application/libraries/Auth/DefaultAuth.php',
    'OidcAuth'      => 'application/libraries/Auth/OidcAuth.php',
    'OidcAuthSpa'   => 'application/libraries/Auth/OidcAuthSpa.php',
    'ZeroAuth'      => 'application/libraries/Auth/ZeroAuth.php',
);


/*
||--------------------------------------------------------------------------
|| Set active authentication
||--------------------------------------------------------------------------
||
|| Set authentication provider to use
||
*/
$config['authentication_driver'] = 'DefaultAuth';


/*
||--------------------------------------------------------------------------
|| OIDC Authentication Config options for OidcAuth and OidcAuthSpa drivers
||--------------------------------------------------------------------------
||
|| Configurations for OIDC (OpenID Connect) authentication
||
|| When OidcAuth driver is used, these settings control the authentication
|| behavior and UI display options.
||
*/

/*
||--------------------------------------------------------------------------
|| ZeroAuth – local/desktop mode (no password, one-click login)
||--------------------------------------------------------------------------
||
|| One-click login for local/desktop mode (no password, one-click login).
||
*/
$config['zero_auth'] = array(
    'admin_email' => 'editor@localhost',
    'admin_name'  => 'Editor Admin',
);

// Load OIDC configuration file - config/auth_oidc.php
$auth_oidc_file = APPPATH . 'config/auth_oidc.php';
if (file_exists($auth_oidc_file)) {
    require $auth_oidc_file;
}