<?php
/**
 * About Page - Mira Edge Technologies
 * SEO Optimized with Schema Markup
 */

// Get data from database
try {
    $db = Database::getInstance()->getConnection();
    
    // Get team members for about page
    $stmt = $db->prepare("
        SELECT u.*, GROUP_CONCAT(t.team_name) as teams
        FROM users u
        LEFT JOIN user_teams ut ON u.user_id = ut.user_id
        LEFT JOIN teams t ON ut.team_id = t.team_id
        WHERE u.status = 'active' 
        GROUP BY u.user_id
        ORDER BY 
            CASE 
                WHEN u.role = 'super_admin' THEN 1
                WHEN u.role = 'admin' THEN 2
                WHEN u.role = 'team_leader' THEN 3
                ELSE 4
            END,
            u.user_id ASC
    ");
    $stmt->execute();
    $team_members = $stmt->fetchAll();
    
    // Get company statistics
    $stats = [
        'clients' => 0,
        'revenue' => 735000,
        'team' => count($team_members),
        'projects' => 0,
        'years' => 1
    ];
    
    // Count completed projects
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM portfolio_projects WHERE status = 'completed'");
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['projects'] = $result['total'] ?? 8;
    
    // Count clients (from service_orders)
    $stmt = $db->prepare("SELECT COUNT(DISTINCT client_email) as total FROM service_orders");
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['clients'] = $result['total'] ?? 20;
    
    // Get milestones/timeline events
    $stmt = $db->prepare("
        SELECT * FROM pages 
        WHERE slug LIKE '%milestone%' OR title LIKE '%milestone%'
        ORDER BY created_at DESC 
        LIMIT 6
    ");
    $stmt->execute();
    $milestones = $stmt->fetchAll();
    
    // Get SEO metadata for about page
    $stmt = $db->prepare("
        SELECT * FROM seo_metadata 
        WHERE page_type = 'about' OR (page_type = 'custom' AND page_slug = 'about')
        LIMIT 1
    ");
    $stmt->execute();
    $seo_meta = $stmt->fetch();
    
} catch (PDOException $e) {
    error_log("About Page Error: " . $e->getMessage());
    $team_members = [];
    $milestones = [];
    $seo_meta = [];
}

// Set page-specific SEO metadata
$page_title = $seo_meta['meta_title'] ?? 'About Mira Edge Technologies | Leading Tech Innovation in Cameroon';
$page_description = $seo_meta['meta_description'] ?? 'Learn about Mira Edge Technologies - Our mission, vision, team, and impact in Cameroon\'s tech industry. Discover how we\'re driving digital transformation across Africa.';
$page_keywords = $seo_meta['meta_keywords'] ?? 'about mira edge, cameroon tech company, digital transformation africa, tech innovation cameroon, software development company, african tech startup';
$og_title = $seo_meta['og_title'] ?? 'About Mira Edge Technologies - Driving Innovation in Cameroon';
$og_description = $seo_meta['og_description'] ?? 'Meet the team behind Mira Edge Technologies and learn about our mission to transform Africa through technology.';
$og_image = $seo_meta['og_image'] ? url($seo_meta['og_image']) : url('/assets/images/about-hero.jpg');
$canonical_url = $seo_meta['canonical_url'] ?? url('/?page=about');

// Milestones data (if not in database)
if (empty($milestones)) {
    $milestones = [
        [
            'title' => 'Company Founded',
            'date' => 'November 2024',
            'description' => 'Mira Edge Technologies was established in November 2024 in Yaounde, Cameroon with a team of 4 passionate technologists.'
        ],
        [
            'title' => 'First MVP Development',
            'date' => 'March 2025',
            'description' => 'Started developing our first MVP. All information regarding this is highly classified.'
        ],
        [
            'title' => 'First International Client',
            'date' => '2022',
            'description' => 'As a freelance Software Developer, Engr. Nkwagoh landed his first international client and built a beautiful responsive website for EXIMAA.'
        ],
        [
            'title' => 'Software Engineer Role',
            'date' => '2023',
            'description' => 'Engr. Nkwagoh was appointed Full-Stack software engineer at SYNDA TECH, one of Cameroon\'s most successful tech companies.'
        ],
        [
            'title' => 'Company Founded',
            'date' => '2024',
            'description' => 'Established Mira Edge with vision to create locally-relevant tech solutions, starting with a team of 5 in a small Yaounde office.'
        ],
        [
            'title' => 'Revenue Milestone',
            'date' => '2025',
            'description' => 'Guided Mira Edge to surpass 735,000 FCFA annual revenue while maintaining 100% Cameroonian ownership.'
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

<!-- JSON-LD Schema Markup -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": "Mira Edge Technologies",
    "url": "<?php echo url('/'); ?>",
    "logo": "<?php echo url('/assets/images/Mira Edge Logo.png'); ?>",
    "description": "<?php echo e($page_description); ?>",
    "founder": {
        "@type": "Person",
        "name": "Engr. Nkwagoh Ivor Richard",
        "jobTitle": "CEO & Founder",
        "url": "<?php echo url('/?page=founder'); ?>"
    },
    "foundingDate": "2024-11-01",
    "address": {
        "@type": "PostalAddress",
        "addressLocality": "Yaounde",
        "addressCountry": "CM"
    },
    "contactPoint": {
        "@type": "ContactPoint",
        "telephone": "<?php echo e(getSetting('company_phone', '+237 672 214 035')); ?>",
        "contactType": "customer service",
        "email": "<?php echo e(getSetting('company_email', 'contact@miraedgetech.com')); ?>"
    },
    "sameAs": [
        "<?php echo e(getSetting('social_facebook', '#')); ?>",
        "<?php echo e(getSetting('social_twitter', '#')); ?>",
        "<?php echo e(getSetting('social_linkedin', '#')); ?>",
        "<?php echo e(getSetting('social_instagram', '#')); ?>",
        "<?php echo e(getSetting('social_github', '#')); ?>"
    ]
}
</script>

<!-- About Hero Section -->
<section class="about-hero">
    <div class="container">
        <div class="about-hero-content">
            <h1 class="animate-up">About Mira Edge Technologies</h1>
            <p class="animate-up" style="animation-delay: 0.2s;">Driving technological innovation in Cameroon since 2024, transforming businesses through cutting-edge digital solutions.</p>
        </div>
    </div>
</section>

<!-- Mission & Vision Section -->
<section class="mission-vision">
    <div class="container">
        <h2 class="section-title animate-up">Our Core Values</h2>
        <p class="section-subtitle animate-up">What drives us to innovate and deliver exceptional solutions</p>
        
        <div class="mv-container">
            <div class="mv-card animate-left" style="animation-delay: 0.1s;">
                <h3><i class="fas fa-bullseye"></i> Our Mission</h3>
                <p>To empower businesses across Cameroon and Africa with transformative technology solutions that solve real-world problems, drive growth, and create sustainable impact in our communities.</p>
            </div>
            
            <div class="mv-card animate-right" style="animation-delay: 0.2s;">
                <h3><i class="fas fa-eye"></i> Our Vision</h3>
                <p>To become the leading catalyst for digital transformation in Central Africa, recognized for our innovative approach and commitment to elevating the continent's technological capabilities.</p>
            </div>
        </div>
    </div>
</section>

<!-- Company Story Section -->
<section class="company-story">
    <div class="container">
        <div class="story-container">
            <div class="story-content animate-left">
                <h2>Our Story</h2>
                <p>Mira Edge Technologies was founded in November 2024 by Engr. Nkwagoh Ivor Richard, a passionate software engineer with a vision to bridge the technological gap in Central Africa. What started as a small team of 4 passionate technologists in a modest Yaounde office has quickly grown into a recognized name in Cameroon's tech industry.</p>
                <p>Our journey began with a simple belief: that world-class technology solutions can and should be built right here in Africa, by engineers who understand our unique challenges and opportunities. Today, we're proud to have worked with clients across various industries, delivering innovative solutions that drive real business value.</p>
                <p>We've achieved remarkable milestones in our short history, including our first international client, strategic partnerships with leading tech companies, and most importantly, the trust of our growing client base. But our journey is just beginning, and we're excited about the future of technology in Africa.</p>
                
                <div class="story-highlight">
                    <i class="fas fa-quote-left"></i>
                    <blockquote>
                        "Africa's digital transformation must be led by Africans. At Mira Edge, we're proving that world-class technology solutions can and should be built right here on the continent."
                    </blockquote>
                    <cite>- Engr. Nkwagoh Ivor Richard, CEO & Founder</cite>
                </div>
            </div>
            
            <div class="story-image animate-right">
                <img src="<?php echo url('/assets/images/team/11~2.jpg'); ?>" alt="Engr. Nkwagoh Ivor Richard - Founder of Mira Edge Technologies">
                <div class="image-caption">Engr. Nkwagoh Ivor Richard, Founder & CEO</div>
            </div>
        </div>
    </div>
</section>

<!-- Achievements Section -->
<section class="achievements">
    <div class="container">
        <h2 class="section-title animate-up">Our Impact in Numbers</h2>
        <p class="section-subtitle animate-up">Measuring our success through tangible results</p>
        
        <div class="stats-grid">
            <div class="stat-card animate-up" style="animation-delay: 0.1s;">
                <div class="stat-number"><span class="counter" data-target="<?php echo $stats['clients']; ?>">0</span>+</div>
                <div class="stat-label">Satisfied Clients</div>
            </div>
            
            <div class="stat-card animate-up" style="animation-delay: 0.2s;">
                <div class="stat-number"><span class="counter" data-target="<?php echo $stats['revenue']; ?>">0</span>K XAF+</div>
                <div class="stat-label">Revenue Generated</div>
            </div>
            
            <div class="stat-card animate-up" style="animation-delay: 0.3s;">
                <div class="stat-number"><span class="counter" data-target="<?php echo $stats['team']; ?>">0</span></div>
                <div class="stat-label">Dedicated Team Members</div>
            </div>
            
            <div class="stat-card animate-up" style="animation-delay: 0.4s;">
                <div class="stat-number"><span class="counter" data-target="<?php echo $stats['projects']; ?>">0</span></div>
                <div class="stat-label">Completed Projects</div>
            </div>
            
            <div class="stat-card animate-up" style="animation-delay: 0.5s;">
                <div class="stat-number"><span class="counter" data-target="<?php echo $stats['years']; ?>">0</span></div>
                <div class="stat-label">Years of Innovation</div>
            </div>
        </div>
    </div>
</section>

<!-- Company History Timeline -->
<section class="history">
    <div class="container">
        <h2 class="section-title animate-up">Our Journey</h2>
        <p class="section-subtitle animate-up">Key milestones in our growth and development</p>
        
        <div class="timeline">
            <?php foreach ($milestones as $index => $milestone): ?>
            <div class="timeline-item" style="opacity: 0;">
                <div class="timeline-dot"></div>
                <div class="timeline-content">
                    <div class="timeline-date"><?php echo e($milestone['date'] ?? date('F Y', strtotime($milestone['created_at'] ?? 'now'))); ?></div>
                    <h3><?php echo e($milestone['title']); ?></h3>
                    <p><?php echo e($milestone['description']); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Team Section -->
<!-- <section class="team-full">
    <div class="container">
        <h2 class="section-title animate-up">Meet Our Team</h2>
        <p class="section-subtitle animate-up">The talented people behind our success</p>
        
        <div class="team-grid">
            <?php if (!empty($team_members)): ?>
                <?php foreach ($team_members as $index => $member): ?>
                <div class="team-member animate-up" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                    <div class="member-image">
                        <img src="<?php echo $member['profile_image'] ? url($member['profile_image']) : 'https://via.placeholder.com/300x300?text=' . urlencode(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>" 
                             alt="<?php echo e($member['first_name'] . ' ' . $member['last_name'] . ' - ' . ($member['position'] ?? ucfirst(str_replace('_', ' ', $member['role'])))); ?>">
                    </div>
                    <div class="member-info">
                        <h3><?php echo e($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                        <p><?php echo e($member['position'] ?? ucfirst(str_replace('_', ' ', $member['role']))); ?></p>
                        <?php if (!empty($member['bio'])): ?>
                        <p class="member-bio"><?php echo e(substr($member['bio'], 0, 100)) . (strlen($member['bio']) > 100 ? '...' : ''); ?></p>
                        <?php endif; ?>
                        <div class="social-links">
                            <?php if (!empty($member['linkedin_url'])): ?>
                                <a href="<?php echo e($member['linkedin_url']); ?>" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn"><i class="fab fa-linkedin"></i></a>
                            <?php endif; ?>
                            <?php if (!empty($member['github_url'])): ?>
                                <a href="<?php echo e($member['github_url']); ?>" target="_blank" rel="noopener noreferrer" aria-label="GitHub"><i class="fab fa-github"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Static team members as fallback -->
                <div class="team-member animate-up" style="animation-delay: 0.1s;">
                    <a href="<?php echo url('/?page=founder'); ?>" style="text-decoration: none; color: inherit;">
                        <div class="member-image">
                            <img src="<?php echo url('/assets/images/team/11~2.jpg'); ?>" alt="Engr. Nkwagoh Ivor Richard - CEO & Founder">
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
                        <img src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?ixlib=rb-4.0.3&auto=format&fit=crop&w=688&q=80" alt="Engr. Liman Zarah - Co-Founder">
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
                        <img src="<?php echo url('/assets/images/team/terence.jpg'); ?>" alt="Engr. Ngulefac Terence - CTO">
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
                        <img src="<?php echo url('/assets/images/team/afa.jpg'); ?>" alt="Eng Foncho Afa - UX/UI Designer">
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
            <?php endif; ?>
        </div>
    </div>
</section> -->

<!-- Values Section -->
<section class="values">
    <div class="container">
        <h2 class="section-title animate-up">Our Core Values</h2>
        <p class="section-subtitle animate-up">The principles that guide everything we do</p>
        
        <div class="values-grid">
            <div class="value-card animate-up" style="animation-delay: 0.1s;">
                <div class="value-icon">
                    <i class="fas fa-star"></i>
                </div>
                <h3>Excellence</h3>
                <p>We strive for excellence in every project, delivering high-quality solutions that exceed expectations.</p>
            </div>
            
            <div class="value-card animate-up" style="animation-delay: 0.2s;">
                <div class="value-icon">
                    <i class="fas fa-lightbulb"></i>
                </div>
                <h3>Innovation</h3>
                <p>We embrace creativity and innovation, constantly exploring new technologies and approaches.</p>
            </div>
            
            <div class="value-card animate-up" style="animation-delay: 0.3s;">
                <div class="value-icon">
                    <i class="fas fa-handshake"></i>
                </div>
                <h3>Integrity</h3>
                <p>We conduct business with honesty, transparency, and ethical practices.</p>
            </div>
            
            <div class="value-card animate-up" style="animation-delay: 0.4s;">
                <div class="value-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3>Collaboration</h3>
                <p>We believe in the power of teamwork and partnership to achieve remarkable results.</p>
            </div>
            
            <div class="value-card animate-up" style="animation-delay: 0.5s;">
                <div class="value-icon">
                    <i class="fas fa-heart"></i>
                </div>
                <h3>Community</h3>
                <p>We're committed to giving back and fostering tech talent in our local community.</p>
            </div>
            
            <div class="value-card animate-up" style="animation-delay: 0.6s;">
                <div class="value-icon">
                    <i class="fas fa-leaf"></i>
                </div>
                <h3>Sustainability</h3>
                <p>We build solutions with long-term impact, considering environmental and social factors.</p>
            </div>
        </div>
    </div>
</section>

<!-- Partners Section -->
<section class="partners">
    <div class="container">
        <h2 class="section-title animate-up">Our Partners & Collaborators</h2>
        <p class="section-subtitle animate-up">Working with industry leaders to deliver exceptional solutions</p>
        
        <div class="partners-grid">
            <div class="partner-logo animate-up" style="animation-delay: 0.1s;">
                <img src="<?php echo url('/assets/images/partners/syndatech.png'); ?>" alt="SyndaTech - Technology Partner">
            </div>
            <div class="partner-logo animate-up" style="animation-delay: 0.2s;">
                <img src="<?php echo url('/assets/images/partners/emp.jpg'); ?>" alt="EMP - Business Partner">
            </div>
            <div class="partner-logo animate-up" style="animation-delay: 0.3s;">
                <img src="<?php echo url('/assets/images/partners/agrileap.png'); ?>" alt="AgriLeap - Innovation Partner">
            </div>
            <div class="partner-logo animate-up" style="animation-delay: 0.4s;">
                <img src="<?php echo url('/assets/images/partners/eximaa.png'); ?>" alt="EXIMAA - International Client">
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="about-cta">
    <div class="container">
        <h2 class="animate-up">Ready to Work With Us?</h2>
        <p class="animate-up" style="animation-delay: 0.2s;">Let's discuss how we can help transform your business with technology.</p>
        <div class="cta-buttons animate-up" style="animation-delay: 0.4s;">
            <a href="<?php echo url('/?page=contact'); ?>" class="btn">Get In Touch</a>
            <a href="<?php echo url('/?page=services'); ?>" class="btn btn-outline">Explore Services</a>
        </div>
    </div>
</section>

<!-- About Page JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Counter animation for stats
    const counters = document.querySelectorAll('.counter');
    
    const counterObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const counter = entry.target;
                const target = parseInt(counter.getAttribute('data-target'));
                let current = 0;
                const increment = target / 50;
                
                const timer = setInterval(() => {
                    current += increment;
                    counter.textContent = Math.floor(current);
                    
                    if (current >= target) {
                        counter.textContent = target.toLocaleString();
                        clearInterval(timer);
                    }
                }, 30);
                
                counterObserver.unobserve(counter);
            }
        });
    }, { threshold: 0.5 });
    
    counters.forEach(counter => counterObserver.observe(counter));
    
    // Timeline animation
    const timelineItems = document.querySelectorAll('.timeline-item');
    const timelineObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                setTimeout(() => {
                    entry.target.style.opacity = '1';
                }, index * 150);
                timelineObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.3 });
    
    timelineItems.forEach(item => timelineObserver.observe(item));
});
</script>