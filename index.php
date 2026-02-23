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
$site_phone = getSetting('company_phone', '+237 672 214 035');
$site_address = getSetting('company_address', 'Yaounde, Cameroon');
$working_hours = getSetting('working_hours', 'Mon-Fri: 8AM-6PM, Sat: 9AM-1PM');
$facebook_url = getSetting('social_facebook', '#');
$twitter_url = getSetting('social_twitter', '#');
$linkedin_url = getSetting('social_linkedin', '#');
$instagram_url = getSetting('social_instagram', '#');
$github_url = getSetting('social_github', '#');
$whatsapp_number = getSetting('whatsapp_number', '+237 672 214 035');

// Get user if logged in
$user = $session->getUser();

// Get page from URL (for routing)
$page = $_GET['page'] ?? 'home';

// Define valid pages
// Define valid pages
$valid_pages = [
    'home' => 'pages/index.php',
    'about' => 'pages/about.php',
    'services' => 'pages/services.php',
    'portfolio' => 'pages/portfolio.php',
    'blog' => 'pages/blog.php',
    'careers' => 'pages/careers.php',
    'contact' => 'pages/contact.php',
    'founder' => 'pages/founder.php',
    'login' => 'login.php',
    'register' => 'register.php',
    'logout' => 'logout.php'
];

// Get the page file
$page_file = $valid_pages[$page] ?? 'pages/404.php';

// Check for single post view (both old and new URL formats) - overrides default page file
if ($page === 'blog' && (isset($_GET['post_id']) || (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])))) {
    $page_file = 'pages/single/post.php';
}

// If user is logged in and trying to access login/register, redirect
if ($session->isLoggedIn() && in_array($page, ['login', 'register'])) {
    if ($session->isAdmin()) {
        redirect(url('/admin/dashboard.php'));
    } elseif ($session->isDeveloper()) {
        redirect(url('/developer/dashboard.php'));
    } else {
        redirect(url('/'));
    }
}

// If user is not logged in and trying to access dashboard
if (!$session->isLoggedIn() && in_array($page, ['admin', 'developer'])) {
    redirect(url('/login.php'));
}

// Check role-based access
if ($session->isLoggedIn()) {
    $user_role = $session->getUserRole();
    
    if ($page === 'admin' && !$session->isAdmin()) {
        $session->setFlash('error', 'Access denied. Admin privileges required.');
        redirect(url('/'));
    }
    
    if ($page === 'developer' && !$session->isDeveloper()) {
        $session->setFlash('error', 'Access denied. Developer privileges required.');
        redirect(url('/'));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="description" content="<?php echo e(getSetting('default_meta_description', 'Leading tech company in Cameroon offering web development, mobile apps, digital marketing and innovative tech solutions across Africa.')); ?>">
    <meta name="keywords" content="<?php echo e(getSetting('default_meta_keywords', 'web development Cameroon, mobile app development, digital marketing Cameroon, tech solutions Africa')); ?>">
    <meta name="author" content="Mira Edge Technologies">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <meta name="googlebot" content="index, follow">
    <meta name="bingbot" content="index, follow">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo currentUrl(); ?>">
    <meta property="og:title" content="<?php echo e($site_name); ?>">
    <meta property="og:description" content="<?php echo e(getSetting('default_meta_description', 'Leading tech company in Cameroon offering web development, mobile apps, digital marketing and innovative tech solutions across Africa.')); ?>">
    <meta property="og:image" content="<?php echo url('/pages/images/favicon/favicon-16x16.jpeg'); ?>">
    
    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo currentUrl(); ?>">
    <meta property="twitter:title" content="<?php echo e($site_name); ?>">
    <meta property="twitter:description" content="<?php echo e(getSetting('default_meta_description', 'Leading tech company in Cameroon offering web development, mobile apps, digital marketing and innovative tech solutions across Africa.')); ?>">
    <meta property="twitter:image" content="<?php echo url('/pages/images/favicon/favicon-16x16.jpeg'); ?>">
    
    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo url('/pages/images/favicon/favicon-16x16.jpeg'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo url('/pages/images/favicon/favicon-16x16.jpeg'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo url('/pages/images/favicon/favicon-16x16.jpeg'); ?>">
    <link rel="manifest" href="<?php echo url('/assets/images/favicon/site.webmanifest'); ?>">
    
    <title><?php echo e($site_name); ?> | <?php echo $page === 'home' ? 'Leading Tech Innovation in Cameroon' : ucfirst($page); ?></title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Main CSS -->
    <link rel="stylesheet" href="<?php echo url('/pages/assets/css/main.css'); ?>">
    
    <!-- Organization Schema JSON-LD (Global) -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "<?php echo e($site_name); ?>",
        "url": "<?php echo url('/'); ?>",
        "logo": "<?php echo url('/assets/images/Mira Edge Logo.png'); ?>",
        "description": "<?php echo e(getSetting('default_meta_description', 'Leading tech company in Cameroon offering web development, mobile apps, digital marketing and innovative tech solutions across Africa.')); ?>",
        "foundingDate": "2024-11-01",
        "foundingLocation": "Yaounde, Cameroon",
        "address": {
            "@type": "PostalAddress",
            "streetAddress": "<?php echo e(getSetting('company_address', 'Yaounde, Cameroon')); ?>",
            "addressLocality": "Yaounde",
            "addressCountry": "CM"
        },
        "contactPoint": {
            "@type": "ContactPoint",
            "telephone": "<?php echo e($site_phone); ?>",
            "contactType": "Customer Service",
            "email": "<?php echo e($site_email); ?>"
        },
        "sameAs": [
            "<?php echo e($facebook_url); ?>",
            "<?php echo e($twitter_url); ?>",
            "<?php echo e($linkedin_url); ?>",
            "<?php echo e($instagram_url); ?>"
        ],
        "founder": {
            "@type": "Person",
            "name": "Engr. Nkwagoh Ivor Richard",
            "jobTitle": "CEO & Founder",
            "url": "<?php echo url('/?page=founder'); ?>"
        },
        "numberOfEmployees": {
            "@type": "QuantitativeValue",
            "value": "1-10"
        },
        "areaServed": [
            "Cameroon",
            "Central Africa",
            "Africa"
        ],
        "knowsAbout": [
            "Web Development",
            "Mobile App Development",
            "Digital Marketing",
            "Software Development",
            "UI/UX Design"
        ]
    }
    </script>
    
    <!-- Page Specific CSS -->
    <?php if ($page === 'about'): ?>
    <link rel="stylesheet" href="<?php echo url('/pages/assets/css/about.css'); ?>">
    <?php elseif ($page === 'contact'): ?>
    <link rel="stylesheet" href="<?php echo url('/pages/assets/css/contact.css'); ?>">
    <?php elseif ($page === 'founder'): ?>
    <link rel="stylesheet" href="<?php echo url('/pages/assets/css/founder.css'); ?>">
    <?php endif; ?>
    
    <style>
        /* Additional Styles for Homepage */
        .popular-badge {
            background-color: var(--primary-color);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            position: absolute;
            top: 20px;
            right: 20px;
        }

        .service-card {
            position: relative;
        }

        .portfolio {
            padding: var(--section-padding);
            background-color: var(--light-gray);
        }

        .portfolio-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }

        .portfolio-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
        }

        .portfolio-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }

        .portfolio-image {
            height: 250px;
            overflow: hidden;
        }

        .portfolio-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .portfolio-card:hover .portfolio-image img {
            transform: scale(1.1);
        }

        .portfolio-info {
            padding: 20px;
        }

        .portfolio-info h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--primary-color);
        }

        .portfolio-info p {
            margin-bottom: 15px;
        }

        .testimonials {
            padding: var(--section-padding);
            background-color: var(--secondary-color);
        }

        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }

        .testimonial-card {
            background: var(--light-gray);
            padding: 30px;
            border-radius: 10px;
            box-shadow: var(--box-shadow);
            position: relative;
            transition: var(--transition);
        }

        .testimonial-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }

        .testimonial-card i.fa-quote-left {
            font-size: 2rem;
            color: var(--primary-color);
            opacity: 0.2;
            position: absolute;
            top: 20px;
            left: 20px;
        }

        .testimonial-card p {
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .testimonial-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }

        .testimonial-avatar-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .testimonial-author h4 {
            font-size: 1.1rem;
            margin-bottom: 5px;
        }

        .testimonial-author p {
            color: var(--dark-gray);
            margin-bottom: 0;
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        @media (max-width: 768px) {
            .portfolio-grid,
            .testimonials-grid {
                grid-template-columns: 1fr !important;
            }
            
            .portfolio-image {
                height: 200px;
            }
        }
    </style>
</head>
<body class="loading">
    <!-- Loader -->
    <div class="loader">
        <div class="loader-ball"></div>
    </div>

    <div class="content">
        <!-- Header & Navigation -->
        <header id="header">
            <div class="container">
                <nav class="navbar">
                    <a href="<?php echo url('/'); ?>" class="logo">
                        <img src="<?php echo url('/pages/images/favicon/favicon-16x16.png'); ?>" alt="Mira Edge Logo">
                        <span>Mira Edge</span>
                    </a>
                    <ul class="nav-links">
                        <li><a href="<?php echo url('/'); ?>" class="<?php echo $page === 'home' ? 'active' : ''; ?>">Home</a></li>
                        <li><a href="<?php echo url('/?page=about'); ?>" class="<?php echo $page === 'about' ? 'active' : ''; ?>">About</a></li>
                        <li><a href="<?php echo url('/?page=services'); ?>" class="<?php echo $page === 'services' ? 'active' : ''; ?>">Services</a></li>
                        <li><a href="<?php echo url('/?page=portfolio'); ?>" class="<?php echo $page === 'portfolio' ? 'active' : ''; ?>">Portfolio</a></li>
                        <li><a href="<?php echo url('/?page=blog'); ?>" class="<?php echo $page === 'blog' ? 'active' : ''; ?>">Blog</a></li>
                        <li><a href="<?php echo url('/?page=careers'); ?>" class="<?php echo $page === 'careers' ? 'active' : ''; ?>">Careers</a></li>
                        <li><a href="<?php echo url('/?page=contact'); ?>" class="<?php echo $page === 'contact' ? 'active' : ''; ?>">Contact</a></li>
                    </ul>
                    <div class="hamburger">
                        <i class="fas fa-bars"></i>
                    </div>
                </nav>
            </div>
        </header>

        <!-- Flash Messages -->
        <?php if ($session->hasFlash()): ?>
            <div class="flash-messages">
                <?php if ($session->hasFlash('success')): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo e($session->getFlash('success')); ?></span>
                        <button class="alert-close">&times;</button>
                    </div>
                <?php endif; ?>
                
                <?php if ($session->hasFlash('error')): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo e($session->getFlash('error')); ?></span>
                        <button class="alert-close">&times;</button>
                    </div>
                <?php endif; ?>
                
                <?php if ($session->hasFlash('warning')): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><?php echo e($session->getFlash('warning')); ?></span>
                        <button class="alert-close">&times;</button>
                    </div>
                <?php endif; ?>
                
                <?php if ($session->hasFlash('info')): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <span><?php echo e($session->getFlash('info')); ?></span>
                        <button class="alert-close">&times;</button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Main Content -->
        <main>
            <?php 
            // Include the requested page
            if ($page === 'home') {
                // Homepage content
                ?>
                
                <!-- Hero Section -->
                <section class="hero">
                    <div class="container">
                        <div class="hero-content">
                            <h1>Innovating Tech Solutions for Cameroon & Beyond</h1>
                            <p>Transforming creative ideas into digital solutions that drive growth and solve real-world problems across industries.</p>
                            <div class="hero-btns">
                                <a href="<?php echo url('/?page=services'); ?>" class="btn">Our Services</a>
                                <a href="<?php echo url('/?page=contact'); ?>" class="btn btn-outline">Get In Touch</a>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- About Section -->
                <section id="about" class="about">
                    <div class="container">
                        <h2 class="section-title animate-up">About Mira Edge</h2>
                        <p class="section-subtitle animate-up">Driving technological innovation and digital transformation in Cameroon</p>
                        
                        <div class="about-content">
                            <div class="about-text animate-left">
                                <h2>We Transform Ideas Into Tech Solutions</h2>
                                <p>Mira Edge Technologies is a leading tech company based in Cameroon, dedicated to bringing innovation and growth to the tech industry. Our mission is to solve real-world problems through cutting-edge digital solutions.</p>
                                <p>We work across various industries including accounting, law firms, sales, and more, helping businesses realize their digital potential. Our team of experts is committed to delivering excellence and pushing the boundaries of what's possible with technology.</p>
                                <p>By partnering with other forward-thinking tech companies in Cameroon, we're creating an ecosystem that fosters innovation and drives the digital transformation of Africa.</p>
                                <a href="<?php echo url('/?page=about'); ?>" class="btn">Learn More</a>
                            </div>
                            <div class="about-image animate-right">
                                <img src="https://images.unsplash.com/photo-1522071820081-009f0129c71c?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1170&q=80" alt="Mira Edge Team">
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Services Section -->
                <section id="services" class="services">
                    <div class="container">
                        <h2 class="section-title animate-up">Our Services</h2>
                        <p class="section-subtitle animate-up">We offer comprehensive tech solutions tailored to your business needs</p>
                        
                        <div class="services-grid">
                            <?php
                            // Get featured services (limit to 6)
                            try {
                                $db = Database::getInstance()->getConnection();
                                $stmt = $db->prepare("
                                    SELECT s.*, sc.category_name 
                                    FROM services s
                                    LEFT JOIN service_categories sc ON s.service_category_id = sc.service_category_id
                                    WHERE s.is_active = 1 
                                    ORDER BY s.is_popular DESC, s.display_order ASC 
                                    LIMIT 6
                                ");
                                $stmt->execute();
                                $services = $stmt->fetchAll();
                                
                                if (empty($services)) {
                                    throw new Exception('No services found');
                                }
                                
                                foreach ($services as $index => $service):
                                    $icons = [
                                        'Web Development' => 'fas fa-code',
                                        'Mobile Apps' => 'fas fa-mobile-alt',
                                        'Digital Marketing' => 'fas fa-bullhorn',
                                        'Tech Solutions' => 'fas fa-cogs'
                                    ];
                                    $icon = $icons[$service['category_name']] ?? 'fas fa-cog';
                            ?>
                                <div class="service-card animate-up" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                                    <div class="service-icon">
                                        <i class="<?php echo $icon; ?>"></i>
                                    </div>
                                    <h3><?php echo e($service['service_name']); ?></h3>
                                    <p><?php echo e($service['short_description']); ?></p>
                                    <?php if ($service['is_popular']): ?>
                                        <span class="popular-badge">Popular</span>
                                    <?php endif; ?>
                                    <a href="<?php echo url('/?page=services&id=' . $service['service_id']); ?>" class="btn btn-outline" style="margin-top: 20px;">Learn More</a>
                                </div>
                            <?php 
                                endforeach;
                            } catch (Exception $e) {
                                // Fallback services
                            ?>
                                <div class="service-card animate-up" style="animation-delay: 0.1s;">
                                    <div class="service-icon">
                                        <i class="fas fa-code"></i>
                                    </div>
                                    <h3>Custom Software Development</h3>
                                    <p>Bespoke software solutions designed to streamline your business operations and enhance productivity.</p>
                                </div>
                                
                                <div class="service-card animate-up" style="animation-delay: 0.2s;">
                                    <div class="service-icon">
                                        <i class="fas fa-globe"></i>
                                    </div>
                                    <h3>Web Development</h3>
                                    <p>Modern, responsive websites that represent your brand and engage your audience effectively.</p>
                                </div>
                                
                                <div class="service-card animate-up" style="animation-delay: 0.3s;">
                                    <div class="service-icon">
                                        <i class="fas fa-mobile-alt"></i>
                                    </div>
                                    <h3>Mobile App Development</h3>
                                    <p>Cross-platform mobile applications that deliver seamless user experiences on any device.</p>
                                </div>
                                
                                <div class="service-card animate-up" style="animation-delay: 0.4s;">
                                    <div class="service-icon">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                    <h3>Digital Transformation</h3>
                                    <p>Comprehensive strategies to modernize your business processes with cutting-edge technology.</p>
                                </div>
                                
                                <div class="service-card animate-up" style="animation-delay: 0.5s;">
                                    <div class="service-icon">
                                        <i class="fas fa-cloud"></i>
                                    </div>
                                    <h3>Cloud Solutions</h3>
                                    <p>Scalable cloud infrastructure and services to support your business growth and flexibility.</p>
                                </div>
                                
                                <div class="service-card animate-up" style="animation-delay: 0.6s;">
                                    <div class="service-icon">
                                        <i class="fas fa-shield-alt"></i>
                                    </div>
                                    <h3>Cybersecurity</h3>
                                    <p>Robust security solutions to protect your digital assets and ensure data privacy.</p>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </section>

                <!-- Team Section -->
                <section id="team" class="team">
                    <div class="container">
                        <h2 class="section-title animate-up">Our Team</h2>
                        <p class="section-subtitle animate-up">Meet the dedicated professionals driving our innovation</p>
                        
                        <div class="team-grid">
                            <?php
                            try {
                                $stmt = $db->prepare("
                                    SELECT u.*, GROUP_CONCAT(t.team_name) as teams
                                    FROM users u
                                    LEFT JOIN user_teams ut ON u.user_id = ut.user_id
                                    LEFT JOIN teams t ON ut.team_id = t.team_id
                                    WHERE u.status = 'active' AND u.role != 'super_admin'
                                    GROUP BY u.user_id
                                    ORDER BY u.user_id ASC
                                    LIMIT 4
                                ");
                                $stmt->execute();
                                $team_members = $stmt->fetchAll();
                                
                                if (!empty($team_members)):
                                    foreach ($team_members as $index => $member):
                            ?>
                                <div class="team-member animate-up" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                                    <div class="member-image">
                                        <img src="<?php echo $member['profile_image'] ? url($member['profile_image']) : 'https://via.placeholder.com/300x300?text=' . urlencode(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>" alt="<?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>">
                                    </div>
                                    <div class="member-info">
                                        <h3><?php echo e($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                                        <p><?php echo e($member['position'] ?? ucfirst(str_replace('_', ' ', $member['role']))); ?></p>
                                        <div class="social-links">
                                            <?php if (!empty($member['linkedin_url'])): ?>
                                                <a href="<?php echo e($member['linkedin_url']); ?>" target="_blank"><i class="fab fa-linkedin"></i></a>
                                            <?php endif; ?>
                                            <?php if (!empty($member['github_url'])): ?>
                                                <a href="<?php echo e($member['github_url']); ?>" target="_blank"><i class="fab fa-github"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php 
                                    endforeach;
                                else:
                                    throw new Exception('No team members found');
                                endif;
                            } catch (Exception $e) {
                            ?>
                                <!-- Founder -->
                                <div class="team-member animate-up" style="animation-delay: 0.1s;">
                                    <a href="<?php echo url('/?page=founder'); ?>" style="text-decoration: none; color: inherit;">
                                        <div class="member-image">
                                            <img src="<?php echo url('/assets/images/team/11~2.jpg'); ?>" alt="Engr. Nkwagoh Ivor Richard">
                                        </div>
                                        <div class="member-info">
                                            <h3>Engr. Nkwagoh Ivor Richard</h3>
                                            <p>CEO & Founder</p>
                                            <div class="social-links">
                                                <a href="#" onclick="event.stopPropagation()"><i class="fab fa-linkedin"></i></a>
                                                <a href="#" onclick="event.stopPropagation()"><i class="fab fa-twitter"></i></a>
                                                <a href="#" onclick="event.stopPropagation()"><i class="fab fa-github"></i></a>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                
                                <div class="team-member animate-up" style="animation-delay: 0.2s;">
                                    <div class="member-image">
                                        <img src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=688&q=80" alt="Engr. Liman Zarah">
                                    </div>
                                    <div class="member-info">
                                        <h3>Engr. Liman Zarah</h3>
                                        <p>Co-Founder</p>
                                        <div class="social-links">
                                            <a href="#"><i class="fab fa-linkedin"></i></a>
                                            <a href="#"><i class="fab fa-twitter"></i></a>
                                            <a href="#"><i class="fab fa-github"></i></a>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="team-member animate-up" style="animation-delay: 0.3s;">
                                    <div class="member-image">
                                        <img src="<?php echo url('/assets/images/team/terence.jpg'); ?>" alt="Engr. Ngulefac Terence">
                                    </div>
                                    <div class="member-info">
                                        <h3>Engr. Ngulefac Terence</h3>
                                        <p>CTO</p>
                                        <div class="social-links">
                                            <a href="#"><i class="fab fa-linkedin"></i></a>
                                            <a href="#"><i class="fab fa-twitter"></i></a>
                                            <a href="#"><i class="fab fa-github"></i></a>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="team-member animate-up" style="animation-delay: 0.4s;">
                                    <div class="member-image">
                                        <img src="<?php echo url('/assets/images/team/afa.jpg'); ?>" alt="Eng Foncho Afa">
                                    </div>
                                    <div class="member-info">
                                        <h3>Eng Foncho Afa</h3>
                                        <p>UX/UI Designer</p>
                                        <div class="social-links">
                                            <a href="#"><i class="fab fa-linkedin"></i></a>
                                            <a href="#"><i class="fab fa-twitter"></i></a>
                                            <a href="#"><i class="fab fa-dribbble"></i></a>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </section>

                <!-- Portfolio Section (Featured Projects) -->
                <?php
                try {
                    $stmt = $db->prepare("
                        SELECT p.*, pc.category_name
                        FROM portfolio_projects p
                        LEFT JOIN portfolio_categories pc ON p.category_id = pc.category_id
                        WHERE p.status != 'upcoming' AND p.is_featured = 1
                        ORDER BY p.display_order ASC
                        LIMIT 3
                    ");
                    $stmt->execute();
                    $portfolio_projects = $stmt->fetchAll();
                    
                    if (!empty($portfolio_projects)):
                ?>
                <section class="portfolio">
                    <div class="container">
                        <h2 class="section-title animate-up">Featured Projects</h2>
                        <p class="section-subtitle animate-up">Some of our best work</p>
                        
                        <div class="portfolio-grid">
                            <?php foreach ($portfolio_projects as $index => $project): ?>
                                <div class="portfolio-card animate-up" style="animation-delay: <?php echo $index * 0.2; ?>s;">
                                    <div class="portfolio-image">
                                        <img src="<?php echo url($project['featured_image']); ?>" alt="<?php echo e($project['title']); ?>">
                                    </div>
                                    <div class="portfolio-info">
                                        <h3><?php echo e($project['title']); ?></h3>
                                        <p><?php echo e($project['short_description']); ?></p>
                                        <a href="<?php echo url('/?page=portfolio&id=' . $project['project_id']); ?>" class="btn btn-outline">View Project</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="text-align: center; margin-top: 40px;">
                            <a href="<?php echo url('/?page=portfolio'); ?>" class="btn">View All Projects</a>
                        </div>
                    </div>
                </section>
                <?php 
                    endif;
                } catch (Exception $e) {
                    // Silently fail - don't show portfolio section if error
                }
                ?>

                <!-- Partners Section -->
                <section id="partners" class="partners">
                    <div class="container">
                        <h2 class="section-title animate-up">Our Partners</h2>
                        <p class="section-subtitle animate-up">Collaborating with industry leaders to deliver exceptional solutions</p>
                        
                        <div class="partners-slider">
                            <div class="partner-logo animate-up" style="animation-delay: 0.1s;">
                                <img src="<?php echo url('/assets/images/partners/syndatech.png'); ?>" alt="SyndaTech">
                            </div>
                            <div class="partner-logo animate-up" style="animation-delay: 0.2s;">
                                <img src="<?php echo url('/assets/images/partners/emp.jpg'); ?>" alt="EMP">
                            </div>
                            <div class="partner-logo animate-up" style="animation-delay: 0.3s;">
                                <img src="<?php echo url('/assets/images/partners/agrileap.png'); ?>" alt="AgriLeap">
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Testimonials Section -->
                <?php
                try {
                    $stmt = $db->prepare("SELECT * FROM testimonials WHERE is_active = 1 ORDER BY testimonial_id DESC LIMIT 3");
                    $stmt->execute();
                    $testimonials = $stmt->fetchAll();
                    
                    if (!empty($testimonials)):
                ?>
                <section class="testimonials">
                    <div class="container">
                        <h2 class="section-title animate-up">What Our Clients Say</h2>
                        <p class="section-subtitle animate-up">Testimonials from satisfied clients</p>
                        
                        <div class="testimonials-grid">
                            <?php foreach ($testimonials as $index => $testimonial): ?>
                                <div class="testimonial-card animate-up" style="animation-delay: <?php echo $index * 0.2; ?>s;">
                                    <i class="fas fa-quote-left"></i>
                                    <p><?php echo e($testimonial['quote']); ?></p>
                                    <div class="testimonial-author">
                                        <?php if (!empty($testimonial['avatar'])): ?>
                                            <img src="<?php echo url($testimonial['avatar']); ?>" alt="<?php echo e($testimonial['name']); ?>" class="testimonial-avatar">
                                        <?php else: ?>
                                            <div class="testimonial-avatar-placeholder">
                                                <?php echo substr($testimonial['name'], 0, 1); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <h4><?php echo e($testimonial['name']); ?></h4>
                                            <p><?php echo e($testimonial['position']); ?><?php echo !empty($testimonial['company']) ? ', ' . e($testimonial['company']) : ''; ?></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
                <?php 
                    endif;
                } catch (Exception $e) {
                    // Silently fail - don't show testimonials if error
                }
                ?>

                <?php
            } else {
                // Include other pages
                if (file_exists($page_file)) {
                    include $page_file;
                } else {
                    include 'pages/404.php';
                }
            }
            ?>
        </main>

        <!-- Footer -->
        <footer id="footer">
            <div class="container">
                <div class="footer-content">
                    <div class="footer-column">
                        <h3>About Mira Edge</h3>
                        <p>Innovating tech solutions in Cameroon and beyond. We transform creative ideas into digital solutions for businesses across industries.</p>
                        <div class="social-links">
                            <a href="<?php echo e($facebook_url); ?>" target="_blank"><i class="fab fa-facebook-f"></i></a>
                            <a href="<?php echo e($twitter_url); ?>" target="_blank"><i class="fab fa-twitter"></i></a>
                            <a href="<?php echo e($linkedin_url); ?>" target="_blank"><i class="fab fa-linkedin-in"></i></a>
                            <a href="<?php echo e($instagram_url); ?>" target="_blank"><i class="fab fa-instagram"></i></a>
                            <a href="<?php echo e($github_url); ?>" target="_blank"><i class="fab fa-github"></i></a>
                        </div>
                    </div>
                    
                    <div class="footer-column">
                        <h3>Quick Links</h3>
                        <ul class="footer-links">
                            <li><a href="<?php echo url('/'); ?>">Home</a></li>
                            <li><a href="<?php echo url('/?page=about'); ?>">About Us</a></li>
                            <li><a href="<?php echo url('/?page=services'); ?>">Services</a></li>
                            <li><a href="<?php echo url('/?page=portfolio'); ?>">Portfolio</a></li>
                            <li><a href="<?php echo url('/?page=blog'); ?>">Blog</a></li>
                            <li><a href="<?php echo url('/?page=careers'); ?>">Careers</a></li>
                            <li><a href="<?php echo url('/?page=contact'); ?>">Contact</a></li>
                        </ul>
                    </div>
                    
                    <div class="footer-column">
                        <h3>Our Services</h3>
                        <ul class="footer-links">
                            <li><a href="<?php echo url('/?page=services#web'); ?>">Web Development</a></li>
                            <li><a href="<?php echo url('/?page=services#mobile'); ?>">Mobile Apps</a></li>
                            <li><a href="<?php echo url('/?page=services#marketing'); ?>">Digital Marketing</a></li>
                            <li><a href="<?php echo url('/?page=services#solutions'); ?>">Tech Solutions</a></li>
                            <li><a href="<?php echo url('/?page=services#consulting'); ?>">IT Consulting</a></li>
                            <li><a href="<?php echo url('/?page=services#maintenance'); ?>">Maintenance</a></li>
                        </ul>
                    </div>
                    
                    <div class="footer-column">
                        <h3>Contact Info</h3>
                        <ul class="footer-links">
                            <li><i class="fas fa-map-marker-alt"></i> <?php echo e($site_address); ?></li>
                            <li><i class="fas fa-phone"></i> <a href="tel:<?php echo e($site_phone); ?>"><?php echo e($site_phone); ?></a></li>
                            <li><i class="fas fa-whatsapp"></i> <a href="https://wa.me/<?php echo e(preg_replace('/[^0-9]/', '', $whatsapp_number)); ?>" target="_blank">WhatsApp</a></li>
                            <li><i class="fas fa-envelope"></i> <a href="mailto:<?php echo e($site_email); ?>"><?php echo e($site_email); ?></a></li>
                            <li><i class="fas fa-clock"></i> <?php echo e($working_hours); ?></li>
                        </ul>
                    </div>
                </div>
                
                <div class="footer-bottom">
                    <p>&copy; <span class="current-year"><?php echo date('Y'); ?></span> Mira Edge Technologies. All Rights Reserved.</p>
                    <p>
                        <a href="<?php echo url('/privacy.php'); ?>">Privacy Policy</a> | 
                        <a href="<?php echo url('/terms.php'); ?>">Terms of Service</a>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Back to Top Button -->
    <button class="back-to-top" aria-label="Back to top">
        <i class="fas fa-chevron-up"></i>
    </button>

    <!-- Main JavaScript -->
    <script src="<?php echo url('/pages/assets/js/main.js'); ?>"></script>
    
    <!-- Page Specific JavaScript -->
    <?php if ($page === 'home'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Stats counter animation (if any stats on page)
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
            
            // Newsletter form submission
            const newsletterForm = document.querySelector('.newsletter-form');
            if (newsletterForm) {
                newsletterForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const email = this.querySelector('input[type="email"]').value;
                    const button = this.querySelector('button');
                    const originalText = button.innerHTML;
                    
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    button.disabled = true;
                    
                    try {
                        // Simulate API call - replace with actual endpoint
                        setTimeout(() => {
                            showNotification('success', 'Successfully subscribed to newsletter!');
                            this.reset();
                            button.innerHTML = originalText;
                            button.disabled = false;
                        }, 1000);
                    } catch (error) {
                        showNotification('error', 'An error occurred. Please try again.');
                        button.innerHTML = originalText;
                        button.disabled = false;
                    }
                });
            }
            
            function showNotification(type, message) {
                const notification = document.createElement('div');
                notification.className = `alert alert-${type}`;
                notification.innerHTML = `
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                    <span>${message}</span>
                    <button class="alert-close">&times;</button>
                `;
                
                const container = document.querySelector('.flash-messages');
                if (!container) {
                    const newContainer = document.createElement('div');
                    newContainer.className = 'flash-messages';
                    document.body.appendChild(newContainer);
                    newContainer.appendChild(notification);
                } else {
                    container.appendChild(notification);
                }
                
                setTimeout(() => {
                    notification.style.animation = 'slideOutRight 0.3s ease forwards';
                    setTimeout(() => {
                        notification.remove();
                    }, 300);
                }, 5000);
                
                notification.querySelector('.alert-close').addEventListener('click', function() {
                    notification.style.animation = 'slideOutRight 0.3s ease forwards';
                    setTimeout(() => {
                        notification.remove();
                    }, 300);
                });
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
<?php ob_end_flush(); ?>