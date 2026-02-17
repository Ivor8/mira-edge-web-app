<?php
/**
 * Contact Page - Mira Edge Technologies
 * Displays contact information, form, map, and FAQ
 */

try {
    $db = Database::getInstance()->getConnection();
    
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
</script>

<!-- Contact Hero Section -->
<section class="contact-hero">
    <div class="container">
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
                <a href="#map" onclick="document.querySelector('.map-container').scrollIntoView({behavior: 'smooth'})">
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
<section class="contact-form-section">
    <div class="container">
        <div class="contact-container">
            <!-- Contact Form -->
            <div class="contact-form-wrapper animate-left">
                <h2>Send Us a Message</h2>
                <p>Fill out the form below and we'll get back to you as soon as possible.</p>
                
                <form id="contactForm" class="contact-form" onsubmit="submitContactForm(event)">
                    <div class="form-group">
                        <label for="name"><i class="fas fa-user"></i> Full Name *</label>
                        <input type="text" id="name" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                        <input type="email" id="email" name="email" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone"><i class="fas fa-phone"></i> Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="subject"><i class="fas fa-tag"></i> Subject *</label>
                        <select id="subject" name="subject" class="form-control" required>
                            <option value="" disabled selected>Select a subject</option>
                            <option value="general">General Inquiry</option>
                            <option value="project">Project Inquiry</option>
                            <option value="support">Technical Support</option>
                            <option value="careers">Careers</option>
                            <option value="partnership">Partnership</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="message"><i class="fas fa-comment"></i> Your Message *</label>
                        <textarea id="message" name="message" class="form-control" rows="6" required></textarea>
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
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3989.158527377184!2d11.514381!3d3.866512!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x108bebf1b2b3b3b3%3A0x3b3b3b3b3b3b3b3b!2sYaounde%2C%20Cameroon!5e0!3m2!1sen!2sus!4v1620000000000!5m2!1sen!2sus" 
                        allowfullscreen="" 
                        loading="lazy"
                        title="Mira Edge Technologies Location">
                </iframe>
                
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
            
            <form class="newsletter-form-large" onsubmit="submitNewsletter(event)">
                <input type="email" placeholder="Your email address" required>
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

// Form Submission
async function submitContactForm(event) {
    event.preventDefault();
    
    const submitBtn = document.getElementById('contactSubmitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner"></span> Sending...';
    submitBtn.disabled = true;
    
    const formData = new FormData(event.target);
    const data = {
        name: formData.get('name'),
        email: formData.get('email'),
        phone: formData.get('phone'),
        subject: formData.get('subject'),
        message: formData.get('message')
    };
    
    try {
        // Simulate API call - replace with actual endpoint
        await new Promise(resolve => setTimeout(resolve, 1500));
        
        // Show success modal
        openSuccessModal();
        event.target.reset();
        
    } catch (error) {
        console.error('Error:', error);
        showNotification('error', 'Failed to send message. Please try again or contact us directly.');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

// Newsletter Submission
async function submitNewsletter(event) {
    event.preventDefault();
    
    const form = event.target;
    const email = form.querySelector('input[type="email"]').value;
    const button = form.querySelector('button');
    const originalText = button.innerHTML;
    
    button.innerHTML = '<span class="spinner"></span>';
    button.disabled = true;
    
    try {
        // Simulate API call
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        showNotification('success', 'Successfully subscribed to our newsletter!');
        form.reset();
        
    } catch (error) {
        showNotification('error', 'Subscription failed. Please try again.');
    } finally {
        button.innerHTML = originalText;
        button.disabled = false;
    }
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

// Notification Function
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
document.querySelector('a[href="#map"]').addEventListener('click', function(e) {
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
</script>

<!-- Link to contact.css -->
<link rel="stylesheet" href="<?php echo url('/assets/css/contact.css'); ?>">