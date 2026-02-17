<?php
/**
 * Blog Page - Mira Edge Technologies
 * Displays all blog posts with categories, tags, and search
 */

// Check if viewing a single post
$post_id = isset($_GET['post_id']) || isset($_GET['id']) ? (int)($_GET['post_id'] ?? $_GET['id'] ?? 0) : 0;
$slug = isset($_GET['slug']) ? sanitize($_GET['slug']) : '';

// If viewing a single post, include the single post page
if ($post_id > 0) {
    include 'single/post.php';
    exit;
}

// Get current page for pagination
$current_page = isset($_GET['blog_page']) ? (int)$_GET['blog_page'] : 1;
$posts_per_page = 6;
$offset = ($current_page - 1) * $posts_per_page;

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$category_id = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$tag_id = isset($_GET['tag']) ? (int)$_GET['tag'] : 0;

try {
    $db = Database::getInstance()->getConnection();
    
    // Get all blog categories
    $stmt = $db->prepare("
        SELECT * FROM blog_categories 
        WHERE is_active = 1 
        ORDER BY display_order ASC
    ");
    $stmt->execute();
    $blog_categories = $stmt->fetchAll() ?: [];
    
    // Get all blog tags with post counts
    $stmt = $db->prepare("
        SELECT t.*, COUNT(pt.post_id) as post_count
        FROM blog_tags t
        LEFT JOIN blog_post_tags pt ON t.tag_id = pt.tag_id
        GROUP BY t.tag_id
        ORDER BY t.tag_name ASC
    ");
    $stmt->execute();
    $blog_tags = $stmt->fetchAll() ?: [];
    
    // Get recent posts for sidebar
    $stmt = $db->prepare("
        SELECT p.*, c.category_name,
               (SELECT COUNT(*) FROM blog_comments WHERE post_id = p.post_id AND is_approved = 1) as comment_count
        FROM blog_posts p
        LEFT JOIN blog_categories c ON p.blog_category_id = c.blog_category_id
        WHERE p.status = 'published' AND p.published_at <= NOW()
        ORDER BY p.published_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_posts = $stmt->fetchAll() ?: [];
    
    // Build query for posts with filters
    $query = "
        SELECT p.*, 
               c.category_name, c.slug as category_slug,
               CONCAT(u.first_name, ' ', u.last_name) as author_name,
               (SELECT COUNT(*) FROM blog_comments WHERE post_id = p.post_id AND is_approved = 1) as comment_count,
               GROUP_CONCAT(DISTINCT t.tag_name) as tag_names,
               GROUP_CONCAT(DISTINCT t.slug) as tag_slugs
        FROM blog_posts p
        LEFT JOIN blog_categories c ON p.blog_category_id = c.blog_category_id
        LEFT JOIN users u ON p.author_id = u.user_id
        LEFT JOIN blog_post_tags pt ON p.post_id = pt.post_id
        LEFT JOIN blog_tags t ON pt.tag_id = t.tag_id
        WHERE p.status = 'published' AND p.published_at <= NOW()
    ";
    
    $params = [];
    
    // Apply search filter
    if (!empty($search)) {
        $query .= " AND (p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // Apply category filter
    if ($category_id > 0) {
        $query .= " AND p.blog_category_id = ?";
        $params[] = $category_id;
    }
    
    // Apply tag filter
    if ($tag_id > 0) {
        $query .= " AND EXISTS (SELECT 1 FROM blog_post_tags WHERE post_id = p.post_id AND tag_id = ?)";
        $params[] = $tag_id;
    }
    
    // Group by and order
    $query .= " GROUP BY p.post_id ORDER BY p.is_featured DESC, p.published_at DESC";
    
    // Get total count for pagination
    $count_query = str_replace(
        "SELECT p.*, c.category_name, c.slug as category_slug, CONCAT(u.first_name, ' ', u.last_name) as author_name, (SELECT COUNT(*) FROM blog_comments WHERE post_id = p.post_id AND is_approved = 1) as comment_count, GROUP_CONCAT(DISTINCT t.tag_name) as tag_names, GROUP_CONCAT(DISTINCT t.slug) as tag_slugs",
        "SELECT COUNT(DISTINCT p.post_id) as total",
        $query
    );
    
    $stmt = $db->prepare($count_query);
    $stmt->execute($params);
    $total_posts = $stmt->fetch()['total'] ?? 0;
    
    // Add pagination
    $query .= " LIMIT ? OFFSET ?";
    $params[] = $posts_per_page;
    $params[] = $offset;
    
    // Get posts
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $posts = $stmt->fetchAll() ?: [];
    
    // Process tags for each post
    foreach ($posts as &$post) {
        if (!empty($post['tag_names'])) {
            $tag_names = explode(',', $post['tag_names']);
            $tag_slugs = !empty($post['tag_slugs']) ? explode(',', $post['tag_slugs']) : [];
            $post['tags'] = [];
            foreach ($tag_names as $index => $name) {
                $post['tags'][] = [
                    'name' => trim($name),
                    'slug' => isset($tag_slugs[$index]) ? trim($tag_slugs[$index]) : ''
                ];
            }
        } else {
            $post['tags'] = [];
        }
    }
    
    // Calculate pagination
    $total_pages = ceil($total_posts / $posts_per_page);
    
    // Get SEO metadata
    $stmt = $db->prepare("
        SELECT * FROM seo_metadata 
        WHERE page_type = 'blog' OR (page_type = 'custom' AND page_slug = 'blog')
        LIMIT 1
    ");
    $stmt->execute();
    $seo_meta = $stmt->fetch() ?: [];
    
} catch (PDOException $e) {
    error_log("Blog Page Error: " . $e->getMessage());
    $blog_categories = [];
    $blog_tags = [];
    $recent_posts = [];
    $posts = [];
    $total_posts = 0;
    $total_pages = 0;
    $seo_meta = [];
}

// Set page-specific SEO metadata
$page_title = isset($seo_meta['meta_title']) && $seo_meta['meta_title'] ? $seo_meta['meta_title'] : 'Blog | Mira Edge Technologies - Tech Insights & Updates';
$page_description = isset($seo_meta['meta_description']) && $seo_meta['meta_description'] ? $seo_meta['meta_description'] : 'Read our latest articles on web development, mobile apps, digital marketing, and technology trends in Cameroon and Africa.';
$page_keywords = isset($seo_meta['meta_keywords']) && $seo_meta['meta_keywords'] ? $seo_meta['meta_keywords'] : 'tech blog cameroon, web development articles, digital marketing tips, software development insights, africa tech news';
$og_title = isset($seo_meta['og_title']) && $seo_meta['og_title'] ? $seo_meta['og_title'] : 'Mira Edge Technologies Blog - Tech Insights';
$og_description = isset($seo_meta['og_description']) && $seo_meta['og_description'] ? $seo_meta['og_description'] : 'Stay updated with the latest technology trends, tutorials, and insights from Mira Edge Technologies.';
$og_image = isset($seo_meta['og_image']) && $seo_meta['og_image'] ? url($seo_meta['og_image']) : url('/assets/images/blog-hero.jpg');
$canonical_url = isset($seo_meta['canonical_url']) && $seo_meta['canonical_url'] ? $seo_meta['canonical_url'] : url('/?page=blog');

// Fallback posts if database is empty
if (empty($posts) && empty($search) && $category_id == 0 && $tag_id == 0) {
    $posts = [
        [
            'post_id' => 1,
            'title' => 'Getting Started with Web Development in Cameroon',
            'slug' => 'getting-started-web-development-cameroon',
            'excerpt' => 'Learn how to start your web development journey in Cameroon with our comprehensive guide for beginners.',
            'content' => '<p>Web development is a exciting field with endless opportunities. In Cameroon, the tech industry is growing rapidly, and there\'s never been a better time to start learning.</p><h2>Why Learn Web Development?</h2><p>The demand for skilled web developers in Cameroon is higher than ever. From small businesses to large corporations, everyone needs an online presence.</p>',
            'featured_image' => 'https://images.unsplash.com/photo-1498050108023-c5249f4df085?ixlib=rb-4.0.3&auto=format&fit=crop&w=2072&q=80',
            'image_alt' => 'Web Development Coding',
            'published_at' => '2025-02-15 10:00:00',
            'views_count' => 1250,
            'reading_time' => 5,
            'category_name' => 'Technology',
            'author_name' => 'Engr. Nkwagoh Ivor Richard',
            'comment_count' => 3,
            'tags' => [
                ['name' => 'Web Development', 'slug' => 'web-development'],
                ['name' => 'Beginners', 'slug' => 'beginners'],
                ['name' => 'Cameroon', 'slug' => 'cameroon']
            ]
        ],
        [
            'post_id' => 2,
            'title' => 'Why Your Business Needs a Mobile App in 2025',
            'slug' => 'why-business-needs-mobile-app-2025',
            'excerpt' => 'Discover the benefits of having a mobile app for your business and how it can help you reach more customers.',
            'content' => '<p>With over 80% of internet users accessing the web via mobile devices, having a mobile app is no longer optional—it\'s essential.</p><h2>Benefits of Mobile Apps</h2><p>Mobile apps provide a direct channel to your customers, increase engagement, and boost sales.</p>',
            'featured_image' => 'https://images.unsplash.com/photo-1512941937669-90a1b58e7e9c?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80',
            'image_alt' => 'Mobile App Development',
            'published_at' => '2025-02-10 14:30:00',
            'views_count' => 890,
            'reading_time' => 4,
            'category_name' => 'Mobile Apps',
            'author_name' => 'Engr. Ngulefac Terence',
            'comment_count' => 1,
            'tags' => [
                ['name' => 'Mobile Apps', 'slug' => 'mobile-apps'],
                ['name' => 'Business', 'slug' => 'business'],
                ['name' => '2025 Trends', 'slug' => '2025-trends']
            ]
        ],
        [
            'post_id' => 3,
            'title' => 'SEO Best Practices for African Businesses',
            'slug' => 'seo-best-practices-african-businesses',
            'excerpt' => 'Learn how to optimize your website for search engines and reach more customers in Africa.',
            'content' => '<p>Search Engine Optimization (SEO) is crucial for businesses looking to increase their online visibility and attract more customers.</p><h2>Local SEO Strategies</h2><p>For African businesses, focusing on local SEO can make a huge difference in reaching your target audience.</p>',
            'featured_image' => 'https://images.unsplash.com/photo-1557838923-2985c318be48?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80',
            'image_alt' => 'SEO Analytics',
            'published_at' => '2025-02-05 09:15:00',
            'views_count' => 675,
            'reading_time' => 6,
            'category_name' => 'Digital Marketing',
            'author_name' => 'Eng Foncho Afa',
            'comment_count' => 2,
            'tags' => [
                ['name' => 'SEO', 'slug' => 'seo'],
                ['name' => 'Digital Marketing', 'slug' => 'digital-marketing'],
                ['name' => 'Africa', 'slug' => 'africa']
            ]
        ],
        [
            'post_id' => 4,
            'title' => 'Company News: Mira Edge Celebrates First Anniversary',
            'slug' => 'company-news-first-anniversary',
            'excerpt' => 'Join us in celebrating our first year of innovation and growth in Cameroon\'s tech industry.',
            'content' => '<p>It\'s been an incredible journey since we founded Mira Edge Technologies in November 2024. We\'re proud of what we\'ve achieved and excited for the future.</p><h2>A Year of Growth</h2><p>From 4 passionate technologists to a growing team serving clients across Cameroon and beyond.</p>',
            'featured_image' => 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?ixlib=rb-4.0.3&auto=format&fit=crop&w=1170&q=80',
            'image_alt' => 'Team Celebration',
            'published_at' => '2025-01-20 11:00:00',
            'views_count' => 2100,
            'reading_time' => 3,
            'category_name' => 'Company News',
            'author_name' => 'Engr. Nkwagoh Ivor Richard',
            'comment_count' => 5,
            'tags' => [
                ['name' => 'Company News', 'slug' => 'company-news'],
                ['name' => 'Anniversary', 'slug' => 'anniversary'],
                ['name' => 'Milestone', 'slug' => 'milestone']
            ]
        ],
        [
            'post_id' => 5,
            'title' => 'Understanding Artificial Intelligence for Beginners',
            'slug' => 'understanding-artificial-intelligence-beginners',
            'excerpt' => 'A simple introduction to artificial intelligence and how it\'s changing the world around us.',
            'content' => '<p>Artificial Intelligence (AI) is transforming industries and our daily lives. But what exactly is AI, and how does it work?</p><h2>What is AI?</h2><p>AI refers to machines that can perform tasks that typically require human intelligence.</p>',
            'featured_image' => 'https://images.unsplash.com/photo-1485827404703-89b55fcc595e?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80',
            'image_alt' => 'Artificial Intelligence',
            'published_at' => '2025-01-12 16:45:00',
            'views_count' => 1540,
            'reading_time' => 7,
            'category_name' => 'Technology',
            'author_name' => 'Engr. Liman Zarah',
            'comment_count' => 4,
            'tags' => [
                ['name' => 'AI', 'slug' => 'ai'],
                ['name' => 'Technology', 'slug' => 'technology'],
                ['name' => 'Beginners', 'slug' => 'beginners']
            ]
        ],
        [
            'post_id' => 6,
            'title' => 'How to Choose the Right Tech Partner for Your Business',
            'slug' => 'choose-right-tech-partner-business',
            'excerpt' => 'Key factors to consider when selecting a technology partner for your business projects.',
            'content' => '<p>Choosing the right technology partner can make or break your digital transformation journey. Here\'s what to look for.</p><h2>Experience and Expertise</h2><p>Look for a partner with proven experience in your industry and the technologies you need.</p>',
            'featured_image' => 'https://images.unsplash.com/photo-1552664730-d307ca884978?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80',
            'image_alt' => 'Business Partnership',
            'published_at' => '2025-01-05 08:30:00',
            'views_count' => 980,
            'reading_time' => 5,
            'category_name' => 'Business',
            'author_name' => 'Engr. Nkwagoh Ivor Richard',
            'comment_count' => 2,
            'tags' => [
                ['name' => 'Business', 'slug' => 'business'],
                ['name' => 'Partnership', 'slug' => 'partnership'],
                ['name' => 'Advice', 'slug' => 'advice']
            ]
        ]
    ];
    
    $total_posts = count($posts);
    $total_pages = 1;
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

<!-- JSON-LD Schema Markup for Blog -->
<?php if (!empty($posts)): ?>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Blog",
    "name": "Mira Edge Technologies Blog",
    "description": "<?php echo e($page_description); ?>",
    "url": "<?php echo e($canonical_url); ?>",
    "blogPost": [
        <?php foreach ($posts as $index => $post): ?>
        {
            "@type": "BlogPosting",
            "headline": "<?php echo e($post['title']); ?>",
            "description": "<?php echo e($post['excerpt']); ?>",
            "image": "<?php echo !empty($post['featured_image']) ? (strpos($post['featured_image'], 'http') === 0 ? $post['featured_image'] : url($post['featured_image'])) : ''; ?>",
            "datePublished": "<?php echo e($post['published_at'] ?? date('Y-m-d')); ?>",
            "author": {
                "@type": "Person",
                "name": "<?php echo e($post['author_name'] ?? 'Mira Edge Technologies'); ?>"
            }
        }<?php echo $index < count($posts) - 1 ? ',' : ''; ?>
        <?php endforeach; ?>
    ]
}
</script>
<?php endif; ?>

<!-- Blog Hero Section -->
<section class="blog-hero">
    <div class="container">
        <div class="blog-hero-content">
            <h1 class="animate-up">Our Blog</h1>
            <p class="animate-up" style="animation-delay: 0.2s;">Insights, tutorials, and updates from the Mira Edge team</p>
        </div>
    </div>
</section>

<!-- Blog Layout -->
<section class="blog-layout">
    <div class="container">
        <div class="blog-container">
            <!-- Main Content - Blog Posts -->
            <div class="blog-main">
                <?php if (!empty($posts)): ?>
                    <div class="blog-posts-grid">
                        <?php foreach ($posts as $index => $post): ?>
                        <article class="blog-post-card animate-up" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                            <div class="blog-post-image">
                                <img src="<?php 
                                    if (!empty($post['featured_image'])) {
                                        echo strpos($post['featured_image'], 'http') === 0 ? $post['featured_image'] : url($post['featured_image']);
                                    } else {
                                        echo 'https://images.unsplash.com/photo-1499750310107-5fef28a66643?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80';
                                    }
                                ?>" alt="<?php echo e($post['image_alt'] ?? $post['title']); ?>">
                                <?php if (!empty($post['category_name'])): ?>
                                <span class="blog-post-category"><?php echo e($post['category_name']); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="blog-post-content">
                                <div class="blog-post-meta">
                                    <span><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($post['published_at'] ?? 'now')); ?></span>
                                    <span><i class="far fa-user"></i> <?php echo e($post['author_name'] ?? 'Mira Edge'); ?></span>
                                </div>
                                
                                <h2><a href="<?php echo url('/?page=blog&post_id=' . $post['post_id'] . '&slug=' . $post['slug']); ?>"><?php echo e($post['title']); ?></a></h2>
                                
                                <p class="blog-post-excerpt"><?php echo e($post['excerpt']); ?></p>
                                
                                <?php if (!empty($post['tags'])): ?>
                                <div class="portfolio-tech-stack" style="margin-bottom: 15px;">
                                    <?php foreach (array_slice($post['tags'], 0, 3) as $tag): ?>
                                    <a href="<?php echo url('/?page=blog&tag=' . $tag['slug']); ?>" class="tech-tag">#<?php echo e($tag['name']); ?></a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="blog-post-footer">
                                    <a href="<?php echo url('/?page=blog&post_id=' . $post['post_id'] . '&slug=' . $post['slug']); ?>" class="read-more-btn">
                                        Read More <i class="fas fa-arrow-right"></i>
                                    </a>
                                    
                                    <div class="post-stats">
                                        <span><i class="far fa-eye"></i> <?php echo number_format($post['views_count'] ?? 0); ?></span>
                                        <span><i class="far fa-comment"></i> <?php echo $post['comment_count'] ?? 0; ?></span>
                                        <span><i class="far fa-clock"></i> <?php echo $post['reading_time'] ?? 5; ?> min read</span>
                                    </div>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($current_page > 1): ?>
                        <a href="<?php echo url('/?page=blog&blog_page=' . ($current_page - 1) . ($category_id ? '&category=' . $category_id : '') . ($tag_id ? '&tag=' . $tag_id : '') . (!empty($search) ? '&search=' . urlencode($search) : '')); ?>" class="page-link prev">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="<?php echo url('/?page=blog&blog_page=' . $i . ($category_id ? '&category=' . $category_id : '') . ($tag_id ? '&tag=' . $tag_id : '') . (!empty($search) ? '&search=' . urlencode($search) : '')); ?>" 
                           class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <?php if ($current_page < $total_pages): ?>
                        <a href="<?php echo url('/?page=blog&blog_page=' . ($current_page + 1) . ($category_id ? '&category=' . $category_id : '') . ($tag_id ? '&tag=' . $tag_id : '') . (!empty($search) ? '&search=' . urlencode($search) : '')); ?>" class="page-link next">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                <div class="no-posts">
                    <i class="fas fa-newspaper"></i>
                    <h3>No Posts Found</h3>
                    <p><?php 
                        if (!empty($search)) {
                            echo "No posts matching your search '" . e($search) . "' were found.";
                        } elseif ($category_id) {
                            echo "No posts in this category yet.";
                        } elseif ($tag_id) {
                            echo "No posts with this tag yet.";
                        } else {
                            echo "Check back soon for new articles!";
                        }
                    ?></p>
                    <a href="<?php echo url('/?page=blog'); ?>" class="btn">View All Posts</a>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar -->
            <aside class="blog-sidebar">
                <!-- Search Widget -->
                <div class="sidebar-widget animate-up">
                    <h3 class="widget-title">Search</h3>
                    <form action="<?php echo url('/?page=blog'); ?>" method="GET" class="search-form">
                        <input type="hidden" name="page" value="blog">
                        <input type="text" name="search" class="search-input" placeholder="Search articles..." value="<?php echo e($search); ?>">
                        <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
                    </form>
                </div>
                
                <!-- Categories Widget -->
                <?php if (!empty($blog_categories)): ?>
                <div class="sidebar-widget animate-up" style="animation-delay: 0.1s;">
                    <h3 class="widget-title">Categories</h3>
                    <ul class="categories-list">
                        <?php foreach ($blog_categories as $cat): ?>
                        <?php
                            // Count posts in this category
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM blog_posts WHERE blog_category_id = ? AND status = 'published' AND published_at <= NOW()");
                            $stmt->execute([$cat['blog_category_id']]);
                            $cat_count = $stmt->fetch()['count'] ?? 0;
                        ?>
                        <li>
                            <a href="<?php echo url('/?page=blog&category=' . $cat['blog_category_id']); ?>">
                                <?php echo e($cat['category_name']); ?>
                                <span class="category-count"><?php echo $cat_count; ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Recent Posts Widget -->
                <?php if (!empty($recent_posts)): ?>
                <div class="sidebar-widget animate-up" style="animation-delay: 0.2s;">
                    <h3 class="widget-title">Recent Posts</h3>
                    <ul class="recent-posts-list">
                        <?php foreach ($recent_posts as $recent): ?>
                        <li class="recent-post-item">
                            <div class="recent-post-image">
                                <img src="<?php 
                                    if (!empty($recent['featured_image'])) {
                                        echo strpos($recent['featured_image'], 'http') === 0 ? $recent['featured_image'] : url($recent['featured_image']);
                                    } else {
                                        echo 'https://images.unsplash.com/photo-1499750310107-5fef28a66643?ixlib=rb-4.0.3&auto=format&fit=crop&w=100&q=80';
                                    }
                                ?>" alt="<?php echo e($recent['title']); ?>">
                            </div>
                            <div class="recent-post-content">
                                <h4><a href="<?php echo url('/?page=blog&action=view&id=' . $recent['post_id'] . '&slug=' . $recent['slug']); ?>"><?php echo e(substr($recent['title'], 0, 40)) . (strlen($recent['title']) > 40 ? '...' : ''); ?></a></h4>
                                <div class="recent-post-date">
                                    <i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($recent['published_at'] ?? 'now')); ?>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Tags Widget -->
                <?php if (!empty($blog_tags)): ?>
                <div class="sidebar-widget animate-up" style="animation-delay: 0.3s;">
                    <h3 class="widget-title">Popular Tags</h3>
                    <div class="tags-cloud">
                        <?php foreach ($blog_tags as $tag): ?>
                        <a href="<?php echo url('/?page=blog&tag=' . $tag['tag_id']); ?>" class="tag">
                            #<?php echo e($tag['tag_name']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Newsletter Widget -->
                <div class="sidebar-widget animate-up" style="animation-delay: 0.4s;">
                    <h3 class="widget-title">Newsletter</h3>
                    <p style="margin-bottom: 15px; color: var(--dark-gray);">Subscribe to get the latest posts in your inbox.</p>
                    <form class="newsletter-form" style="margin-top: 0;">
                        <input type="email" placeholder="Your Email" required style="border-radius: 5px 0 0 5px;">
                        <button type="submit" style="border-radius: 0 5px 5px 0;"><i class="fas fa-paper-plane"></i></button>
                    </form>
                </div>
            </aside>
        </div>
    </div>
</section>

<!-- Link to blog.css -->
<link rel="stylesheet" href="<?php echo url('/pages/assets/css/blog.css'); ?>">