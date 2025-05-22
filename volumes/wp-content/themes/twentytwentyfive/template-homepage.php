<?php
/**
 * Template Name: Custom Attendify Homepage
 */
get_header();
?>

<div class="full-background" style="background-image: url('<?php echo get_template_directory_uri(); ?>/assets/images/backdrop.png'); background-size: cover; background-position: center; background-repeat: no-repeat;">
  
  <!-- 🔹 NAVIGATION -->
  <header class="login-header">
    <div class="header-container">
      <a href="<?php echo site_url(); ?>" class="logo-link">
        <img src="<?php echo get_template_directory_uri(); ?>/assets/images/AttendifyLogo.png" class="logo" alt="Attendify Logo">
      </a>
      <nav class="site-nav">
        <ul class="nav-list">
          <li><a href="<?php echo site_url('/?page_id=37'); ?>">Events</a></li>
          <li><a href="<?php echo site_url('/?page_id=12'); ?>">Register</a></li>
          <li><a href="<?php echo site_url('/?page_id=11'); ?>">Login</a></li>
          <li><a href="<?php echo site_url('/?page_id=13'); ?>">Account</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <!-- 🔹 HERO -->
  <section class="home-hero">
  <div class="home-hero-content">
    
    <div class="home-hero-left">
      <h1 class="home-hero-title">      </h1>
      
    </div>
    <div class="home-hero-right">
      <!-- Add visuals if needed -->
    </div>
  </div>
</section>


<div style="text-align: center; margin-top: 0px;margin-bottom: 40px;">
  <a href="<?php echo site_url('/?page_id=37'); ?>" class="hero-button">All events</a>
</div>

  <!-- 🔹 FOOTER -->
  <footer class="login-footer">
    <div class="footer-content">
      <div class="footer-col brand-col">
        <img src="<?php echo get_template_directory_uri(); ?>/assets/images/AttendifyLogo.png" alt="Attendify Logo" class="footer-logo">
      </div>

      <div class="footer-col links-col">
        <h4>Catalog</h4>
        <ul>
          <li><a href="<?php echo site_url('/?page_id=37'); ?>">Events</a></li>
          <li><a href="#">Sessions</a></li>
        </ul>
      </div>

      <div class="footer-col links-col">
        <h4>Services</h4>
        <ul>
          <li><a href="#">Event Registration</a></li>
          <li><a href="#">Billing & Invoicing</a></li>
          <li><a href="#">Session Planning</a></li>
          <li><a href="#">Live Monitoring</a></li>
        </ul>
      </div>

      <div class="footer-col links-col">
        <h4>About</h4>
        <ul>
          <li><a href="#">About Us</a></li>
          <li><a href="#">News</a></li>
          <li><a href="#">Partners</a></li>
        </ul>
      </div>

      <div class="footer-col contact-col">
        <a href="#" class="contact-button">Get In Touch</a>
      </div>
    </div>

    <div class="footer-bottom">
      <div class="social-icons">
        <a href="#"><i class="fab fa-facebook"></i></a>
        <a href="#"><i class="fab fa-instagram"></i></a>
      </div>
      <div class="footer-email">Attendify@gmail.com</div>
      <div class="footer-copy">© 2025 — Attendify. All Rights Reserved</div>
    </div>
  </footer>

</div>
<style>
  .full-background {
  background-position: center;  
  height: 10vh; /* vaste hoogte = 100% van scherm */

}
</style>

<?php get_footer(); ?>

