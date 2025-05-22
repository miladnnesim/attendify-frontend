<?php /* Template Name: Custom Login Page */ ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <title>Login - Attendify</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <?php wp_head(); ?>
    <style>
        body.custom-login-body {
            margin: 0;
            padding: 0;
            background-image: url('<?php echo get_stylesheet_directory_uri(); ?>/assets/images/backdrop.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            font-family: 'Poppins', sans-serif;
            background-attachment: scroll;

        }

        /* NAVBAR (white like in Figma) */
        .login-header {
            background-color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 2;
        }

        .login-header img.logo {
            height: 50px;
        }

        .nav-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
        }

        .nav-list li {
            margin-left: 1.5rem;
        }

        .nav-list a {
            color: #000;
            text-decoration: none;
            font-weight: 500;
        }

        .nav-list a:hover {
            color: #62A8D5;
        }

        /* TEXT (transparent, no bg) */
        .hero-text {
            text-align: center;
            color: white;
            margin-top: 4rem;
            background: none !important;
        }

        .hero-text h1 {
            font-size: 3.5rem;
            margin-bottom: 0.5rem;
        }

        .hero-text p {
            font-size: 1.2rem;
            font-weight: 300;
        }

        /* FORM SECTION */
        .login-form-section {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2.5rem 1rem;
        }

         .form-container {
            background-color: rgba(255, 255, 255, 0.97);
            padding: 2.5rem;
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            max-width: 420px;
            width: 100%;
            font-family: 'Poppins', sans-serif;
        }

        .form-container h2 {
	font-size: 2rem;
	color: #2C3D47;
	margin-bottom: 1.5rem;
	text-align: center;
	font-weight: 600;
}

        .form-container input {
            width: 100%;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border: 1px solid #ccc;
            border-radius: 8px;
        }

        .btn.hero-button {
	background-color: #62A8D5;
	border: none;
	font-weight: 600;
	padding: 0.75rem;
	width: 100%;
	color: white;
	border-radius: 8px;
	font-size: 1rem;
	cursor: pointer;
	transition: background-color 0.3s ease;
}

        .btn.hero-button:hover {
            background-color: #4c8cb4;
        }

        .auth-link-text {
	text-align: center;
	margin-top: 1rem;
	color: #555;
	font-size: 0.95rem;
}

.auth-link-text a {
	color: #62A8D5;
	text-decoration: none;
	font-weight: 500;
}

        .auth-link-text a:hover {
            text-decoration: underline;
        }

        /* FOOTER */
        .footer {
            background-color: #2C3D47; /* matches backdrop */
            color: #d0d2d6;
            padding: 3rem 1rem 1rem;
            margin-top: 4rem;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
        }

        .footer-col {
            flex: 1 1 200px;
        }

        .footer-logo {
            max-width: 150px;
        }

        .footer h4 {
            color: white;
            margin-bottom: 0.5rem;
        }

        .footer ul {
            list-style: none;
            padding: 0;
        }

        .footer ul li a {
            color: #d0d2d6;
            text-decoration: none;
        }

        .footer ul li a:hover {
            color: white;
        }

        .footer-bottom {
            border-top: 1px solid #44575c;
            margin-top: 2rem;
            padding-top: 1rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
        }

        .footer-email,
        .footer-copy {
            color: #a0a3a8;
        }

        .social-icons a {
            margin-right: 1rem;
            text-decoration: none;
            color: #d0d2d6;
        }

        .social-icons a:hover {
            color: white;
        }
    </style>
</head>
<body class="custom-login-body">

    <!-- NAVBAR -->
    <header class="login-header">
        <a href="<?php echo home_url(); ?>">
            <img src="<?php echo get_stylesheet_directory_uri(); ?>/assets/images/AttendifyLogo.png" alt="Attendify Logo" class="logo">
        </a>
        <nav>
            <ul class="nav-list">
                <li><a href="<?php echo home_url(); ?>">Home</a></li>
                <li><a href="<?php echo site_url('/?page_id=12'); ?>">Register</a></li>
                <li><a href="<?php echo site_url('/?page_id=37'); ?>">Events</a></li>
                <li><a href="<?php echo site_url('/?page_id=11'); ?>">Login</a></li>
                <li><a href="<?php echo home_url('/account'); ?>">Account</a></li>
            </ul>
        </nav>
    </header>

    <!-- NO BACKGROUND ON TEXT -->
    <section class="hero-text">
        <h1>attendify </h1>
        <p>Events that connect, inspire and innovate</p>
    </section>

    <!-- FORM -->
    <section class="login-form-section">
        <div class="form-container">
            <h2>Login</h2>
            <form action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>" method="post">
                <input type="text" name="log" placeholder="Username or Email" required>
                <input type="password" name="pwd" placeholder="Password" required>
                <button type="submit" class="btn hero-button">Login</button>
            </form>
            <p class="auth-link-text">Don't have an account? <a href="<?php echo site_url('/?page_id=12'); ?>">Register</a></p>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-col">
                <img src="<?php echo get_stylesheet_directory_uri(); ?>/assets/images/AttendifyLogo.png" alt="Attendify Logo" class="footer-logo">
            </div>
            <div class="footer-col">
                <h4>Catalog</h4>
                <ul>
                    <li><a href="<?php echo site_url('/?page_id=37'); ?>">Events</a></li>
                    <li><a href="#">Sessions</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Services</h4>
                <ul>
                    <li><a href="#">Event Registration</a></li>
                    <li><a href="#">Billing & Invoicing</a></li>
                    <li><a href="#">Session Planning</a></li>
                    <li><a href="#">Live Monitoring</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>About</h4>
                <ul>
                    <li><a href="#">About Us</a></li>
                    <li><a href="#">News</a></li>
                    <li><a href="#">Partners</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <div class="social-icons">
                <a href="#"><img src="<?php echo get_stylesheet_directory_uri(); ?>/assets/images/facebook.svg" alt="Facebook"></a>
                <a href="#"><img src="<?php echo get_stylesheet_directory_uri(); ?>/assets/images/instagram.svg" alt="Instagram"></a>
            </div>
            <div class="footer-email">Attendify@gmail.com</div>
            <div class="footer-copy">&copy; 2025 — Attendify. All Rights Reserved</div>
        </div>
    </footer>

    <?php wp_footer(); ?>
</body>
</html>
