<?php
/**
 * Template Name: Custom Login Page
 */
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo( 'charset' ); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Attendify Login</title>
  <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
  <?php wp_head(); ?>
</head>
<body class="custom-login-body">

  <div class="login-page">
    <?php get_template_part('ultimate-member/templates/login'); ?>
  </div>

  <?php wp_footer(); ?>
</body>
</html>
