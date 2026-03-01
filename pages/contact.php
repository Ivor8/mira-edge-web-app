<?php
/**
 * Contact Page - Mira Edge Technologies
 * Displays contact information, form, map, and FAQ
 */

try {
    $db = Database::getInstance()->getConnection();
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contact'])) {
        // Sanitize input
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        
        // Validation
        $errors = [];
        
        if (empty($name)) {
            $errors[] = 'Name is required';
        }
        
        if (empty($email)) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }
        
        if (empty($subject)) {
            $errors[] = 'Subject is required';
        }
        
        if (empty($message)) {
            $errors[] = 'Message is required';
        } elseif (strlen($message) < 10) {
            $errors[] = 'Message must be at least 10 characters';
        }
        
        // If no errors, insert into database
        if (empty($errors)) {
            try {
                $stmt = $db->prepare("INSERT INTO contact_messages (name, email, subject, message, phone, received_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
                $stmt->execute([
                    $name, 
                    $email, 
                    $subject, 
                    $message, 
                    $phone ?: null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                
                // Redirect to avoid resubmission and show success modal
                header('Location: ' . url('/?page=contact') . '&success=1');
                exit();
                
            } catch (PDOException $e) {
                error_log('Contact form insert error: ' . $e->getMessage());
                $errors[] = 'Database error occurred. Please try again later.';
            }
        }
        
        // If there are errors, store them in session and redirect back
        if (!empty($errors)) {
            $_SESSION['contact_errors'] = $errors;
            $_SESSION['contact_form_data'] = [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'subject' => $subject,
                'message' => $message
            ];
            header('Location: ' . url('/?page=contact') . '&error=1#contact-form');
            exit();
        }
    }
    
    // Get contact settings
    $company_email = getSetting('company_email', 'contact@miraedgetech.com');
    $company_phone = getSetting('company_phone', '+237 672 214 035');
    $company_address = getSetting('company_address', 'Yaounde, Cameroon');
    $whatsapp_number = getSetting('whatsapp_number', '+237 672 214 035');
    $working_hours = getSetting('working_hours', 'Mon-Fri: 8AM-6PM, Sat: 9AM-1PM');
    
    // Get social media links
    $facebook_url = getSetting('social_facebook', '#');
    $twitter_url = getSetting('social_twitter', '#');
    $linkedin_url = getSetting('social_linkedin', '#');
    $instagram_url = getSetting('social_instagram', '#');
    $github_url = getSetting('social_github', '#');
    
    // Get contact page SEO metadata
    $stmt = $db->prepare("
        SELECT * FROM seo_metadata 
        WHERE page_type = 'contact' OR (page_type = 'custom' AND page_slug = 'contact')
        LIMIT 1
    ");
    $stmt->execute();
    $seo_meta = $stmt->fetch() ?: [];
    
    // Get FAQ items if they exist in pages table
    $stmt = $db->prepare("
        SELECT * FROM pages 
        WHERE slug LIKE '%faq%' OR title LIKE '%FAQ%'
        ORDER BY created_at DESC
        LIMIT 6
    ");
    $stmt->execute();
    $faq_items = $stmt->fetchAll() ?: [];
    
} catch (PDOException $e) {
    error_log("Contact Page Error: " . $e->getMessage());
    $seo_meta = [];
    $faq_items = [];
}

// Set page-specific SEO metadata
$page_title = isset($seo_meta['meta_title']) && $seo_meta['meta_title'] ? $seo_meta['meta_title'] : 'Contact Us | Mira Edge Technologies - Get in Touch';
$page_description = isset($seo_meta['meta_description']) && $seo_meta['meta_description'] ? $seo_meta['meta_description'] : 'Get in touch with Mira Edge Technologies. Contact us for web development, mobile apps, digital marketing, or any tech solutions. We\'re here to help transform your business.';
$page_keywords = isset($seo_meta['meta_keywords']) && $seo_meta['meta_keywords'] ? $seo_meta['meta_keywords'] : 'contact mira edge, cameroon tech company, web development contact, mobile app inquiry, digital marketing consultation, tech support cameroon';
$og_title = isset($seo_meta['og_title']) && $seo_meta['og_title'] ? $seo_meta['og_title'] : 'Contact Mira Edge Technologies';
$og_description = isset($seo_meta['og_description']) && $seo_meta['og_description'] ? $seo_meta['og_description'] : 'Reach out to us for your technology needs. We respond within 24 hours.';
$og_image = isset($seo_meta['og_image']) && $seo_meta['og_image'] ? url($seo_meta['og_image']) : url('/assets/images/contact-hero.jpg');
$canonical_url = isset($seo_meta['canonical_url']) && $seo_meta['canonical_url'] ? $seo_meta['canonical_url'] : url('/?page=contact');

// Get form errors and old data from session
$form_errors = $_SESSION['contact_errors'] ?? [];
$old_data = $_SESSION['contact_form_data'] ?? [];
// Clear session data after retrieving
unset($_SESSION['contact_errors'], $_SESSION['contact_form_data']);

// Fallback FAQ items
if (empty($faq_items)) {
    $faq_items = [
        [
            'title' => 'What services do you offer?',
            'content' => 'We offer a comprehensive range of technology services including web development, mobile app development, digital marketing, SEO optimization, custom software solutions, IT consulting, and maintenance services. Visit our Services page for more details.'
        ],
        [
            'title' => 'How quickly do you respond to inquiries?',
            'content' => 'We typically respond to all inquiries within 24 hours during business days. For urgent matters, we recommend calling us directly or sending a message via WhatsApp.'
        ],
        [
            'title' => 'Do you work with international clients?',
            'content' => 'Yes, absolutely! We work with clients from around the world. Our team is experienced in remote collaboration and we use modern tools to ensure smooth communication regardless of location.'
        ],
        [
            'title' => 'What is your typical project timeline?',
            'content' => 'Project timelines vary depending on complexity and requirements. A simple website might take 2-3 weeks, while a complex web application or mobile app could take 2-3 months. We provide detailed timelines during the consultation phase.'
        ],
        [
            'title' => 'Do you offer maintenance and support after project completion?',
            'content' => 'Yes, we offer various maintenance and support packages to ensure your project continues to run smoothly after launch. We also provide training for your team if needed.'
        ],
        [
            'title' => 'How do I get a quote for my project?',
            'content' => 'You can request a quote by filling out the contact form on this page, sending us an email, or giving us a call. We\'ll schedule a consultation to understand your requirements and provide a detailed proposal.'
        ]
    ];
}
?>

<!-- Page Specific Meta Tags -->
<meta name="description" content="<?php echo e($page_description); ?>">
<meta name="keywords" content="<?php echo e($page_keywords); ?>">
<link rel="canonical" href="<?php echo e($canonical_url); ?>">

<!-- Open Graph / Facebook -->
<meta property="og:title" content="<?php echo e($og_title); ?>">
<meta property="og:description" content="<?php echo e($og_description); ?>">
<meta property="og:image" content="<?php echo e($og_image); ?>">
<meta property="og:url" content="<?php echo e($canonical_url); ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Mira Edge Technologies">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo e($og_title); ?>">
<meta name="twitter:description" content="<?php echo e($og_description); ?>">
<meta name="twitter:image" content="<?php echo e($og_image); ?>">
<meta name="twitter:site" content="@miraedgetech">

<!-- JSON-LD Schema Markup for Contact Page -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "ContactPage",
    "name": "Contact Mira Edge Technologies",
    "description": "<?php echo e($page_description); ?>",
    "url": "<?php echo e($canonical_url); ?>",
    "mainEntity": {
        "@type": "Organization",
        "name": "Mira Edge Technologies",
        "url": "<?php echo url('/'); ?>",
        "logo": "<?php echo url('/assets/images/Mira Edge Logo.png'); ?>",
        "contactPoint": [
            {
                "@type": "ContactPoint",
                "telephone": "<?php echo e($company_phone); ?>",
                "contactType": "customer service",
                "areaServed": "CM",
                "availableLanguage": ["English", "French"]
            },
            {
                "@type": "ContactPoint",
                "telephone": "<?php echo e($whatsapp_number); ?>",
                "contactType": "sales",
                "areaServed": "Global"
            }
        ],
        "address": {
            "@type": "PostalAddress",
            "addressLocality": "Yaounde",
            "addressCountry": "CM"
        }
    }
}
</script>

<!-- Contact Hero Section -->
<section class="contact-hero">
    <div class="container">
        <br><br><br><br>
        <div class="contact-hero-content">
            <h1 class="animate-up">Get In Touch</h1>
            <p class="animate-up" style="animation-delay: 0.2s;">We'd love to hear from you. Whether you have a project in mind, need technical consultation, or want to join our team, reach out to start the conversation.</p>
        </div>
    </div>
</section>

<!-- Contact Info Section -->
<section class="contact-info-section">
    <div class="container">
        <h2 class="section-title animate-up">Contact Information</h2>
        <p class="section-subtitle animate-up">Multiple ways to connect with our team</p>
        
        <div class="contact-info-grid">
            <!-- Address Card -->
            <div class="contact-info-card animate-up" style="animation-delay: 0.1s;">
                <div class="info-icon">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <h3>Visit Us</h3>
                <p><?php echo e($company_address); ?></p>
                <a href="#map" onclick="document.querySelector('.map-container').scrollIntoView({behavior: 'smooth'}); return false;">
                    <i class="fas fa-arrow-right"></i> Get Directions
                </a>
            </div>
            
            <!-- Phone Card -->
            <div class="contact-info-card animate-up" style="animation-delay: 0.2s;">
                <div class="info-icon">
                    <i class="fas fa-phone-alt"></i>
                </div>
                <h3>Call Us</h3>
                <p><a href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $company_phone)); ?>"><?php echo e($company_phone); ?></a></p>
                <p><a href="https://wa.me/<?php echo e(preg_replace('/[^0-9]/', '', $whatsapp_number)); ?>" target="_blank">
                    <i class="fab fa-whatsapp"></i> WhatsApp
                </a></p>
            </div>
            
            <!-- Email Card -->
            <div class="contact-info-card animate-up" style="animation-delay: 0.3s;">
                <div class="info-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <h3>Email Us</h3>
                <p><a href="mailto:<?php echo e($company_email); ?>"><?php echo e($company_email); ?></a></p>
                <p><small>We reply within 24 hours</small></p>
            </div>
            
            <!-- Hours Card -->
            <div class="contact-info-card animate-up" style="animation-delay: 0.4s;">
                <div class="info-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3>Working Hours</h3>
                <p><?php echo e($working_hours); ?></p>
                <p><small>GMT+1 (Yaounde Time)</small></p>
            </div>
        </div>
    </div>
</section>

<!-- Contact Form & Map Section -->
<section class="contact-form-section" id="contact-form">
    <div class="container">
        <div class="contact-container">
            <!-- Contact Form -->
            <div class="contact-form-wrapper animate-left">
                <h2>Send Us a Message</h2>
                <p>Fill out the form below and we'll get back to you as soon as possible.</p>
                
                <!-- Display validation errors (only shown, not modal) -->
                <?php if (!empty($form_errors) && isset($_GET['error'])): ?>
                <div class="alert alert-error" style="margin-bottom: 20px; padding: 15px; background-color: #f8d7da; color: #721c24; border-radius: 5px;">
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($form_errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <form id="contactForm" class="contact-form" method="post" action="<?php echo url('/?page=contact'); ?>#contact-form">
                    <input type="hidden" name="submit_contact" value="1">
                    
                    <div class="form-group">
                        <label for="name"><i class="fas fa-user"></i> Full Name *</label>
                        <input type="text" id="name" name="name" class="form-control <?php echo isset($form_errors) && !empty($form_errors) && empty($old_data['name']) ? 'error' : ''; ?>" 
                               value="<?php echo e($old_data['name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                        <input type="email" id="email" name="email" class="form-control <?php echo isset($form_errors) && !empty($form_errors) && empty($old_data['email']) ? 'error' : ''; ?>" 
                               value="<?php echo e($old_data['email'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone"><i class="fas fa-phone"></i> Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control" 
                               value="<?php echo e($old_data['phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="subject"><i class="fas fa-tag"></i> Subject *</label>
                        <select id="subject" name="subject" class="form-control <?php echo isset($form_errors) && !empty($form_errors) && empty($old_data['subject']) ? 'error' : ''; ?>" required>
                            <option value="" disabled <?php echo empty($old_data['subject']) ? 'selected' : ''; ?>>Select a subject</option>
                            <option value="general" <?php echo ($old_data['subject'] ?? '') == 'general' ? 'selected' : ''; ?>>General Inquiry</option>
                            <option value="project" <?php echo ($old_data['subject'] ?? '') == 'project' ? 'selected' : ''; ?>>Project Inquiry</option>
                            <option value="support" <?php echo ($old_data['subject'] ?? '') == 'support' ? 'selected' : ''; ?>>Technical Support</option>
                            <option value="careers" <?php echo ($old_data['subject'] ?? '') == 'careers' ? 'selected' : ''; ?>>Careers</option>
                            <option value="partnership" <?php echo ($old_data['subject'] ?? '') == 'partnership' ? 'selected' : ''; ?>>Partnership</option>
                            <option value="other" <?php echo ($old_data['subject'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="message"><i class="fas fa-comment"></i> Your Message *</label>
                        <textarea id="message" name="message" class="form-control <?php echo isset($form_errors) && !empty($form_errors) && empty($old_data['message']) ? 'error' : ''; ?>" rows="6" required><?php echo e($old_data['message'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <button type="submit" class="submit-btn" id="contactSubmitBtn">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Map Container -->
            <div class="map-container animate-right" id="map">
<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3980.823937013284!2d11.479921673963268!3d3.8479408484618918!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x108bcf6da309b55b%3A0x746fe6e440113ece!2sMira%20Edge%20Technologies!5e0!3m2!1sen!2sus!4v1771852818582!5m2!1sen!2sus" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                
                <div class="map-overlay">
                    <div class="map-overlay-icon">
                        <i class="fas fa-map-pin"></i>
                    </div>
                    <div class="map-overlay-content">
                        <h4>Mira Edge Technologies</h4>
                        <p><?php echo e($company_address); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Business Hours Section -->
<section class="business-hours-section">
    <div class="container">
        <div class="hours-container">
            <div class="hours-content animate-left">
                <h2>Business Hours</h2>
                <p>We're available during the following hours to assist you. Our team typically responds to inquiries within 24 hours.</p>
                
                <ul class="hours-list">
                    <?php
                    // Parse working hours
                    $hours_parts = explode(',', $working_hours);
                    foreach ($hours_parts as $part):
                        $part = trim($part);
                        if (strpos($part, '-') !== false):
                            list($days, $time) = explode(':', $part, 2);
                    ?>
                    <li>
                        <span class="hours-day"><?php echo e($days); ?></span>
                        <span class="hours-time"><?php echo e(trim($time)); ?></span>
                    </li>
                    <?php else: ?>
                    <li>
                        <span class="hours-day"><?php echo e($part); ?></span>
                    </li>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </ul>
                
                <div class="hours-note">
                    <i class="fas fa-clock"></i>
                    All times are in West Africa Time (GMT+1)
                </div>
            </div>
            
            <div class="hours-image animate-right">
                <img src="https://images.unsplash.com/photo-1521791136064-7986c2920216?ixlib=rb-4.0.3&auto=format&fit=crop&w=2069&q=80" 
                     alt="Our Team at Work">
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="faq-section">
    <div class="container">
        <h2 class="section-title animate-up">Frequently Asked Questions</h2>
        <p class="section-subtitle animate-up">Quick answers to common questions</p>
        
        <div class="faq-container">
            <?php foreach ($faq_items as $index => $faq): ?>
            <div class="faq-item animate-up" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <h3><?php echo e($faq['title']); ?></h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p><?php echo e($faq['content']); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Social Media Section -->
<section class="social-media-section">
    <div class="container">
        <h2 class="animate-up">Connect With Us</h2>
        <p class="animate-up" style="animation-delay: 0.1s;">Follow us on social media for updates, insights, and tech news</p>
        
        <div class="social-grid">
            <?php if ($facebook_url && $facebook_url != '#'): ?>
            <a href="<?php echo e($facebook_url); ?>" target="_blank" rel="noopener noreferrer" class="social-card facebook animate-up" style="animation-delay: 0.2s;">
                <i class="fab fa-facebook-f"></i>
                <span>Facebook</span>
            </a>
            <?php endif; ?>
            
            <?php if ($twitter_url && $twitter_url != '#'): ?>
            <a href="<?php echo e($twitter_url); ?>" target="_blank" rel="noopener noreferrer" class="social-card twitter animate-up" style="animation-delay: 0.3s;">
                <i class="fab fa-twitter"></i>
                <span>Twitter</span>
            </a>
            <?php endif; ?>
            
            <?php if ($linkedin_url && $linkedin_url != '#'): ?>
            <a href="<?php echo e($linkedin_url); ?>" target="_blank" rel="noopener noreferrer" class="social-card linkedin animate-up" style="animation-delay: 0.4s;">
                <i class="fab fa-linkedin-in"></i>
                <span>LinkedIn</span>
            </a>
            <?php endif; ?>
            
            <?php if ($instagram_url && $instagram_url != '#'): ?>
            <a href="<?php echo e($instagram_url); ?>" target="_blank" rel="noopener noreferrer" class="social-card instagram animate-up" style="animation-delay: 0.5s;">
                <i class="fab fa-instagram"></i>
                <span>Instagram</span>
            </a>
            <?php endif; ?>
            
            <?php if ($github_url && $github_url != '#'): ?>
            <a href="<?php echo e($github_url); ?>" target="_blank" rel="noopener noreferrer" class="social-card github animate-up" style="animation-delay: 0.6s;">
                <i class="fab fa-github"></i>
                <span>GitHub</span>
            </a>
            <?php endif; ?>
            
            <a href="https://wa.me/<?php echo e(preg_replace('/[^0-9]/', '', $whatsapp_number)); ?>" target="_blank" rel="noopener noreferrer" class="social-card whatsapp animate-up" style="animation-delay: 0.7s;">
                <i class="fab fa-whatsapp"></i>
                <span>WhatsApp</span>
            </a>
        </div>
    </div>
</section>

<!-- Newsletter Section -->
<section class="newsletter-section">
    <div class="container">
        <div class="newsletter-content animate-up">
            <h2>Subscribe to Our Newsletter</h2>
            <p>Get the latest tech insights, company updates, and special offers delivered to your inbox.</p>
            
            <form class="newsletter-form-large" method="post" action="<?php echo url('/?page=contact'); ?>">
                <input type="email" name="newsletter_email" placeholder="Your email address" required>
                <input type="hidden" name="submit_newsletter" value="1">
                <button type="submit">
                    <i class="fas fa-paper-plane"></i> Subscribe
                </button>
            </form>
        </div>
    </div>
</section>

<!-- Success Modal -->
<div id="successModal" class="success-modal">
    <div class="success-modal-content">
        <div class="success-modal-header">
            <i class="fas fa-check-circle"></i>
            <h2>Message Sent!</h2>
        </div>
        <div class="success-modal-body">
            <p>Thank you for contacting us. We've received your message and will get back to you within 24 hours.</p>
            <button class="btn" onclick="closeSuccessModal()">Close</button>
        </div>
    </div>
</div>

<!-- Contact Page JavaScript -->
<script>
// Toggle FAQ
function toggleFaq(element) {
    const faqItem = element.closest('.faq-item');
    faqItem.classList.toggle('active');
}

// Success Modal Functions
function openSuccessModal() {
    document.getElementById('successModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeSuccessModal() {
    document.getElementById('successModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Form submission loading state
document.addEventListener('DOMContentLoaded', function() {
    const contactForm = document.getElementById('contactForm');
    if (contactForm) {
        contactForm.addEventListener('submit', function() {
            const submitBtn = document.getElementById('contactSubmitBtn');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            submitBtn.disabled = true;
        });
    }
    
    // Newsletter form submission
    const newsletterForm = document.querySelector('.newsletter-form-large');
    if (newsletterForm) {
        newsletterForm.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            submitBtn.disabled = true;
        });
    }
    
    // Show success modal if success parameter is present
    <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
        openSuccessModal();
    <?php endif; ?>
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('successModal');
    if (event.target === modal) {
        closeSuccessModal();
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeSuccessModal();
    }
});

// Smooth scroll for directions link
document.querySelector('a[href="#map"]')?.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelector('.map-container').scrollIntoView({
        behavior: 'smooth',
        block: 'center'
    });
});

// Form validation enhancement
document.querySelectorAll('.form-control').forEach(input => {
    input.addEventListener('invalid', function(e) {
        e.preventDefault();
        this.classList.add('error');
    });
    
    input.addEventListener('input', function() {
        this.classList.remove('error');
    });
});

// Auto-hide error alerts after 5 seconds
setTimeout(function() {
    document.querySelectorAll('.alert-error').forEach(alert => {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);
</script>

<!-- Link to contact.css -->
<link rel="stylesheet" href="<?php echo url('/assets/css/contact.css'); ?>">