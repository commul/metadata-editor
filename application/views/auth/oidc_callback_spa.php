    <div class="container d-flex justify-content-center align-items-center" style="min-height: 100vh;">
        <div class="card shadow-sm" style="max-width: 400px; width: 100%;">
            <div class="card-body text-center p-5">
                <div id="loading">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="sr-only">Loading...</span>
                    </div>
                    <p class="mb-0">Completing login...</p>
                </div>
                <div id="error" class="alert alert-danger" style="display: none;">
                    <h4 class="alert-heading">Authentication Failed</h4>
                    <p id="error-message" class="mb-3"></p>
                    <a href="<?php echo isset($login_url) ? $login_url : site_url('auth/login'); ?>" class="btn btn-outline-danger">Return to login</a>
                </div>
                <div id="success" class="alert alert-success" style="display: none;">
                    <h4 class="alert-heading">Login Successful</h4>
                    <p class="mb-0">Redirecting...</p>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
    $(function() {
        var code = '<?php echo isset($code) ? addslashes($code) : ''; ?>';
        var state = '<?php echo isset($state) ? addslashes($state) : ''; ?>';
        var error = '<?php echo isset($error) ? addslashes($error) : ''; ?>';
        var errorDescription = '<?php echo isset($error_description) ? addslashes($error_description) : ''; ?>';
        var callbackApiUrl = '<?php echo isset($callback_api_url) ? $callback_api_url : site_url('auth/oidc_callback_api'); ?>';
        var homeUrl = '<?php echo isset($home_url) ? $home_url : site_url(); ?>';
        var loginUrl = '<?php echo isset($login_url) ? $login_url : site_url('auth/login'); ?>';
        
        // Check for popup mode from URL parameter or sessionStorage
        var urlParams = new URLSearchParams(window.location.search);
        var popupMode = urlParams.get('mode') === 'popup';
        if (!popupMode) {
          // Check sessionStorage (set when login was initiated)
          popupMode = sessionStorage.getItem('oidc_popup_mode') === 'true';
        }
        
        // Update URLs based on popup mode
        if (popupMode) {
          homeUrl = '<?php echo site_url('auth/login_success?mode=popup'); ?>';
          loginUrl = '<?php echo site_url('auth/login?mode=popup'); ?>';
        }

        // Check for OAuth error
        if (error) {
            $('#loading').hide();
            $('#error').show();
            $('#error-message').text(error + (errorDescription ? ': ' + errorDescription : ''));
            // Redirect to login page after delay (preserve popup mode)
            setTimeout(function() {
                window.location.replace(loginUrl);
            }, 3000);
            return;
        }

        // Check if we have code and state
        if (!code || !state) {
            $('#loading').hide();
            $('#error').show();
            $('#error-message').text('Missing authorization code or state parameter.');
            return;
        }

        // Retrieve stored PKCE values from sessionStorage
        var codeVerifier = sessionStorage.getItem('oidc_code_verifier');
        var storedState = sessionStorage.getItem('oidc_state');
        var nonce = sessionStorage.getItem('oidc_nonce');

        // Validate state
        if (state !== storedState) {
            $('#loading').hide();
            $('#error').show();
            $('#error-message').text('Invalid state parameter. Please try again.');
            // Clear stored values
            sessionStorage.removeItem('oidc_code_verifier');
            sessionStorage.removeItem('oidc_state');
            sessionStorage.removeItem('oidc_nonce');
            return;
        }

        if (!codeVerifier || !nonce) {
            $('#loading').hide();
            $('#error').show();
            $('#error-message').text('Session expired. Please try logging in again.');
            return;
        }

        // Exchange code for tokens
        $.ajax({
            url: callbackApiUrl,
            method: 'POST',
            dataType: 'json',
            xhrFields: {
                withCredentials: true  // Include cookies (session) in request
            },
            data: {
                code: code,
                state: state,
                code_verifier: codeVerifier,
                nonce: nonce
            },
            success: function(data) {
                if (data.id_token && data.user) {
                    // Store tokens
                    localStorage.setItem('id_token', data.id_token);
                    if (data.access_token) {
                        localStorage.setItem('access_token', data.access_token);
                    }
                    
                    // Store user info
                    if (data.user) {
                        localStorage.setItem('user_info', JSON.stringify(data.user));
                    }

                    // Clear PKCE values and popup mode
                    sessionStorage.removeItem('oidc_code_verifier');
                    sessionStorage.removeItem('oidc_state');
                    sessionStorage.removeItem('oidc_nonce');
                    sessionStorage.removeItem('oidc_popup_mode');

                    // Show success message
                    $('#loading').hide();
                    $('#success').show();

                    // Redirect based on popup mode
                    // In popup mode, redirect to login_success page which will close the popup
                    // Otherwise, redirect to home page
                    setTimeout(function() {
                        window.location.replace(homeUrl);
                    }, 1000);
                } else {
                    $('#loading').hide();
                    $('#error').show();
                    $('#error-message').text(data.error || 'Authentication failed: Invalid response from server.');
                    // Redirect to login page after delay (preserve popup mode)
                    setTimeout(function() {
                        window.location.replace(loginUrl);
                    }, 3000);
                }
            },
            error: function(xhr) {
                var errorMsg = 'Failed to complete authentication';
                try {
                    var response = JSON.parse(xhr.responseText);
                    errorMsg = response.error || errorMsg;
                } catch(e) {
                    if (xhr.status === 0) {
                        errorMsg = 'Network error. Please check your connection.';
                    } else {
                        errorMsg = 'Server error: ' + xhr.status;
                    }
                }
                
                $('#loading').hide();
                $('#error').show();
                $('#error-message').text(errorMsg);

                // Clear stored values on error
                sessionStorage.removeItem('oidc_code_verifier');
                sessionStorage.removeItem('oidc_state');
                sessionStorage.removeItem('oidc_nonce');
                sessionStorage.removeItem('oidc_popup_mode');
                
                // Redirect to login page after delay (preserve popup mode)
                setTimeout(function() {
                    window.location.replace(loginUrl);
                }, 3000);
            }
        });
    });
    </script>
    
    <?php if (isset($popup_mode) && $popup_mode): ?>
    <script src="<?php echo base_url(); ?>vue-app/assets/session_channel.js"></script>
    <?php endif; ?>

