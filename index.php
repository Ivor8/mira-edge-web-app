<?php
/**
 * Mira Edge Technologies - Main Website
 * Entry point for the application
 */

// Start output buffering
ob_start();

// Start session
session_start();

// Include core files
require_once 'includes/core/Database.php';
require_once 'includes/core/Session.php';
require_once 'includes/core/Auth.php';
require_once 'includes/functions/helpers.php';

// Initialize session and auth
$session = new Session();
$auth = new Auth();

// Check for redirect URL
$redirect_url = $session->get('redirect_url');
if ($redirect_url) {
    $session->remove('redirect_url');
    redirect($redirect_url);
}

// Get website settings
$site_name = getSetting('company_name', 'Mira Edge Technologies');
$site_email = getSetting('company_email', 'contact@miraedgetech.com');
$site_phone = getSetting('company_phone', '+237 6XX XXX XXX');

// Get user if logged in
$user = $session->getUser();

// Get page from URL (for routing)
$page = $_GET['page'] ?? 'home';

// Define valid pages
$valid_pages = [
    'home' => 'pages/index.php',
    'about' => 'pages/about.php',
    'services' => 'pages/services.php',
    'portfolio' => 'pages/portfolio.php',
    'blog' => 'pages/blog.php',
    'careers' => 'pages/careers.php',
    'contact' => 'pages/contact.php',
    'login' => 'login.php',
    'register' => 'register.php',
    'logout' => 'logout.php'
];

// Get the page file
$page_file = $valid_pages[$page] ?? 'pages/404.php';

// If user is logged in and trying to access login/register, redirect
if ($session->isLoggedIn() && in_array($page, ['login', 'register'])) {
    if ($session->isAdmin()) {
        redirect('/admin/dashboard.php');
    } elseif ($session->isDeveloper()) {
        redirect('/developer/dashboard.php');
    } else {
        redirect('/');
    }
}

// If user is not logged in and trying to access dashboard
if (!$session->isLoggedIn() && in_array($page, ['admin', 'developer'])) {
    redirect('/login.php');
}

// Check role-based access
if ($session->isLoggedIn()) {
    $user_role = $session->getUserRole();
    
    if ($page === 'admin' && !$session->isAdmin()) {
        $session->setFlash('error', 'Access denied. Admin privileges required.');
        redirect('/');
    }
    
    if ($page === 'developer' && !$session->isDeveloper()) {
        $session->setFlash('error', 'Access denied. Developer privileges required.');
        redirect('/');
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo e(getSetting('default_meta_description', 'Leading tech company in Cameroon offering web development, mobile apps, digital marketing and innovative tech solutions across Africa.')); ?>">
    <meta name="keywords" content="<?php echo e(getSetting('default_meta_keywords', 'web development Cameroon, mobile app development, digital marketing Cameroon, tech solutions Africa')); ?>">
    <meta name="author" content="Mira Edge Technologies">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo currentUrl(); ?>">
    <meta property="og:title" content="<?php echo e($site_name); ?>">
    <meta property="og:description" content="<?php echo e(getSetting('default_meta_description', 'Leading tech company in Cameroon offering web development, mobile apps, digital marketing and innovative tech solutions across Africa.')); ?>">
    <meta property="og:image" content="<?php echo e(getSetting('site_url', 'http://localhost/mira-edge-technologies')); ?>/assets/images/og-image.jpg">
    
    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo currentUrl(); ?>">
    <meta property="twitter:title" content="<?php echo e($site_name); ?>">
    <meta property="twitter:description" content="<?php echo e(getSetting('default_meta_description', 'Leading tech company in Cameroon offering web development, mobile apps, digital marketing and innovative tech solutions across Africa.')); ?>">
    <meta property="twitter:image" content="<?php echo e(getSetting('site_url', 'http://localhost/mira-edge-technologies')); ?>/assets/images/og-image.jpg">
    
    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon/favicon-16x16.png">
    <link rel="manifest" href="/assets/images/favicon/site.webmanifest">
    
    <title><?php echo e($site_name); ?> | <?php echo ucfirst($page); ?></title>
    
    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container navbar-container">
            <a href="/" class="logo">
                <span class="logo-text">MIRA</span><span class="logo-accent">EDGE</span>
            </a>
            
            <button class="mobile-toggle" aria-label="Toggle navigation">
                <i class="fas fa-bars"></i>
            </button>
            
            <ul class="nav-menu">
                <li><a href="/" class="nav-link <?php echo $page === 'home' ? 'active' : ''; ?>">Home</a></li>
                <li><a href="/?page=about" class="nav-link <?php echo $page === 'about' ? 'active' : ''; ?>">About</a></li>
                <li><a href="/?page=services" class="nav-link <?php echo $page === 'services' ? 'active' : ''; ?>">Services</a></li>
                <li><a href="/?page=portfolio" class="nav-link <?php echo $page === 'portfolio' ? 'active' : ''; ?>">Portfolio</a></li>
                <li><a href="/?page=blog" class="nav-link <?php echo $page === 'blog' ? 'active' : ''; ?>">Blog</a></li>
                <li><a href="/?page=careers" class="nav-link <?php echo $page === 'careers' ? 'active' : ''; ?>">Careers</a></li>
                <li><a href="/?page=contact" class="nav-link <?php echo $page === 'contact' ? 'active' : ''; ?>">Contact</a></li>
                
                <?php if ($session->isLoggedIn()): ?>
                    <li class="nav-dropdown">
                        <a href="#" class="nav-link dropdown-toggle">
                            <i class="fas fa-user-circle"></i>
                            <?php echo e($user['first_name']); ?>
                        </a>
                        <div class="dropdown-menu">
                            <?php if ($session->isAdmin()): ?>
                                <a href="/admin/dashboard.php" class="dropdown-item">
                                    <i class="fas fa-tachometer-alt"></i> Admin Dashboard
                                </a>
                            <?php elseif ($session->isDeveloper()): ?>
                                <a href="/developer/dashboard.php" class="dropdown-item">
                                    <i class="fas fa-tasks"></i> Developer Dashboard
                                </a>
                            <?php endif; ?>
                            <a href="/profile.php" class="dropdown-item">
                                <i class="fas fa-user"></i> My Profile
                            </a>
                            <a href="/logout.php" class="dropdown-item">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </li>
                <?php else: ?>
                    <li><a href="login.php" class="nav-link btn btn-outline">Login</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Flash Messages -->
    <?php if ($session->hasFlash()): ?>
        <div class="flash-messages">
            <?php if ($session->hasFlash('success')): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo e($session->getFlash('success')); ?>
                    <button class="alert-close">&times;</button>
                </div>
            <?php endif; ?>
            
            <?php if ($session->hasFlash('error')): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo e($session->getFlash('error')); ?>
                    <button class="alert-close">&times;</button>
                </div>
            <?php endif; ?>
            
            <?php if ($session->hasFlash('warning')): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo e($session->getFlash('warning')); ?>
                    <button class="alert-close">&times;</button>
                </div>
            <?php endif; ?>
            
            <?php if ($session->hasFlash('info')): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <?php echo e($session->getFlash('info')); ?>
                    <button class="alert-close">&times;</button>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Main Content -->
    <main>
        <?php 
        // Include the requested page
        if (file_exists($page_file)) {
            include $page_file;
        } else {
            include 'pages/404.php';
        }
        ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-about">
                    <h3 class="footer-title">Mira Edge Technologies</h3>
                    <p>Leading tech solutions provider in Cameroon and Africa, specializing in web development, mobile apps, and digital marketing.</p>
                    <div class="social-links">
                        <a href="#" class="social-link"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-linkedin"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-github"></i></a>
                    </div>
                </div>
                
                <div class="footer-links">
                    <h3 class="footer-title">Quick Links</h3>
                    <ul>
                        <li><a href="/?page=about">About Us</a></li>
                        <li><a href="/?page=services">Our Services</a></li>
                        <li><a href="/?page=portfolio">Portfolio</a></li>
                        <li><a href="/?page=blog">Blog</a></li>
                        <li><a href="/?page=careers">Careers</a></li>
                        <li><a href="/?page=contact">Contact Us</a></li>
                    </ul>
                </div>
                
                <div class="footer-services">
                    <h3 class="footer-title">Our Services</h3>
                    <ul>
                        <li><a href="/?page=services#web">Web Development</a></li>
                        <li><a href="/?page=services#mobile">Mobile App Development</a></li>
                        <li><a href="/?page=services#marketing">Digital Marketing</a></li>
                        <li><a href="/?page=services#solutions">Tech Solutions</a></li>
                        <li><a href="/?page=services#consulting">IT Consulting</a></li>
                        <li><a href="/?page=services#maintenance">Maintenance & Support</a></li>
                    </ul>
                </div>
                
                <div class="footer-contact">
                    <h3 class="footer-title">Contact Info</h3>
                    <ul>
                        <li><i class="fas fa-map-marker-alt"></i> Yaounde, Cameroon</li>
                        <li><i class="fas fa-phone"></i> <?php echo e($site_phone); ?></li>
                        <li><i class="fas fa-envelope"></i> <?php echo e($site_email); ?></li>
                        <li><i class="fas fa-clock"></i> Mon-Fri: 8AM-6PM</li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; <span class="current-year"><?php echo date('Y'); ?></span> Mira Edge Technologies. All rights reserved.</p>
                <p><a href="/privacy.php">Privacy Policy</a> | <a href="/terms.php">Terms of Service</a></p>
            </div>
        </div>
    </footer>

    <!-- Back to Top Button -->
    <button class="back-to-top" aria-label="Back to top">
        <i class="fas fa-chevron-up"></i>
    </button>

    <!-- JavaScript -->
    <script src="/assets/js/main.js"></script>
    
    <?php if ($page === 'home'): ?>
    <script>
        // Home page specific JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize hero animations
            const heroTitle = document.querySelector('.hero-title');
            const heroSubtitle = document.querySelector('.hero-subtitle');
            const heroButtons = document.querySelector('.hero-buttons');
            
            if (heroTitle) {
                setTimeout(() => {
                    heroTitle.style.opacity = '1';
                    heroTitle.style.transform = 'translateY(0)';
                }, 100);
            }
            
            if (heroSubtitle) {
                setTimeout(() => {
                    heroSubtitle.style.opacity = '1';
                    heroSubtitle.style.transform = 'translateY(0)';
                }, 300);
            }
            
            if (heroButtons) {
                setTimeout(() => {
                    heroButtons.style.opacity = '1';
                    heroButtons.style.transform = 'translateY(0)';
                }, 500);
            }
            
            // Stats counter animation
            const stats = document.querySelectorAll('.stat-number');
            if (stats.length > 0) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const target = parseInt(entry.target.getAttribute('data-target'));
                            animateCounter(entry.target, target);
                            observer.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.5 });
                
                stats.forEach(stat => observer.observe(stat));
            }
            
            function animateCounter(element, target) {
                let current = 0;
                const increment = target / 50;
                const timer = setInterval(() => {
                    current += increment;
                    element.textContent = Math.floor(current);
                    
                    if (current >= target) {
                        element.textContent = target.toLocaleString();
                        clearInterval(timer);
                    }
                }, 30);
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
<?php ob_end_flush(); ?>