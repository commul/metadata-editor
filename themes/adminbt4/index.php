<?php
if (!headers_sent()) {
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header("Pragma: no-cache");
}
?>
<?php //include_once APPPATH.'/config/site_menus.php'; ?>
<?php
//build language switcher for admin navbar
$_adm_languages    = $this->config->item('supported_languages');  // flat folder-name array
$_adm_lang_codes   = $this->config->item('language_codes');       // keyed by folder name
$_adm_current_lang = $this->session->userdata('language') ?: $this->config->item('language');
$_adm_lang_label   = (isset($_adm_lang_codes[$_adm_current_lang]['display']))
    ? $_adm_lang_codes[$_adm_current_lang]['display']
    : ucfirst($_adm_current_lang);
$_adm_show_switcher = is_array($_adm_languages) && count($_adm_languages) > 1;

$this->load->helper('site_menu');
$site_navigation_menu=get_site_menu();
?>
<!DOCTYPE html>
<html>
  <head>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
	  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	  <base href="<?php echo js_base_url(); ?>">
	  <title><?php echo $title; ?></title>

    <link rel="stylesheet" href="<?php echo base_url(); ?>/themes/nada52/fontawesome/css/all.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="<?php echo base_url();?>/vue-app/assets/bootstrap.min.css" crossorigin="anonymous">   

    <script src="<?php echo base_url();?>vue-app/assets/jquery.min.js"  crossorigin="anonymous"></script>
    <script src="<?php echo base_url();?>vue-app/assets/popper.min.js"  crossorigin="anonymous"></script>
    <script src="<?php echo base_url();?>vue-app/assets/bootstrap.bundle.min.js"  crossorigin="anonymous"></script>

    <link href="<?php echo base_url(); ?>themes/<?php echo $this->template->theme();?>/custom.css?v=bt4" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="<?php echo base_url(); ?>themes/<?php echo $this->template->theme();?>/style.css?v=3">
    

    
    <script type="text/javascript"> 
        var CI = {'base_url': '<?php echo site_url(); ?>'}; 
    </script> 

    <?php if (isset($_styles) ){ echo $_styles;} ?>
    <?php if (isset($_scripts) ){ echo $_scripts;} ?>


    <style>
      .dropdown-submenu {
        position: relative;
      }

      .dropdown-submenu>.dropdown-menu {
        top: 0;
        left: 100%;
      }
      .dropdown-submenu ul{
        max-height:550px;
        overflow-y: scroll;
      }

      .dropdown-submenu ul li > a{
        border-bottom:1px solid gainsboro;
      }

      .dropdown-menu > li > a {
          display: block;
          padding: 3px 20px;
          clear: both;
          font-weight: normal;
          line-height: 1.42857143;
          color: #333333;
          white-space: nowrap;
      }

      .sub-header {
          background: #F1F1F1;
          background: -webkit-gradient(radial, 100 36, 0, 100 -40, 120, from(#FAFAFA), to(#F1F1F1)), #F1F1F1;
          border-bottom: 1px solid #666;
          border-color: #E5E5E5;
          height: 100px;
          width: 100%;
          margin-top: -10px;
          margin-bottom: 20px;
          padding: 10px 25px
      }


      .nada-site-admin-nav .nav > li > a {
          position: relative;
          display: block;
          padding: 10px 15px;
          color:white;
          font-size:14px;
      }

      .app-version{
        font-weight:normal;
        font-size: 0.6em;
        vertical-align: super;        
      }

      .divider{
        border-bottom: 1px solid #E5E5E5;
        margin: 5px 0;
      }

    </style>

    <script>
    $(document).ready(function()  {
      /*global ajax error handler */
      $( document ).ajaxError(function(event, jqxhr, settings, exception) {
        if(jqxhr.status==401){
          window.location=CI.base_url+'/auth/login/?destination=admin/';
        }
      });
    });

    $(function() {
      $("ul.dropdown-menu [data-toggle='dropdown']").on("click", function(event) {
        event.preventDefault();
        event.stopPropagation();
        
        $(this).parents('.dropdown-submenu').siblings().find('.show').removeClass("show");        
        $(this).siblings().toggleClass("show");        
        
        //collapse all after nav is closed
        $(this).parents('li.nav-item.dropdown.show').on('hidden.bs.dropdown', function(e) {
          $('.dropdown-submenu .show').removeClass("show");
        });

      });
    });
    </script>

</head>
<body>


<nav class="navbar navbar-inverse navbar-expand-lg navbar-secondary bg-dark nada-site-admin-nav">  
  <a class="navbar-brand site-title" href="<?php echo site_url();?>/admin">Editor <span class="app-version"><?php echo APP_VERSION;?></span></a>
  <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>

  <div class="collapse navbar-collapse" id="navbarSupportedContent">
    <ul class="navbar-nav mr-auto">
      <?php echo $site_navigation_menu;?>      
    </ul>
    <ul class="nav navbar-nav navbar-right float-right pull-right">
      <li class="divider-vertical"></li>
      <?php if ($_adm_show_switcher): ?>
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          <i class="fas fa-language"></i> <?php echo htmlspecialchars($_adm_lang_label); ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-right">
          <?php foreach ($_adm_languages as $_adm_folder):
            if (!isset($_adm_lang_codes[$_adm_folder])) continue;
            $_adm_info = $_adm_lang_codes[$_adm_folder];
          ?>
          <li>
            <a class="dropdown-item" href="<?php echo site_url('switch_language/' . $_adm_folder . '?destination=admin'); ?>">
              <?php echo htmlspecialchars($_adm_info['display']); ?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </li>
      <?php endif; ?>
      <li class="dropdown">
      <?php $user=strtoupper($this->session->userdata('username'));?>
      <?php if ($user):?>
        <a href="#" class="dropdown-toggle" data-toggle="dropdown"><?php echo $user;?> <b class="caret"></b></a>
        <ul class="dropdown-menu dropdown-menu-right">                  
          <li><a target="_blank" href="<?php echo site_url();?>"><?php echo t('home');?></a></li>
          <li class="divider"></li>
          <li><?php echo anchor('auth/profile',t('profile'));?></li>
          <li class="divider"></li>
          <li><?php echo anchor('auth/change_password',t('change_password'));?></li>
          <li class="divider"></li>
          <li><?php echo anchor('auth/logout',t('logout'));?></li>
          
        </ul>
        <?php endif;?>
      </li>
    </ul>
</nav>



<?php if(isset($collection)):?>
<div class="sub-header" > <?php echo $collection;?></div>
<?php endif;?>

    <div class="container-fluid">
        <div>             
                
            <div id="content">
              <?php if (isset($content) ):?>
                  <?php print $content; ?>
              <?php endif;?>
            </div>         
        </div>
    </div>    
    
  </body>
</html>