<script type="text/javascript"> 
	if (top.frames.length!=0) {
		top.location=self.document.location;
	}
</script>	
<style>
.login-form{
    width: 100%;
    max-width: 500px;
    padding: 30px;
    margin: auto;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    background-color: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.btn-default {
    background-color: #ffffff;
    color: #000000;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 10px;
    text-align: center;
    text-decoration: none;
    display: inline-block;
    font-size: 16px;
}

.btn-default:hover {
    background-color: #f0f0f0;
    color: #000000;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 10px;
    text-align: center;
    text-decoration: none;
    display: inline-block;
    font-size: 16px;
}

.privacy-info{
    font-size:smaller;
}

.image_captcha {
    background:gainsboro;
    padding:10px;
    margin-bottom:20px;
}
.image_captcha input{
    display:block;
}

.login-divider {
    margin: 20px 0;
    text-align: center;
    position: relative;
}

.login-divider::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    height: 1px;
    background: #dee2e6;
}

.login-divider span {
    background: #fff;
    padding: 0 15px;
    position: relative;
    color: #6c757d;
}

.login-footer-links {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #dee2e6;
    text-align: center;
}

</style>
<div class="login-form mt-5">

<?php $reason=$this->session->flashdata('reason');?>
<?php if ($reason!==""):?>
    <?php echo ($reason!="") ? '<div class="reason">'.$reason.'</div>' : '';?>
<?php endif;?>

<?php $message=$this->session->flashdata('message');?>
<?php if (isset($error) && $error!=''):?>
	<?php $error= '<div class="alert alert-danger">'.$error.'</div>'?>
<?php else:?>
	<?php $error=$this->session->flashdata('error');?>
	<?php $error= ($error!="") ? '<div class="alert-danger">'.$error.'</div>' : '';?>            
<?php endif;?>	

<?php if ($error!=''):?> 
	<div><?php echo $error;?></div>
<?php endif;?>

<?php if ($message!=''):?> 
	<div class="alert alert-primary"><?php echo $message;?></div>
<?php endif;?>

<h1><?php echo t('log_in');?></h1>

<?php if (!isset($show_default_login) || $show_default_login): ?>
<form method="post" class="form" autocomplete="off">

<?php if (isset($popup_mode) && $popup_mode): ?>
<input type="hidden" name="mode" value="popup">
<?php endif; ?>

<div style="padding:5px;">

    <?php if (isset($email)): ?>
    <div class="form-group">
        <!--<label for="email"><?php echo t('email');?>:</label>-->
        <input class="form-control"  name="email" type="text" id="email"  value="<?php echo isset($email['value']) ? $email['value'] : ''; ?>" placeholder="<?php echo t('email');?>" />
    </div>
    <?php endif; ?>

    <?php if (isset($password)): ?>
    <div class="form-group">
        <!--<label for="password"><?php echo t('password');?>:</label>-->
        <input class="form-control"  name="password" type="password" id="password"  value="" placeholder="<?php echo t('password');?>"/>
    </div>
    <?php endif; ?>
    
    <?php if (isset($captcha_question)): ?>
    <div class="captcha_container">
        <?php echo $captcha_question;?>
    </div>
    <?php endif; ?>
        
    <div class="login-footer">
        <input type="submit" name="submit" value="<?php echo t('login');?>" class="btn btn-primary btn-block"/>
    </div>
</form>
<?php endif; ?>

<?php if (isset($show_oidc_button) && $show_oidc_button && (isset($show_default_login) && $show_default_login)): ?>
<div class="login-divider">
    <span><?php echo t('or'); ?></span>
</div>
<?php endif; ?>

<?php if (isset($show_oidc_button) && $show_oidc_button): ?>
<div class="oidc-login-section mb-3">
    <!-- Public client: Use JavaScript to call API endpoint -->
    <button type="button" id="oidc-login-btn" class="btn btn-default btn-block" style="display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
        <?php if (isset($oidc_provider_icon) && !empty($oidc_provider_icon)): ?>
        <img src="<?php echo $oidc_provider_icon; ?>" alt="" style="width: 20px; height: 20px; object-fit: contain;">
        <?php endif; ?>
        <span><?php echo isset($oidc_provider_name) ? $oidc_provider_name : 'Login with OIDC'; ?></span>
    </button>
</div>
<?php endif; ?>

<?php if (!isset($show_default_login) || $show_default_login): ?>
    <?php if (!isset($popup_mode) || !$popup_mode): ?>
    <div class="login-footer-links">
        <div class="ot clearfix">
            <?php if ($this->config->item("site_user_register")!=='no' && $this->config->item("site_password_protect")!=='yes'):?>	
                <span class="lnk first float-left"><?php echo anchor('auth/register',t('register'),'class="jx btn btn-link btn-sm"'); ?></span>
            <?php endif;?>
            <span class="lnk float-right"><?php echo anchor('auth/forgot_password',t('forgot_password'),'class="jx btn btn-link btn-sm"'); ?></span>
        </div>
    </div>
    <?php endif;?>
<?php endif; ?>
        
<div class="privacy-info mt-4 text-secondary"><?php echo t('site_login_privacy_terms');?></div>
        
</div>    

<script type="text/javascript">

$(function() {
  <?php if (isset($email) && isset($email['id'])): ?>
  $("#email").focus();
  <?php endif; ?>
  
  <?php if (isset($show_oidc_button) && $show_oidc_button): ?>
  // Handle OIDC login for public clients (SPA/PKCE flow)
  $('#oidc-login-btn').on('click', function() {
    var $btn = $(this);
    $btn.prop('disabled', true).text('Loading...');
    
    // Check for popup mode from URL or form
    var popupMode = <?php echo (isset($popup_mode) && $popup_mode) ? 'true' : 'false'; ?>;
    if (!popupMode) {
      // Also check URL parameter
      var urlParams = new URLSearchParams(window.location.search);
      popupMode = urlParams.get('mode') === 'popup';
    }
    
    // Store popup mode in sessionStorage for callback
    if (popupMode) {
      sessionStorage.setItem('oidc_popup_mode', 'true');
    }
    
    // Call API endpoint to get authorization URL
    $.ajax({
      url: '<?php echo site_url('auth/oidc_authorize_api'); ?>',
      method: 'GET',
      dataType: 'json',
      success: function(data) {
        if (data.auth_url) {
          // Store PKCE values in sessionStorage
          sessionStorage.setItem('oidc_code_verifier', data.code_verifier);
          sessionStorage.setItem('oidc_state', data.state);
          sessionStorage.setItem('oidc_nonce', data.nonce);
          
          // Redirect to authorization URL
          window.location.href = data.auth_url;
        } else {
          alert('Error: ' + (data.error || 'Failed to get authorization URL'));
          $btn.prop('disabled', false).html('<span><?php echo isset($oidc_provider_name) ? addslashes($oidc_provider_name) : 'Login with OIDC'; ?></span>');
        }
      },
      error: function(xhr) {
        var errorMsg = 'Failed to initiate login';
        try {
          var response = JSON.parse(xhr.responseText);
          errorMsg = response.error || errorMsg;
        } catch(e) {}
        alert('Error: ' + errorMsg);
        $btn.prop('disabled', false).html('<span><?php echo isset($oidc_provider_name) ? addslashes($oidc_provider_name) : 'Login with OIDC'; ?></span>');
      }
    });
  });
  <?php endif; ?>
});
</script>

