<?php

require_once 'application/libraries/Auth/AuthInterface.php';
require_once 'application/libraries/Auth/DefaultAuth.php';

class OidcAuth extends DefaultAuth implements AuthInterface {

    protected $oidc_config;
    protected $oidc_enabled;

    function __construct()
    {
        parent::__construct($skip_auth=TRUE);
        
        $this->oidc_config = $this->ci->config->item('oidc_auth');
        $this->oidc_enabled = !empty($this->oidc_config) && 
                             isset($this->oidc_config['enabled']) && 
                             $this->oidc_config['enabled'] === true;
        
        $this->ci->load->model("Ion_auth_model");
    }

    /**
     * 
     * Main login method - shows dual login options if OIDC enabled
     * 
     */
    function login()
    {
        if ($this->ci->input->get("isajax")) {
            return $this->login_ajax();
        }

        $this->ci->template->set_template('default');
        $this->data['title'] = t("login");

        // Check for popup mode
        $popup_mode = $this->ci->input->get('mode') === 'popup' || $this->ci->input->post('mode') === 'popup';

        if ($popup_mode) {
            $this->ci->template->set_template('blank');
        }

        // Check if OIDC is enabled and should show default login
        $show_oidc_button = $this->oidc_enabled;
        $show_default_login = !$this->oidc_enabled || 
                             (isset($this->oidc_config['show_default_login']) && 
                              $this->oidc_config['show_default_login'] === true);

        // If OIDC enabled and default login hidden, redirect to OIDC
        if ($this->oidc_enabled && !$show_default_login) {
            redirect('auth/oidc_login', 'refresh');
            return;
        }

        //validate form input
        $this->ci->form_validation->set_rules('email', t('email'), 'trim|required|valid_email|max_length[100]');
        $this->ci->form_validation->set_rules('password', t('password'), 'required|max_length[100]');
        
        if ($show_default_login) {
            $this->ci->form_validation->set_rules($this->ci->captcha_lib->get_question_field(), t('captcha'), 'trim|required|callback_validate_captcha');
        }

        if ($this->ci->form_validation->run() == true) {             
            $remember = false;

            //track login attempts?
            if ($this->ci->config->item("track_login_attempts")===TRUE)
            {
                //check if max login attempts limit reached
                $max_login_limit=$this->ci->ion_auth->is_max_login_attempts_exceeded($this->ci->input->post('email'));

                if ($max_login_limit)
                {
                    $this->ci->session->set_flashdata('error', t("max_login_attempted"));
                    sleep(3);
                    // Preserve popup mode
                    if ($popup_mode) {
                        redirect("auth/login?mode=popup", 'refresh');
                    } else {
                        redirect("auth/login", 'refresh');
                    }
                }
            }

            if ($this->ci->ion_auth->login($this->ci->input->post('email'), $this->ci->input->post('password'), $remember)) //if the login is successful
            {
                //log
                $this->ci->db_logger->write_log('login',$this->ci->input->post('email'));

                // If popup mode, redirect to success page instead of destination
                if ($popup_mode) {
                    redirect("auth/login_success?mode=popup", 'refresh');
                } else {
                    $destination=$this->ci->session->userdata("destination");

                    if ($destination!="")
                    {
                        $this->ci->session->unset_userdata('destination');
                        redirect($destination, 'refresh');
                    }
                    else
                    {
                        redirect($this->ci->config->item('base_url'), 'refresh');
                    }
                }
            }
            else
            { 	//if the login was un-successful
                //redirect them back to the login page
                $this->ci->session->set_flashdata('error', t("login_failed"));

                // Preserve popup mode in redirect
                if ($popup_mode) {
                    redirect("auth/login?mode=popup", 'refresh');
                } else {
                    redirect("auth/login", 'refresh');
                }
            }
        }
        else
        {
            $this->data['error'] = (validation_errors()) ? validation_errors() : $this->ci->session->flashdata('error');
            
            // Pass popup mode to view
            $this->data['popup_mode'] = $this->ci->input->get('mode') === 'popup';

            // OIDC configuration for view
            $this->data['show_oidc_button'] = $show_oidc_button;
            $this->data['show_default_login'] = $show_default_login;
            $this->data['oidc_provider_name'] = isset($this->oidc_config['provider_name']) 
                ? $this->oidc_config['provider_name'] 
                : 'OIDC Provider';
            $this->data['oidc_provider_icon'] = isset($this->oidc_config['provider_icon']) 
                ? $this->oidc_config['provider_icon'] 
                : '';

            if ($show_default_login) {
                $this->data['email']      = array('name'    => 'email',
                                                  'id'      => 'email',
                                                  'type'    => 'text',
                                                  'value'   => $this->ci->form_validation->set_value('email'),
                                                 );
                $this->data['password']   = array('name'    => 'password',
                                                  'id'      => 'password',
                                                  'type'    => 'password',
                                                 );
                $this->data['captcha_question']=$this->ci->captcha_lib->get_html();
            }
            
            $content=$this->ci->load->view('auth/login', $this->data,TRUE);

            $this->ci->template->write('content', $content,true);
            $this->ci->template->write('title', t('login'),true);
            $this->ci->template->render();
        }
    }

    /**
     * Default username/password login (accessible at auth/alternate)
     */
    function alternate()
    {
        $this->ci->template->set_template('blank');
        $this->data['title'] = t("login");

        if($this->ci->input->get('destination'))
        {
            $destination=$this->ci->input->get('destination');
            $this->ci->session->unset_userdata('destination');
        }
        else {
            $destination=$this->ci->session->userdata("destination");
        }

        //validate form input
        $this->ci->form_validation->set_rules('email', t('email'), 'trim|required|valid_email|max_length[100]');
        $this->ci->form_validation->set_rules('password', t('password'), 'required|max_length[100]');

        if ($this->ci->form_validation->run() == true) {
            $remember = false;
            if ($this->ci->config->item("track_login_attempts")===TRUE)
            {
                $max_login_limit=$this->ci->ion_auth->is_max_login_attempts_exceeded($this->ci->input->post('email'));

                if ($max_login_limit)
                {
                    $this->ci->session->set_flashdata('error', t("max_login_attempted"));
                    sleep(3);
                    redirect("auth/alternate", 'refresh');
                }
            }

            if ($this->ci->ion_auth->login($this->ci->input->post('email'), $this->ci->input->post('password'), $remember)) //if the login is successful
            {
                //log
                $this->ci->db_logger->write_log('login',$this->ci->input->post('email'));

                if ($destination!="")
                {
                    redirect($destination, 'refresh');
                }
                else
                {
                    redirect($this->ci->config->item('base_url'), 'refresh');
                }
            }
            else
            { 	//if the login was un-successful
                //redirect them back to the login page
                $this->ci->session->set_flashdata('error', t("login_failed"));

                //log
                $this->ci->db_logger->write_log('login-failed',$this->ci->input->post('email'));

                redirect("auth/alternate", 'refresh');
            }
        }
        else
        {  	//the user is not logging in so display the login page
            //set the flash data error message if there is one
            $this->data['error'] = (validation_errors()) ? validation_errors() : $this->ci->session->flashdata('error');

            $this->data['email']      = array('name'    => 'email',
                                              'id'      => 'email',
                                              'type'    => 'text',
                                              'value'   => $this->ci->form_validation->set_value('email'),
                                             );
            $this->data['password']   = array('name'    => 'password',
                                              'id'      => 'password',
                                              'type'    => 'password',
                                             );

            $content=$this->ci->load->view('auth/login', $this->data,TRUE);

            $this->ci->template->write('content', $content,true);
            $this->ci->template->write('title', t('login'),true);
            $this->ci->template->render();
        }
    }

    /**
     * Initiate OIDC login flow
     */
    function oidc_login()
    {
        if (!$this->oidc_enabled) {
            show_error('OIDC authentication is not enabled');
        }

        try {
            $this->ci->load->library('OidcClient');
            
            // Check for popup mode
            $popup_mode = $this->ci->input->get('mode') === 'popup' || $this->ci->input->post('mode') === 'popup';
            
            // Generate state and nonce
            $state = nada_random_hash(32);
            $nonce = nada_random_hash(32);
            
            // Store in session for validation
            $this->ci->session->set_userdata('oidc_state', $state);
            $this->ci->session->set_userdata('oidc_nonce', $nonce);
            
            // Store popup mode in session
            if ($popup_mode) {
                $this->ci->session->set_userdata('oidc_popup_mode', true);
            }
            
            // Store destination if provided
            $destination = $this->ci->input->get('destination') ?: $this->ci->session->userdata("destination");
            if ($destination) {
                $this->ci->session->set_userdata('oidc_destination', $destination);
            }
            
            // Get authorization URL
            $auth_url = $this->ci->oidcclient->getAuthorizationUrl($state, $nonce);
            
            // Redirect to OIDC provider
            redirect($auth_url, 'refresh');
            
        } catch (Exception $e) {
            log_message('error', 'OIDC login failed: ' . $e->getMessage());
            $this->ci->session->set_flashdata('error', 'OIDC authentication failed: ' . $e->getMessage());
            
            // Preserve popup mode in error redirect
            $popup_mode = $this->ci->session->userdata('oidc_popup_mode');
            if ($popup_mode) {
                redirect('auth/login?mode=popup', 'refresh');
            } else {
                redirect('auth/login', 'refresh');
            }
        }
    }

    /**
     * Handle OIDC callback
     */
    function oidc_callback()
    {
        if (!$this->oidc_enabled) {
            show_error('OIDC authentication is not enabled');
        }

        try {
            $this->ci->load->library('OidcClient');
            
            // Get stored nonce
            $nonce = $this->ci->session->userdata('oidc_nonce');
            if (empty($nonce)) {
                throw new Exception('Session expired. Please try logging in again.');
            }
            
            // Handle different response modes
            $code = null;
            $state = null;
            $id_token = null;
            
            if ($this->oidc_config['response_mode'] === 'form_post') {
                $code = $this->ci->input->post('code');
                $state = $this->ci->input->post('state');
                $id_token = $this->ci->input->post('id_token');
            } else {
                $code = $this->ci->input->get('code');
                $state = $this->ci->input->get('state');
            }
            
            // Check for errors
            $error = $this->ci->input->get('error') ?: $this->ci->input->post('error');
            if ($error) {
                $error_description = $this->ci->input->get('error_description') ?: $this->ci->input->post('error_description');
                throw new Exception('OIDC error: ' . $error . ($error_description ? ' - ' . $error_description : ''));
            }
            
            if (empty($code) && empty($id_token)) {
                throw new Exception('No authorization code or ID token received');
            }
            
            // Exchange code for tokens only if id_token is not already present
            // Hybrid flow (response_type=code id_token with form_post) provides id_token directly
            // Authorization code flow (response_type=code) requires token exchange
            if (empty($id_token) && !empty($code)) {
                $tokens = $this->ci->oidcclient->exchangeCodeForTokens($code, $state);
                $id_token = $tokens['id_token'];
            }
            
            // Validate ID token
            $claims = $this->ci->oidcclient->validateIdToken($id_token, $nonce);
            
            // Clear OIDC session data
            $this->ci->session->unset_userdata('oidc_nonce');
            
            // Store ID token for logout if needed
            if (isset($this->oidc_config['logout_endpoint']) && $this->oidc_config['logout_endpoint']) {
                $this->ci->session->set_userdata('oidc_id_token', $id_token);
            }
            
            // Map claims to user data
            $user_data = $this->mapClaimsToUserData($claims);
            
            if (empty($user_data['email'])) {
                throw new Exception('Email not found in OIDC claims');
            }
            
            // Check if user exists
            $user_info = $this->ci->ion_auth_model->get_user_by_email($user_data['email'])->row_array();
            
            if (is_array($user_info) && count($user_info) > 0) {
                // User exists - log them in
                $this->login_user_from_oidc($user_data['email']);
            } else {
                // User doesn't exist - register if auto_register is enabled
                if (isset($this->oidc_config['auto_register']) && $this->oidc_config['auto_register']) {
                    $this->register_user_from_oidc($user_data);
                    $this->login_user_from_oidc($user_data['email']);
                } else {
                    throw new Exception('User not found and auto-registration is disabled');
                }
            }
            
            // Check for popup mode
            $popup_mode = $this->ci->session->userdata('oidc_popup_mode');
            $this->ci->session->unset_userdata('oidc_popup_mode');
            
            // Get destination
            $destination = $this->ci->session->userdata('oidc_destination');
            $this->ci->session->unset_userdata('oidc_destination');
            
            // Handle redirect based on popup mode
            if ($popup_mode) {
                // In popup mode, redirect to login_success page
                redirect("auth/login_success?mode=popup", 'refresh');
            } else {
                // Normal mode - redirect to destination or home
                if ($destination && $destination != '') {
                    redirect($destination, 'refresh');
                } else {
                    redirect($this->ci->config->item('base_url'), 'refresh');
                }
            }
            
        } catch (Exception $e) {
            log_message('error', 'OIDC callback failed: ' . $e->getMessage());
            $this->ci->session->set_flashdata('error', 'Authentication failed: ' . $e->getMessage());
            
            // Preserve popup mode in error redirect
            $popup_mode = $this->ci->session->userdata('oidc_popup_mode');
            $this->ci->session->unset_userdata('oidc_popup_mode');
            
            if ($popup_mode) {
                redirect('auth/login?mode=popup', 'refresh');
            } else {
                redirect('auth/login', 'refresh');
            }
        }
    }

    /**
     * Enhanced logout with OIDC support
     */
    function logout()
    {
        $this->disable_page_cache();
        $this->data['title'] = t("logout");
        
        // Clear local session
        $logout = $this->ci->ion_auth->logout();
        
        // If OIDC logout endpoint is configured, redirect to provider logout
        if ($this->oidc_enabled && 
            isset($this->oidc_config['logout_endpoint']) && 
            $this->oidc_config['logout_endpoint']) {
            
            try {
                $this->ci->load->library('OidcClient');
                $logout_url = $this->ci->oidcclient->getEndSessionUrl(site_url(''));
                
                if ($logout_url) {
                    redirect($logout_url, 'refresh');
                    return;
                }
            } catch (Exception $e) {
                log_message('error', 'OIDC logout failed: ' . $e->getMessage());
                // Fall through to default logout
            }
        }
        
        // Default logout redirect
        redirect('', 'refresh');
    }

    /**
     * Map OIDC claims to user data
     */
    private function mapClaimsToUserData($claims)
    {
        $mappings = isset($this->oidc_config['claim_mappings']) 
            ? $this->oidc_config['claim_mappings'] 
            : array(
                'email' => 'email',
                'first_name' => 'given_name',
                'last_name' => 'family_name',
                'username' => 'preferred_username'
            );
        
        $user_data = array();
        
        // Map email (required)
        if (isset($mappings['email']) && isset($claims[$mappings['email']])) {
            $user_data['email'] = $claims[$mappings['email']];
        } elseif (isset($claims['email'])) {
            $user_data['email'] = $claims['email'];
        }
        
        // Map first name
        if (isset($mappings['first_name']) && isset($claims[$mappings['first_name']])) {
            $user_data['first_name'] = $claims[$mappings['first_name']];
        } elseif (isset($claims['given_name'])) {
            $user_data['first_name'] = $claims['given_name'];
        }
        
        // Map last name
        if (isset($mappings['last_name']) && isset($claims[$mappings['last_name']])) {
            $user_data['last_name'] = $claims[$mappings['last_name']];
        } elseif (isset($claims['family_name'])) {
            $user_data['last_name'] = $claims['family_name'];
        }
        
        // Map username (fallback to email)
        if (isset($mappings['username']) && isset($claims[$mappings['username']])) {
            $user_data['username'] = $claims[$mappings['username']];
        } elseif (isset($claims['preferred_username'])) {
            $user_data['username'] = $claims['preferred_username'];
        } else {
            $user_data['username'] = $user_data['email'];
        }
        
        return $user_data;
    }

    /**
     * Login user from OIDC
     */
    private function login_user_from_oidc($email)
    {
        if (empty($email)) {
            return FALSE;
        }

        $query = $this->ci->db->select('username,email, id, password')
            ->where("email", $email)
            ->where($this->ci->ion_auth->_extra_where)
            ->where('active', 1)
            ->get($this->ci->config->item('tables')['users']);
                  
        $result = $query->row();

        if ($query->num_rows() == 1){
            $this->update_last_login($result->id);
            $this->ci->session->set_userdata('email',  $result->email);
            $this->ci->session->set_userdata('username',  $result->username);
            $this->ci->session->set_userdata('user_id',  $result->id);
            
            // Log the login
            $this->ci->db_logger->write_log('login', $result->email);
            
            return TRUE;
        }
        
        return FALSE;
    }

    /**
     * Register user from OIDC claims
     */
    private function register_user_from_oidc($user_data)
    {
        $username = isset($user_data['username']) ? $user_data['username'] : $user_data['email'];
        $email = $user_data['email'];
        $password = nada_random_hash(); // Random password since OIDC handles auth
        
        $additional_data = array(
            'first_name' => isset($user_data['first_name']) ? $user_data['first_name'] : '',
            'last_name'  => isset($user_data['last_name']) ? $user_data['last_name'] : '',
            'email'      => $email,
            'identity'   => $username
        );
        
        $this->ci->ion_auth_model->register(
            $username, 
            $password, 
            $email, 
            $additional_data, 
            $group_name = 'user', 
            $auth_type = 'OIDC'
        );
    }

    /**
     * Update last login timestamp
     */
    private function update_last_login($id)
    {
        $this->ci->load->helper('date');

        if (isset($this->ci->ion_auth) && isset($this->ci->ion_auth->_extra_where)){
            $this->ci->db->where($this->ci->ion_auth->_extra_where);
        }
        
        $this->ci->db->update(
            $this->ci->config->item('tables')['users'], 
            array('last_login' => now()), 
            array('id' => $id)
        );
        
        return $this->ci->db->affected_rows() == 1;
    }

    /**
     * Disable page cache
     */
    private function disable_page_cache()
    {
        header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' );
        header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Cache-Control: post-check=0, pre-check=0', false );
        header( 'Pragma: no-cache' );
    }
}

//end-class

