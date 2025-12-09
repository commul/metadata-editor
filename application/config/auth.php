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
    'OidcAuthSpa'   => 'application/libraries/Auth/OidcAuthSpa.php'        
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
|||--------------------------------------------------------------------------
||| OIDC Authentication Config options
|||--------------------------------------------------------------------------
|||
||| Configurations for OIDC (OpenID Connect) authentication
|||
||| When OidcAuth driver is used, these settings control the authentication
||| behavior and UI display options.
|||
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
    
    // Client type and credentials
    // client_type options:
    //   - 'confidential': Server-side PHP application (requires client_secret)
    //   - 'public': SPA/frontend application (uses PKCE, no client_secret)
    'client_type' => 'public',  // 'confidential' | 'public'
    
    // Use PKCE (Proof Key for Code Exchange)
    // - Required for public clients (client_type='public')
    // - Optional for confidential clients (adds extra security)
    'use_pkce' => true,  // true | false
    
    'client_id' => '',
    // client_secret:
    // - Required for confidential clients (client_type='confidential')
    // - Not used for public clients (client_type='public')
    'client_secret' => '',
    'redirect_uri' => '', // Will default to site_url('auth/oidc_callback') if empty
    
    // OIDC/OAuth settings
    // response_type options:
    //   - 'code': Authorization code flow (standard, recommended)
    //   - 'id_token': Implicit flow (deprecated, not recommended)
    'response_type' => 'code',  // Authorization code flow
    // response_mode options:
    //   - 'query': Code in URL query string (default)
    //   - 'form_post': Code in POST body (more secure)
    //   - 'fragment': Code in URL fragment (less secure, not recommended)
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
