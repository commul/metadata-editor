<?php

defined('BASEPATH') OR exit('No direct script access allowed');


/*
|--------------------------------------------------------------------------
| Authentication providers
|--------------------------------------------------------------------------
|
| List of supported authentication providers
|
|
|
*/
$config['authentication_drivers'] = array(
    'DefaultAuth'   => 'application/libraries/Auth/DefaultAuth.php',
    'SsoAuth'       => 'application/libraries/Auth/SsoAuth.php',
    'AzureAuth'     => 'application/libraries/Auth/AzureAuth.php',
    'OidcAuth'      => 'application/libraries/Auth/OidcAuth.php'        
);


/*
|--------------------------------------------------------------------------
| Set active authentication
|--------------------------------------------------------------------------
|
| Set authentication provider to use
|
*/
$config['authentication_driver'] = 'DefaultAuth';



/*
|--------------------------------------------------------------------------
| AzureAuth Config options
|--------------------------------------------------------------------------
|
| Configurations for AzureAuth
|

* OAuth 2.0 Endpoints  
*
* Authorization endpoint (v1) 
* https://login.microsoftonline.com/{tenent-id}/oauth2/authorize  
* 
* Token endpoint (v1) 
* https://login.microsoftonline.com/{tenent-id}/oauth2/token 
*
* Logout endpoint (v1) 
* https://login.microsoftonline.com/{tenent-id}/oauth2/logout 
*
* Authorization endpoint (v2) 
* https://login.microsoftonline.com/{tenent-id}/oauth2/v2.0/authorize  
*
*
* Token endpoint (v2) 
* https://login.microsoftonline.com/{tenent-id}/oauth2/v2.0/token 
*
* Microsoft Graph API endpoint 
* https://graph.microsoft.com 

* Login request format
* https://login.microsoftonline.com/{tenant-id}/oauth2/authorize?client_id={client-id}&response_mode=form_post&response_type=code%20id_token&nonce=any-random-value


*/

$config['azure_auth']['client_id']='';
$config['azure_auth']['tenant_id']='';
$config['azure_auth']['authorize_endpoint']='https://login.microsoftonline.com/'.$config['azure_auth']['tenant_id'].'/oauth2/authorize';
$config['azure_auth']['token_endpoint'] = 'https://login.microsoftonline.com/'.$config['azure_auth']['tenant_id'].'/oauth2/token';
$config['azure_auth']['logout_endpoint'] = 'https://login.microsoftonline.com/'.$config['azure_auth']['tenant_id'].'/oauth2/logout';


/*
||--------------------------------------------------------------------------
|| OIDC Authentication Config options
||--------------------------------------------------------------------------
||
|| Configurations for OIDC (OpenID Connect) authentication
||
|| When OidcAuth driver is used, these settings control the authentication
|| behavior and UI display options.
||
*/
$config['oidc_auth'] = array(
    // Enable/disable OIDC authentication
    'enabled' => true,
    
    // Show default username/password login form when OIDC is enabled
    // Set to true to show both OIDC and default login options
    // Set to false to show only OIDC login (hide username/password form)
    'show_default_login' => true,

    //provider icon url
    'provider_icon' => '',
    
    // Display name for the OIDC provider (shown on login button)
    // Example: 'Login with Azure AD', 'Login with Google', etc.
    'provider_name' => 'OAuth0 Provider',
    
    // OIDC Provider Configuration
    // Issuer URL for auto-discovery (recommended)
    // Example: 'https://login.microsoftonline.com/{tenant-id}/v2.0'
    //          'https://accounts.google.com'
    //          'https://dev-<domain>.us.auth0.com'
    'issuer' => '',
    
    // Client credentials
    'client_id' => '',
    'client_secret' => '',
    'redirect_uri' => '', // Will default to site_url('auth/oidc_callback') if empty
    
    // OIDC/OAuth settings
    'response_type' => 'code',  // Authorization code flow
    'response_mode' => 'query', // 'query', 'form_post', or 'fragment'
    'scopes' => 'openid profile email',
    
    // User claim mappings (OIDC claims → local user fields)
    'claim_mappings' => array(
        'email' => 'email',
        'first_name' => 'given_name',
        'last_name' => 'family_name',
        'username' => 'preferred_username', // Falls back to email if not present
    ),
    
    // Behavior settings
    'auto_register' => true,  // Auto-create users from OIDC claims
    'logout_endpoint' => true,  // Use end_session_endpoint for logout
    
    // Security settings
    'validate_nonce' => true,  // Validate nonce in ID token
    'validate_state' => true,  // Validate state parameter (CSRF protection)
);