<?php
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metadata Editor Installer</title>

    <link href="<?php echo base_url(); ?>themes/nada52/fontawesome/css/all.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo base_url(); ?>themes/nada52/css/bootstrap.min.css">

    <script src="<?php echo base_url(); ?>vue-app/assets/jquery.min.js"></script>
    <script src="<?php echo base_url(); ?>themes/nada52/js/popper.min.js"></script>
    <script src="<?php echo base_url(); ?>themes/nada52/js/bootstrap.min.js"></script>

    <script type="text/javascript">
        var CI = {'base_url': '<?php echo site_url(); ?>'};
    </script>

    <?php if (isset($_styles)){ echo $_styles; } ?>
    <?php if (isset($_scripts)){ echo $_scripts; } ?>
</head>
<body>

<div class="container border rounded mt-4 p-0 shadow p-3 mb-5 bg-white rounded" style="max-width:700px;">
    <nav class="navbar navbar-light bg-light mb-3">
        <span class="navbar-brand mb-0 h1">Metadata Editor Installer</span>
    </nav>

    <?php if (isset($content)): ?>
        <?php print $content; ?>
    <?php endif; ?>
</div>

</body>
</html>