<?php
/**
 * Careers Page - Mira Edge Technologies
 * Displays job listings and internship opportunities
 */

// Get current page for pagination
$current_page = isset($_GET['jobs_page']) ? (int)$_GET['jobs_page'] : 1;
$jobs_per_page = 10;
$offset = ($current_page - 1) * $jobs_per_page;

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$category_id = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$job_type = isset($_GET['type']) ? sanitize($_GET['type']) : '';
$experience = isset($_GET['experience']) ? sanitize($_GET['experience']) : '';

try {
    $db = Database::getInstance()->getConnection();
    
    // Get all job categories
    $stmt = $db->prepare("
        SELECT * FROM job_categories 
        WHERE is_active = 1 
        ORDER BY display_order ASC
    ");
    $stmt->execute();
    $job_categories = $stmt->fetchAll() ?: [];
    
    // Get counts for each category
    foreach ($job_categories as &$category) {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM job_listings 
            WHERE job_category_id = ? AND is_active = 1 
            AND (application_deadline IS NULL OR application_deadline >= CURDATE())
        ");
        $stmt->execute([$category['job_category_id']]);
        $category['job_count'] = $stmt->fetch()['count'] ?? 0;
    }
    
    // Get counts for each job type
    $job_types = ['full_time', 'part_time', 'contract', 'internship', 'remote', 'hybrid'];
    $type_counts = [];
    foreach ($job_types as $type) {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM job_listings 
            WHERE job_type = ? AND is_active = 1 
            AND (application_deadline IS NULL OR application_deadline >= CURDATE())
        ");
        $stmt->execute([$type]);
        $type_counts[$type] = $stmt->fetch()['count'] ?? 0;
    }
    
    // Get counts for each experience level
    $exp_levels = ['entry', 'mid', 'senior', 'executive'];
    $exp_counts = [];
    foreach ($exp_levels as $level) {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM job_listings 
            WHERE experience_level = ? AND is_active = 1 
            AND (application_deadline IS NULL OR application_deadline >= CURDATE())
        ");
        $stmt->execute([$level]);
        $exp_counts[$level] = $stmt->fetch()['count'] ?? 0;
    }
    
    // Build query for jobs with filters
    $query = "
        SELECT j.*, c.category_name, c.slug as category_slug,
               CONCAT(u.first_name, ' ', u.last_name) as poster_name
        FROM job_listings j
        LEFT JOIN job_categories c ON j.job_category_id = c.job_category_id
        LEFT JOIN users u ON j.created_by = u.user_id
        WHERE j.is_active = 1 
        AND (j.application_deadline IS NULL OR j.application_deadline >= CURDATE())
    ";
    
    $params = [];
    
    // Apply search filter
    if (!empty($search)) {
        $query .= " AND (j.job_title LIKE ? OR j.short_description LIKE ? OR j.full_description LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // Apply category filter
    if ($category_id > 0) {
        $query .= " AND j.job_category_id = ?";
        $params[] = $category_id;
    }
    
    // Apply job type filter
    if (!empty($job_type)) {
        $query .= " AND j.job_type = ?";
        $params[] = $job_type;
    }
    
    // Apply experience level filter
    if (!empty($experience)) {
        $query .= " AND j.experience_level = ?";
        $params[] = $experience;
    }
    
    // Get total count for pagination
    $count_query = str_replace(
        "SELECT j.*, c.category_name, c.slug as category_slug, CONCAT(u.first_name, ' ', u.last_name) as poster_name",
        "SELECT COUNT(*) as total",
        $query
    );
    
    $stmt = $db->prepare($count_query);
    $stmt->execute($params);
    $total_jobs = $stmt->fetch()['total'] ?? 0;
    
    // Add ordering and pagination
    $query .= " ORDER BY j.is_featured DESC, j.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $jobs_per_page;
    $params[] = $offset;
    
    // Get jobs
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $jobs = $stmt->fetchAll() ?: [];
    
    // Calculate pagination
    $total_pages = ceil($total_jobs / $jobs_per_page);
    
    // Get featured jobs for sidebar
    $stmt = $db->prepare("
        SELECT job_id, job_title, slug, location, job_type, is_featured
        FROM job_listings 
        WHERE is_active = 1 AND is_featured = 1
        AND (application_deadline IS NULL OR application_deadline >= CURDATE())
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $featured_jobs = $stmt->fetchAll() ?: [];
    
    // Get SEO metadata
    $stmt = $db->prepare("
        SELECT * FROM seo_metadata 
        WHERE page_type = 'careers' OR (page_type = 'custom' AND page_slug = 'careers')
        LIMIT 1
    ");
    $stmt->execute();
    $seo_meta = $stmt->fetch() ?: [];
    
} catch (PDOException $e) {
    error_log("Careers Page Error: " . $e->getMessage());
    $job_categories = [];
    $jobs = [];
    $featured_jobs = [];
    $total_jobs = 0;
    $total_pages = 0;
    $seo_meta = [];
}

// Set page-specific SEO metadata
$page_title = isset($seo_meta['meta_title']) && $seo_meta['meta_title'] ? $seo_meta['meta_title'] : 'Careers | Mira Edge Technologies - Join Our Team';
$page_description = isset($seo_meta['meta_description']) && $seo_meta['meta_description'] ? $seo_meta['meta_description'] : 'Explore career opportunities at Mira Edge Technologies. Join our innovative team and help shape the future of technology in Cameroon and Africa.';
$page_keywords = isset($seo_meta['meta_keywords']) && $seo_meta['meta_keywords'] ? $seo_meta['meta_keywords'] : 'careers cameroon, tech jobs cameroon, software developer jobs, web developer jobs, internships cameroon, IT careers africa';
$og_title = isset($seo_meta['og_title']) && $seo_meta['og_title'] ? $seo_meta['og_title'] : 'Careers at Mira Edge Technologies';
$og_description = isset($seo_meta['og_description']) && $seo_meta['og_description'] ? $seo_meta['og_description'] : 'Join our team of innovators and help drive digital transformation in Africa. View our current job openings and internship opportunities.';
$og_image = isset($seo_meta['og_image']) && $seo_meta['og_image'] ? url($seo_meta['og_image']) : url('/assets/images/careers-hero.jpg');
$canonical_url = isset($seo_meta['canonical_url']) && $seo_meta['canonical_url'] ? $seo_meta['canonical_url'] : url('/?page=careers');

// Fallback jobs if database is empty
if (empty($jobs)) {
    $jobs = [
        [
            'job_id' => 1,
            'job_title' => 'Senior Full Stack Developer',
            'slug' => 'senior-full-stack-developer',
            'job_type' => 'full_time',
            'location' => 'Yaounde, Cameroon (Hybrid)',
            'short_description' => 'We are looking for an experienced Full Stack Developer to lead our web development projects and mentor junior developers.',
            'full_description' => '<p>As a Senior Full Stack Developer at Mira Edge Technologies, you will be responsible for designing, developing, and maintaining complex web applications. You will work closely with our design team to create seamless user experiences and with our project managers to deliver high-quality solutions on time.</p><h4>Key Responsibilities:</h4><ul><li>Lead the development of web applications from concept to deployment</li><li>Mentor junior developers and conduct code reviews</li><li>Architect scalable and maintainable solutions</li><li>Collaborate with cross-functional teams to define project requirements</li><li>Stay up-to-date with emerging technologies and industry trends</li></ul>',
            'requirements' => '<ul><li>5+ years of experience in web development</li><li>Strong proficiency in PHP, JavaScript, and MySQL</li><li>Experience with Laravel or similar frameworks</li><li>Front-end expertise in Vue.js or React</li><li>Understanding of DevOps practices and cloud services</li><li>Excellent problem-solving and communication skills</li></ul>',
            'responsibilities' => '<ul><li>Lead development projects and ensure best practices</li><li>Write clean, maintainable, and efficient code</li><li>Participate in architectural decisions</li><li>Collaborate with UI/UX designers</li><li>Ensure application performance and scalability</li></ul>',
            'benefits' => '<ul><li>Competitive salary (500,000 - 800,000 XAF/month)</li><li>Flexible working hours</li><li>Professional development opportunities</li><li>Health insurance</li><li>Annual bonus based on performance</li></ul>',
            'salary_range' => '500,000 - 800,000 XAF/month',
            'experience_level' => 'senior',
            'vacancy_count' => 2,
            'application_deadline' => '2025-04-15',
            'is_featured' => 1,
            'category_name' => 'Development',
            'job_category_id' => 1
        ],
        [
            'job_id' => 2,
            'job_title' => 'UI/UX Designer',
            'slug' => 'ui-ux-designer',
            'job_type' => 'full_time',
            'location' => 'Yaounde, Cameroon (Remote)',
            'short_description' => 'Join our creative team to design beautiful and intuitive user interfaces for web and mobile applications.',
            'full_description' => '<p>We are seeking a talented UI/UX Designer to create amazing user experiences for our clients. You will work on a variety of projects, from mobile apps to complex web platforms, ensuring that every interaction is intuitive and delightful.</p><h4>Key Responsibilities:</h4><ul><li>Create user flows, wireframes, and prototypes</li><li>Design visually stunning interfaces</li><li>Conduct user research and usability testing</li><li>Collaborate with developers to implement designs</li><li>Maintain design systems and guidelines</li></ul>',
            'requirements' => '<ul><li>3+ years of UI/UX design experience</li><li>Proficiency in Figma, Adobe XD, or Sketch</li><li>Strong portfolio demonstrating design skills</li><li>Understanding of user-centered design principles</li><li>Experience with responsive and mobile design</li></ul>',
            'responsibilities' => '<ul><li>Design user interfaces for web and mobile apps</li><li>Create wireframes and prototypes</li><li>Conduct user research and testing</li><li>Collaborate with development team</li><li>Ensure consistent brand experience</li></ul>',
            'benefits' => '<ul><li>Competitive salary (350,000 - 550,000 XAF/month)</li><li>Remote work option</li><li>Creative work environment</li><li>Professional growth opportunities</li></ul>',
            'salary_range' => '350,000 - 550,000 XAF/month',
            'experience_level' => 'mid',
            'vacancy_count' => 1,
            'application_deadline' => '2025-03-30',
            'is_featured' => 0,
            'category_name' => 'Design',
            'job_category_id' => 2
        ],
        [
            'job_id' => 3,
            'job_title' => 'Digital Marketing Specialist',
            'slug' => 'digital-marketing-specialist',
            'job_type' => 'full_time',
            'location' => 'Yaounde, Cameroon',
            'short_description' => 'Drive our clients\' digital presence through strategic marketing campaigns and SEO optimization.',
            'full_description' => '<p>We are looking for a Digital Marketing Specialist to develop and execute marketing strategies for our clients. You will be responsible for SEO, social media, content marketing, and analytics to drive growth and engagement.</p><h4>Key Responsibilities:</h4><ul><li>Develop and implement digital marketing strategies</li><li>Optimize websites for search engines</li><li>Manage social media accounts and campaigns</li><li>Create engaging content for various platforms</li><li>Analyze campaign performance and provide insights</li></ul>',
            'requirements' => '<ul><li>2+ years of digital marketing experience</li><li>Knowledge of SEO best practices</li><li>Experience with Google Analytics and Ads</li><li>Social media management skills</li><li>Excellent written and verbal communication</li></ul>',
            'responsibilities' => '<ul><li>Plan and execute marketing campaigns</li><li>Monitor and report on campaign performance</li><li>Optimize content for search engines</li><li>Manage social media presence</li><li>Stay updated with marketing trends</li></ul>',
            'benefits' => '<ul><li>Competitive salary (300,000 - 450,000 XAF/month)</li><li>Performance bonuses</li><li>Training opportunities</li><li>Collaborative team environment</li></ul>',
            'salary_range' => '300,000 - 450,000 XAF/month',
            'experience_level' => 'mid',
            'vacancy_count' => 1,
            'application_deadline' => '2025-04-10',
            'is_featured' => 0,
            'category_name' => 'Marketing',
            'job_category_id' => 3
        ],
        [
            'job_id' => 4,
            'job_title' => 'Software Development Intern',
            'slug' => 'software-development-intern',
            'job_type' => 'internship',
            'location' => 'Yaounde, Cameroon',
            'short_description' => 'Kickstart your career with hands-on experience in software development at Mira Edge Technologies.',
            'full_description' => '<p>Our internship program offers students and recent graduates the opportunity to gain practical experience in software development. You will work on real projects, learn from experienced mentors, and develop skills that will jumpstart your career.</p><h4>What You\'ll Learn:</h4><ul><li>Web development with modern technologies</li><li>Software development best practices</li><li>Team collaboration and agile methodologies</li><li>Problem-solving and debugging skills</li></ul>',
            'requirements' => '<ul><li>Currently pursuing or recently completed degree in Computer Science or related field</li><li>Basic knowledge of HTML, CSS, JavaScript</li><li>Familiarity with PHP or other backend language</li><li>Eagerness to learn and strong work ethic</li><li>Good communication skills</li></ul>',
            'responsibilities' => '<ul><li>Assist in developing web applications</li><li>Write clean and maintainable code</li><li>Participate in team meetings and code reviews</li><li>Learn from senior developers</li><li>Complete assigned tasks and projects</li></ul>',
            'benefits' => '<ul><li>Monthly stipend (50,000 XAF)</li><li>Hands-on experience</li><li>Mentorship from experienced developers</li><li>Potential for full-time employment</li><li>Certificate of completion</li></ul>',
            'salary_range' => '50,000 XAF/month (stipend)',
            'experience_level' => 'entry',
            'vacancy_count' => 3,
            'application_deadline' => '2025-05-01',
            'is_featured' => 1,
            'category_name' => 'Internship',
            'job_category_id' => 4
        ],
        [
            'job_id' => 5,
            'job_title' => 'Mobile App Developer (React Native)',
            'slug' => 'mobile-app-developer-react-native',
            'job_type' => 'full_time',
            'location' => 'Yaounde, Cameroon (Remote)',
            'short_description' => 'Build cross-platform mobile applications that reach thousands of users across Africa.',
            'full_description' => '<p>We are seeking a Mobile App Developer with React Native experience to join our growing team. You will develop and maintain mobile applications for clients in various industries, from e-commerce to healthcare.</p><h4>Key Responsibilities:</h4><ul><li>Develop cross-platform mobile apps using React Native</li><li>Collaborate with designers and backend developers</li><li>Optimize app performance and user experience</li><li>Debug and fix issues in existing apps</li><li>Stay updated with mobile development trends</li></ul>',
            'requirements' => '<ul><li>2+ years of React Native development</li><li>Experience with JavaScript/TypeScript</li><li>Knowledge of native modules and APIs</li><li>Understanding of mobile UI/UX principles</li><li>Experience with state management (Redux, Context)</li></ul>',
            'responsibilities' => '<ul><li>Build and maintain React Native applications</li><li>Write clean, reusable code</li><li>Optimize app performance</li><li>Collaborate with cross-functional teams</li><li>Publish apps to App Store and Google Play</li></ul>',
            'benefits' => '<ul><li>Competitive salary (400,000 - 600,000 XAF/month)</li><li>Remote work option</li><li>Flexible hours</li><li>Professional development budget</li></ul>',
            'salary_range' => '400,000 - 600,000 XAF/month',
            'experience_level' => 'mid',
            'vacancy_count' => 2,
            'application_deadline' => '2025-04-20',
            'is_featured' => 0,
            'category_name' => 'Development',
            'job_category_id' => 1
        ]
    ];
    
    $total_jobs = count($jobs);
    $total_pages = 1;
    
    // Set categories for fallback
    if (empty($job_categories)) {
        $job_categories = [
            ['job_category_id' => 1, 'category_name' => 'Development', 'slug' => 'development', 'job_count' => 2],
            ['job_category_id' => 2, 'category_name' => 'Design', 'slug' => 'design', 'job_count' => 1],
            ['job_category_id' => 3, 'category_name' => 'Marketing', 'slug' => 'marketing', 'job_count' => 1],
            ['job_category_id' => 4, 'category_name' => 'Internship', 'slug' => 'internship', 'job_count' => 1]
        ];
    }
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

<!-- JSON-LD Schema Markup for Careers -->
<?php if (!empty($jobs)): ?>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "ItemList",
    "itemListElement": [
        <?php foreach ($jobs as $index => $job): ?>
        {
            "@type": "ListItem",
            "position": <?php echo $index + 1; ?>,
            "item": {
                "@type": "JobPosting",
                "title": "<?php echo e($job['job_title']); ?>",
                "description": "<?php echo e(substr(strip_tags($job['full_description']), 0, 200)); ?>...",
                "datePosted": "<?php echo e($job['created_at'] ?? date('Y-m-d')); ?>",
                "validThrough": "<?php echo e($job['application_deadline'] ?? date('Y-m-d', strtotime('+30 days'))); ?>",
                "employmentType": "<?php echo strtoupper(str_replace('_', ' ', $job['job_type'])); ?>",
                "hiringOrganization": {
                    "@type": "Organization",
                    "name": "Mira Edge Technologies",
                    "sameAs": "<?php echo url('/'); ?>"
                },
                "jobLocation": {
                    "@type": "Place",
                    "address": {
                        "@type": "PostalAddress",
                        "addressLocality": "<?php echo e($job['location']); ?>",
                        "addressCountry": "CM"
                    }
                }
            }
        }<?php echo $index < count($jobs) - 1 ? ',' : ''; ?>
        <?php endforeach; ?>
    ]
}
</script>
<?php endif; ?>

<!-- Careers Hero Section -->
<section class="careers-hero">
    <div class="container">
        <div class="careers-hero-content">
            <h1 class="animate-up">Join Our Team</h1>
            <p class="animate-up" style="animation-delay: 0.2s;">Build your career at Mira Edge Technologies and help shape the future of technology in Africa</p>
        </div>
    </div>
</section>

<!-- Why Join Us Section -->
<section class="why-join-us">
    <div class="container">
        <h2 class="section-title animate-up">Why Work With Us</h2>
        <p class="section-subtitle animate-up">We offer more than just a job - we offer a career with purpose</p>
        
        <div class="benefits-grid">
            <div class="benefit-card animate-up" style="animation-delay: 0.1s;">
                <div class="benefit-icon">
                    <i class="fas fa-rocket"></i>
                </div>
                <h3>Innovative Projects</h3>
                <p>Work on cutting-edge technologies and solve real-world problems that make a difference.</p>
            </div>
            
            <div class="benefit-card animate-up" style="animation-delay: 0.2s;">
                <div class="benefit-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3>Growth Opportunities</h3>
                <p>Continuous learning, mentorship programs, and clear career progression paths.</p>
            </div>
            
            <div class="benefit-card animate-up" style="animation-delay: 0.3s;">
                <div class="benefit-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3>Great Team Culture</h3>
                <p>Collaborative environment with passionate professionals who support each other.</p>
            </div>
            
            <div class="benefit-card animate-up" style="animation-delay: 0.4s;">
                <div class="benefit-icon">
                    <i class="fas fa-hand-holding-heart"></i>
                </div>
                <h3>Work-Life Balance</h3>
                <p>Flexible hours, remote options, and a healthy work environment that values your wellbeing.</p>
            </div>
            
            <div class="benefit-card animate-up" style="animation-delay: 0.5s;">
                <div class="benefit-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h3>Learning & Development</h3>
                <p>Training budget, conferences, and resources to help you stay at the top of your game.</p>
            </div>
            
            <div class="benefit-card animate-up" style="animation-delay: 0.6s;">
                <div class="benefit-icon">
                    <i class="fas fa-gift"></i>
                </div>
                <h3>Competitive Benefits</h3>
                <p>Attractive compensation, health insurance, performance bonuses, and more.</p>
            </div>
        </div>
    </div>
</section>

<!-- Jobs Section -->
<section class="jobs-layout">
    <div class="container">
        <div class="jobs-container">
            <!-- Main Content - Jobs List -->
            <div class="jobs-main">
                <div class="jobs-header">
                    <h2 class="animate-up">Open Positions</h2>
                    <span class="jobs-count animate-up"><?php echo $total_jobs; ?> open positions</span>
                </div>
                
                <!-- Filters -->
                <div class="job-filters animate-up">
                    <form action="<?php echo url('/?page=careers'); ?>" method="GET" class="filters-row">
                        <input type="hidden" name="page" value="careers">
                        
                        <select name="category" class="filter-select">
                            <option value="">All Categories</option>
                            <?php foreach ($job_categories as $cat): ?>
                            <option value="<?php echo $cat['job_category_id']; ?>" <?php echo $category_id == $cat['job_category_id'] ? 'selected' : ''; ?>>
                                <?php echo e($cat['category_name']); ?> (<?php echo $cat['job_count'] ?? 0; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="type" class="filter-select">
                            <option value="">All Job Types</option>
                            <option value="full_time" <?php echo $job_type == 'full_time' ? 'selected' : ''; ?>>Full Time (<?php echo $type_counts['full_time'] ?? 0; ?>)</option>
                            <option value="part_time" <?php echo $job_type == 'part_time' ? 'selected' : ''; ?>>Part Time (<?php echo $type_counts['part_time'] ?? 0; ?>)</option>
                            <option value="contract" <?php echo $job_type == 'contract' ? 'selected' : ''; ?>>Contract (<?php echo $type_counts['contract'] ?? 0; ?>)</option>
                            <option value="internship" <?php echo $job_type == 'internship' ? 'selected' : ''; ?>>Internship (<?php echo $type_counts['internship'] ?? 0; ?>)</option>
                            <option value="remote" <?php echo $job_type == 'remote' ? 'selected' : ''; ?>>Remote (<?php echo $type_counts['remote'] ?? 0; ?>)</option>
                            <option value="hybrid" <?php echo $job_type == 'hybrid' ? 'selected' : ''; ?>>Hybrid (<?php echo $type_counts['hybrid'] ?? 0; ?>)</option>
                        </select>
                        
                        <select name="experience" class="filter-select">
                            <option value="">All Experience Levels</option>
                            <option value="entry" <?php echo $experience == 'entry' ? 'selected' : ''; ?>>Entry Level (<?php echo $exp_counts['entry'] ?? 0; ?>)</option>
                            <option value="mid" <?php echo $experience == 'mid' ? 'selected' : ''; ?>>Mid Level (<?php echo $exp_counts['mid'] ?? 0; ?>)</option>
                            <option value="senior" <?php echo $experience == 'senior' ? 'selected' : ''; ?>>Senior Level (<?php echo $exp_counts['senior'] ?? 0; ?>)</option>
                            <option value="executive" <?php echo $experience == 'executive' ? 'selected' : ''; ?>>Executive (<?php echo $exp_counts['executive'] ?? 0; ?>)</option>
                        </select>
                        
                        <div class="filter-search">
                            <input type="text" name="search" placeholder="Search jobs..." value="<?php echo e($search); ?>">
                            <button type="submit"><i class="fas fa-search"></i></button>
                        </div>
                    </form>
                </div>
                
                <!-- Jobs Grid -->
                <?php if (!empty($jobs)): ?>
                <div class="jobs-grid">
                    <?php foreach ($jobs as $index => $job): ?>
                    <?php
                        $is_expired = !empty($job['application_deadline']) && strtotime($job['application_deadline']) < time();
                        $job_type_class = str_replace('_', '-', $job['job_type']);
                    ?>
                    <div class="job-card <?php echo $job['is_featured'] ? 'featured' : ''; ?> animate-up" 
                         style="animation-delay: <?php echo $index * 0.1; ?>s;"
                         onclick="openJobModal(<?php echo htmlspecialchars(json_encode($job), ENT_QUOTES, 'UTF-8'); ?>)">
                        
                        <div class="job-card-header">
                            <div class="job-title">
                                <h3><?php echo e($job['job_title']); ?></h3>
                                <div class="job-meta-tags">
                                    <span class="job-type-badge <?php echo $job_type_class; ?>">
                                        <?php echo str_replace('_', ' ', ucfirst($job['job_type'])); ?>
                                    </span>
                                    <?php if (!empty($job['category_name'])): ?>
                                    <span class="job-category-badge"><?php echo e($job['category_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($job['is_featured']): ?>
                            <span class="popular-badge" style="position: relative; top: 0; right: 0;">Featured</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="job-card-body">
                            <p class="job-description"><?php echo e($job['short_description']); ?></p>
                            
                            <div class="job-details">
                                <span class="job-detail-item">
                                    <i class="fas fa-map-marker-alt"></i> <?php echo e($job['location']); ?>
                                </span>
                                <span class="job-detail-item">
                                    <i class="fas fa-briefcase"></i> <?php echo ucfirst($job['experience_level']); ?> Level
                                </span>
                                <span class="job-detail-item">
                                    <i class="fas fa-users"></i> <?php echo $job['vacancy_count']; ?> position(s)
                                </span>
                            </div>
                            
                            <?php if (!empty($job['application_deadline'])): ?>
                            <div class="job-deadline <?php echo $is_expired ? 'expired' : ''; ?>">
                                <i class="fas fa-clock"></i>
                                <?php if ($is_expired): ?>
                                    Application closed on <?php echo date('M d, Y', strtotime($job['application_deadline'])); ?>
                                <?php else: ?>
                                    Apply by <?php echo date('M d, Y', strtotime($job['application_deadline'])); ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="job-card-footer">
                            <?php if (!empty($job['salary_range'])): ?>
                            <div class="job-salary">
                                <?php echo e($job['salary_range']); ?>
                            </div>
                            <?php endif; ?>
                            <button class="btn btn-outline" onclick="event.stopPropagation(); openJobModal(<?php echo htmlspecialchars(json_encode($job), ENT_QUOTES, 'UTF-8'); ?>)">
                                View Details <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                    <a href="<?php echo url('/?page=careers&jobs_page=' . ($current_page - 1) . ($category_id ? '&category=' . $category_id : '') . ($job_type ? '&type=' . $job_type : '') . ($experience ? '&experience=' . $experience : '') . (!empty($search) ? '&search=' . urlencode($search) : '')); ?>" class="page-link prev">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="<?php echo url('/?page=careers&jobs_page=' . $i . ($category_id ? '&category=' . $category_id : '') . ($job_type ? '&type=' . $job_type : '') . ($experience ? '&experience=' . $experience : '') . (!empty($search) ? '&search=' . urlencode($search) : '')); ?>" 
                       class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                    
                    <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo url('/?page=careers&jobs_page=' . ($current_page + 1) . ($category_id ? '&category=' . $category_id : '') . ($job_type ? '&type=' . $job_type : '') . ($experience ? '&experience=' . $experience : '') . (!empty($search) ? '&search=' . urlencode($search) : '')); ?>" class="page-link next">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div class="no-jobs">
                    <i class="fas fa-briefcase"></i>
                    <h3>No Open Positions</h3>
                    <p><?php 
                        if (!empty($search) || $category_id || $job_type || $experience) {
                            echo "No jobs match your current filters. Try adjusting your search criteria.";
                        } else {
                            echo "We don't have any open positions at the moment. Please check back later or send us your resume for future opportunities.";
                        }
                    ?></p>
                    <a href="<?php echo url('/?page=contact'); ?>" class="btn">Contact Us</a>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar -->
            <aside class="jobs-sidebar">
                <!-- Categories Widget -->
                <?php if (!empty($job_categories)): ?>
                <div class="sidebar-widget animate-up">
                    <h3 class="widget-title">Categories</h3>
                    <ul class="categories-list">
                        <li>
                            <a href="<?php echo url('/?page=careers'); ?>">
                                All Categories
                                <span class="category-count"><?php echo $total_jobs; ?></span>
                            </a>
                        </li>
                        <?php foreach ($job_categories as $cat): ?>
                        <li>
                            <a href="<?php echo url('/?page=careers&category=' . $cat['job_category_id']); ?>">
                                <?php echo e($cat['category_name']); ?>
                                <span class="category-count"><?php echo $cat['job_count'] ?? 0; ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Job Types Widget -->
                <div class="sidebar-widget animate-up" style="animation-delay: 0.1s;">
                    <h3 class="widget-title">Job Types</h3>
                    <ul class="job-types-list">
                        <li>
                            <a href="<?php echo url('/?page=careers&type=full_time'); ?>">
                                Full Time
                                <span class="type-count">(<?php echo $type_counts['full_time'] ?? 0; ?>)</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo url('/?page=careers&type=part_time'); ?>">
                                Part Time
                                <span class="type-count">(<?php echo $type_counts['part_time'] ?? 0; ?>)</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo url('/?page=careers&type=contract'); ?>">
                                Contract
                                <span class="type-count">(<?php echo $type_counts['contract'] ?? 0; ?>)</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo url('/?page=careers&type=internship'); ?>">
                                Internship
                                <span class="type-count">(<?php echo $type_counts['internship'] ?? 0; ?>)</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo url('/?page=careers&type=remote'); ?>">
                                Remote
                                <span class="type-count">(<?php echo $type_counts['remote'] ?? 0; ?>)</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo url('/?page=careers&type=hybrid'); ?>">
                                Hybrid
                                <span class="type-count">(<?php echo $type_counts['hybrid'] ?? 0; ?>)</span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- Experience Levels Widget -->
                <div class="sidebar-widget animate-up" style="animation-delay: 0.2s;">
                    <h3 class="widget-title">Experience Level</h3>
                    <ul class="experience-list">
                        <li>
                            <a href="<?php echo url('/?page=careers&experience=entry'); ?>">
                                Entry Level
                                <span class="type-count">(<?php echo $exp_counts['entry'] ?? 0; ?>)</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo url('/?page=careers&experience=mid'); ?>">
                                Mid Level
                                <span class="type-count">(<?php echo $exp_counts['mid'] ?? 0; ?>)</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo url('/?page=careers&experience=senior'); ?>">
                                Senior Level
                                <span class="type-count">(<?php echo $exp_counts['senior'] ?? 0; ?>)</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo url('/?page=careers&experience=executive'); ?>">
                                Executive
                                <span class="type-count">(<?php echo $exp_counts['executive'] ?? 0; ?>)</span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- Quick Apply Widget -->
                <div class="sidebar-widget quick-apply-widget animate-up" style="animation-delay: 0.3s;">
                    <h3 class="widget-title">Quick Apply</h3>
                    <p>Don't see a position that fits? Send us your resume and we'll keep you in mind for future opportunities.</p>
                    <button class="upload-resume-btn" onclick="openQuickApplyModal()">
                        <i class="fas fa-upload"></i> Upload Resume
                    </button>
                    <p style="font-size: 0.9rem;">Or email us at: <a href="mailto:careers@miraedgetech.com" class="contact-email">careers@miraedgetech.com</a></p>
                </div>
                
                <!-- Featured Jobs Widget -->
                <?php if (!empty($featured_jobs)): ?>
                <div class="sidebar-widget animate-up" style="animation-delay: 0.4s;">
                    <h3 class="widget-title">Featured Jobs</h3>
                    <ul class="recent-posts-list">
                        <?php foreach ($featured_jobs as $featured): ?>
                        <li class="recent-post-item" style="cursor: pointer;" onclick="openJobModal(<?php echo htmlspecialchars(json_encode($featured), ENT_QUOTES, 'UTF-8'); ?>)">
                            <div class="recent-post-content" style="width: 100%;">
                                <h4><a href="#" onclick="event.preventDefault();"><?php echo e($featured['job_title']); ?></a></h4>
                                <div style="display: flex; gap: 10px; margin-top: 5px; font-size: 0.85rem; color: var(--dark-gray);">
                                    <span><i class="fas fa-map-marker-alt"></i> <?php echo e($featured['location']); ?></span>
                                    <span><i class="fas fa-clock"></i> <?php echo str_replace('_', ' ', ucfirst($featured['job_type'])); ?></span>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</section>

<!-- Internship Section -->
<section class="internship-section">
    <div class="container">
        <div class="internship-container">
            <div class="internship-content animate-left">
                <span class="internship-badge">Internship Program</span>
                <h2>Kickstart Your Tech Career</h2>
                <p>Our internship program offers hands-on experience, mentorship from industry experts, and the opportunity to work on real projects that make a difference. Whether you're a student or recent graduate, we provide the perfect environment to launch your career in tech.</p>
                
                <ul class="internship-features">
                    <li><i class="fas fa-check-circle"></i> 3-6 months paid internship</li>
                    <li><i class="fas fa-check-circle"></i> One-on-one mentorship</li>
                    <li><i class="fas fa-check-circle"></i> Real project experience</li>
                    <li><i class="fas fa-check-circle"></i> Flexible schedule for students</li>
                    <li><i class="fas fa-check-circle"></i> Potential for full-time employment</li>
                </ul>
                
                <a href="<?php echo url('/?page=careers&type=internship'); ?>" class="btn">View Internships</a>
            </div>
            <div class="internship-image animate-right">
                <img src="https://images.unsplash.com/photo-1523240795612-9a054b0db644?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80" alt="Internship Program">
            </div>
        </div>
    </div>
</section>

<!-- Job Detail Modal -->
<div id="jobModal" class="job-modal" style="display: none;">
    <div class="job-modal-content">
        <button class="job-modal-close" onclick="closeJobModal()">&times;</button>
        <div class="job-modal-header" id="jobModalHeader">
            <h2 id="jobModalTitle"></h2>
            <div class="job-meta" id="jobModalMeta"></div>
        </div>
        
        <div class="job-modal-body" id="jobModalBody">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<!-- Quick Apply Modal -->
<div id="quickApplyModal" class="job-modal" style="display: none;">
    <div class="job-modal-content" style="max-width: 600px;">
        <button class="job-modal-close" onclick="closeQuickApplyModal()">&times;</button>
        <div class="job-modal-header" style="background: linear-gradient(135deg, var(--primary-color) 0%, #333 100%);">
            <h2>Quick Apply</h2>
            <p>Send us your resume for future opportunities</p>
        </div>
        
        <div class="job-modal-body">
            <form id="quickApplyForm" onsubmit="submitQuickApply(event)">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="quick_name"><i class="fas fa-user"></i> Full Name *</label>
                        <input type="text" id="quick_name" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="quick_email"><i class="fas fa-envelope"></i> Email Address *</label>
                        <input type="email" id="quick_email" name="email" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="quick_phone"><i class="fas fa-phone"></i> Phone Number</label>
                        <input type="tel" id="quick_phone" name="phone" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="quick_position"><i class="fas fa-briefcase"></i> Position Interested In</label>
                        <input type="text" id="quick_position" name="position" class="form-control" placeholder="e.g., Developer, Designer, etc.">
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="quick_resume"><i class="fas fa-file-pdf"></i> Upload Resume *</label>
                        <div class="file-upload" onclick="document.getElementById('quick_resume').click()">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p>Click to upload or drag and drop</p>
                            <small>PDF, DOC, DOCX (Max 5MB)</small>
                            <input type="file" id="quick_resume" name="resume" accept=".pdf,.doc,.docx" style="display: none;" required onchange="updateFileName(this, 'resume-file-name')">
                            <div id="resume-file-name" class="file-name"></div>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="quick_cover"><i class="fas fa-envelope-open-text"></i> Cover Letter / Message</label>
                        <textarea id="quick_cover" name="cover_letter" class="form-control" rows="4" placeholder="Tell us why you're interested in joining Mira Edge..."></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <button type="submit" class="submit-application-btn" id="quickApplyBtn">
                            <i class="fas fa-paper-plane"></i> Submit Application
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Careers Page JavaScript -->
<script>
// Store jobs data for quick access
const jobsData = <?php echo json_encode($jobs); ?>;

// Job Modal Functions
function openJobModal(job) {
    const modal = document.getElementById('jobModal');
    const modalTitle = document.getElementById('jobModalTitle');
    const modalMeta = document.getElementById('jobModalMeta');
    const modalBody = document.getElementById('jobModalBody');
    
    // Set header content
    modalTitle.textContent = job.job_title || 'Job Details';
    
    const jobTypeClass = (job.job_type || 'full_time').replace('_', '-');
    const isExpired = job.application_deadline && new Date(job.application_deadline) < new Date();
    
    modalMeta.innerHTML = `
        <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(job.location || 'Yaounde, Cameroon')}</span>
        <span><i class="fas fa-clock"></i> <span class="job-type-badge ${jobTypeClass}">${formatJobType(job.job_type)}</span></span>
        <span><i class="fas fa-calendar-alt"></i> Posted: ${job.created_at ? new Date(job.created_at).toLocaleDateString() : 'Recently'}</span>
        ${job.application_deadline ? `
            <span><i class="fas fa-hourglass-end"></i> Deadline: ${new Date(job.application_deadline).toLocaleDateString()} ${isExpired ? '(Expired)' : ''}</span>
        ` : ''}
    `;
    
    // Build modal body
    modalBody.innerHTML = `
        <div class="job-section">
            <h3>Job Description</h3>
            ${job.full_description || '<p>No description available.</p>'}
        </div>
        
        <div class="job-section">
            <h3>Requirements</h3>
            ${job.requirements || '<p>No specific requirements listed.</p>'}
        </div>
        
        <div class="job-section">
            <h3>Responsibilities</h3>
            ${job.responsibilities || '<p>No responsibilities listed.</p>'}
        </div>
        
        ${job.benefits ? `
        <div class="job-section">
            <h3>Benefits</h3>
            ${job.benefits}
        </div>
        ` : ''}
        
        <div class="job-section">
            <h3>Additional Information</h3>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                <div>
                    <strong>Experience Level:</strong><br>
                    ${formatExperience(job.experience_level)}
                </div>
                <div>
                    <strong>Vacancies:</strong><br>
                    ${job.vacancy_count || 1} position(s)
                </div>
                ${job.salary_range ? `
                <div>
                    <strong>Salary Range:</strong><br>
                    ${escapeHtml(job.salary_range)}
                </div>
                ` : ''}
                <div>
                    <strong>Category:</strong><br>
                    ${escapeHtml(job.category_name || 'General')}
                </div>
            </div>
        </div>
        
        <div class="application-form">
            <h3>Apply for this Position</h3>
            <form id="jobApplicationForm" onsubmit="submitJobApplication(event, ${job.job_id})">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="app_name_${job.job_id}"><i class="fas fa-user"></i> Full Name *</label>
                        <input type="text" id="app_name_${job.job_id}" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="app_email_${job.job_id}"><i class="fas fa-envelope"></i> Email Address *</label>
                        <input type="email" id="app_email_${job.job_id}" name="email" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="app_phone_${job.job_id}"><i class="fas fa-phone"></i> Phone Number *</label>
                        <input type="tel" id="app_phone_${job.job_id}" name="phone" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="app_portfolio_${job.job_id}"><i class="fas fa-link"></i> Portfolio URL</label>
                        <input type="url" id="app_portfolio_${job.job_id}" name="portfolio" class="form-control" placeholder="https://...">
                    </div>
                    
                    <div class="form-group">
                        <label for="app_linkedin_${job.job_id}"><i class="fab fa-linkedin"></i> LinkedIn URL</label>
                        <input type="url" id="app_linkedin_${job.job_id}" name="linkedin" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="app_resume_${job.job_id}"><i class="fas fa-file-pdf"></i> Upload Resume *</label>
                        <div class="file-upload" onclick="document.getElementById('app_resume_${job.job_id}').click()">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p>Click to upload or drag and drop</p>
                            <small>PDF, DOC, DOCX (Max 5MB)</small>
                            <input type="file" id="app_resume_${job.job_id}" name="resume" accept=".pdf,.doc,.docx" style="display: none;" required onchange="updateFileName(this, 'app-resume-name-${job.job_id}')">
                            <div id="app-resume-name-${job.job_id}" class="file-name"></div>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="app_cover_${job.job_id}"><i class="fas fa-envelope-open-text"></i> Cover Letter</label>
                        <textarea id="app_cover_${job.job_id}" name="cover_letter" class="form-control" rows="5" placeholder="Tell us why you're the perfect candidate for this position..."></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <button type="submit" class="submit-application-btn" id="submitAppBtn_${job.job_id}">
                            <i class="fas fa-paper-plane"></i> Submit Application
                        </button>
                    </div>
                </div>
            </form>
        </div>
    `;
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeJobModal() {
    document.getElementById('jobModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Quick Apply Modal Functions
function openQuickApplyModal() {
    document.getElementById('quickApplyModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeQuickApplyModal() {
    document.getElementById('quickApplyModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    document.getElementById('quickApplyForm').reset();
    document.getElementById('resume-file-name').innerHTML = '';
}

// Form Submission Functions
async function submitJobApplication(event, jobId) {
    event.preventDefault();
    
    const submitBtn = document.getElementById(`submitAppBtn_${jobId}`);
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner"></span> Submitting...';
    submitBtn.disabled = true;
    
    const formData = new FormData(event.target);
    formData.append('job_id', jobId);
    
        try {
            const response = await fetch('<?php echo url('/api/job_applications.php'); ?>', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showNotification('success', result.message || 'Application submitted successfully! We\'ll review your application and contact you soon.');
                closeJobModal();
            } else {
                showNotification('error', result.message || 'Failed to submit application. Please try again.');
            }

        } catch (error) {
            console.error('Error:', error);
            showNotification('error', 'An error occurred. Please try again.');
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
}

async function submitQuickApply(event) {
    event.preventDefault();
    
    const submitBtn = document.getElementById('quickApplyBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner"></span> Submitting...';
    submitBtn.disabled = true;
    
    const formData = new FormData(event.target);
    
    try {
        // Simulate API call
        setTimeout(() => {
            showNotification('success', 'Your resume has been received! We\'ll keep you in mind for future opportunities.');
            closeQuickApplyModal();
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 1500);
        
    } catch (error) {
        console.error('Error:', error);
        showNotification('error', 'An error occurred. Please try again.');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

// Helper Functions
function formatJobType(type) {
    if (!type) return 'Full Time';
    return type.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
}

function formatExperience(level) {
    if (!level) return 'Not Specified';
    const levels = {
        'entry': 'Entry Level',
        'mid': 'Mid Level',
        'senior': 'Senior Level',
        'executive': 'Executive'
    };
    return levels[level] || level;
}

function updateFileName(input, displayId) {
    const fileName = input.files[0] ? input.files[0].name : '';
    document.getElementById(displayId).textContent = fileName;
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

// Close modals when clicking outside
window.onclick = function(event) {
    const jobModal = document.getElementById('jobModal');
    const quickApplyModal = document.getElementById('quickApplyModal');
    
    if (event.target === jobModal) {
        closeJobModal();
    }
    if (event.target === quickApplyModal) {
        closeQuickApplyModal();
    }
}

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeJobModal();
        closeQuickApplyModal();
    }
});

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<!-- Link to careers.css -->
<link rel="stylesheet" href="<?php echo url('/pages/assets/css/careers.css'); ?>">