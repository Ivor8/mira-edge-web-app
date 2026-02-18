<?php
/**
 * Portfolio Page - Mira Edge Technologies
 * Displays all portfolio projects with filtering and detailed views
 */

// Get data from database
try {
    $db = Database::getInstance()->getConnection();
    
    // Get all portfolio categories
    $stmt = $db->prepare("
        SELECT * FROM portfolio_categories 
        WHERE is_active = 1 
        ORDER BY display_order ASC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll() ?: [];
    
    // Get all portfolio projects with their categories and technologies
    $stmt = $db->prepare("
        SELECT p.*, pc.category_name, pc.slug as category_slug,
               GROUP_CONCAT(pt.technology_name) as technologies,
               GROUP_CONCAT(pt.icon_class) as tech_icons
        FROM portfolio_projects p
        LEFT JOIN portfolio_categories pc ON p.category_id = pc.category_id
        LEFT JOIN project_technologies pt ON p.project_id = pt.project_id
        WHERE p.status != 'upcoming'
        GROUP BY p.project_id
        ORDER BY p.is_featured DESC, p.display_order ASC, p.created_at DESC
    ");
    $stmt->execute();
    $projects = $stmt->fetchAll() ?: [];
    
    // Get featured project (first featured project)
    $featured_project = null;
    foreach ($projects as $project) {
        if ($project['is_featured'] == 1) {
            $featured_project = $project;
            break;
        }
    }
    
    // Get project images for each project
    foreach ($projects as &$project) {
        $stmt = $db->prepare("
            SELECT * FROM project_images 
            WHERE project_id = ? 
            ORDER BY display_order ASC
        ");
        $stmt->execute([$project['project_id']]);
        $project['images'] = $stmt->fetchAll() ?: [];
    }
    
    // Get SEO metadata for portfolio page
    $stmt = $db->prepare("
        SELECT * FROM seo_metadata 
        WHERE page_type = 'portfolio' OR (page_type = 'custom' AND page_slug = 'portfolio')
        LIMIT 1
    ");
    $stmt->execute();
    $seo_meta = $stmt->fetch() ?: [];
    
} catch (PDOException $e) {
    error_log("Portfolio Page Error: " . $e->getMessage());
    $categories = [];
    $projects = [];
    $featured_project = null;
    $seo_meta = [];
}

// Set page-specific SEO metadata
$page_title = isset($seo_meta['meta_title']) && $seo_meta['meta_title'] ? $seo_meta['meta_title'] : 'Our Portfolio | Mira Edge Technologies - Showcasing Our Best Work';
$page_description = isset($seo_meta['meta_description']) && $seo_meta['meta_description'] ? $seo_meta['meta_description'] : 'Explore our portfolio of successful projects including web applications, mobile apps, e-commerce solutions, and custom software development for clients across Africa.';
$page_keywords = isset($seo_meta['meta_keywords']) && $seo_meta['meta_keywords'] ? $seo_meta['meta_keywords'] : 'portfolio, web development projects, mobile apps portfolio, software projects cameroon, tech portfolio africa, successful projects, case studies';
$og_title = isset($seo_meta['og_title']) && $seo_meta['og_title'] ? $seo_meta['og_title'] : 'Mira Edge Technologies Portfolio - Success Stories';
$og_description = isset($seo_meta['og_description']) && $seo_meta['og_description'] ? $seo_meta['og_description'] : 'Browse through our collection of successful projects and see how we\'ve helped businesses transform through technology.';
$og_image = isset($seo_meta['og_image']) && $seo_meta['og_image'] ? url($seo_meta['og_image']) : url('/assets/images/portfolio-hero.jpg');
$canonical_url = isset($seo_meta['canonical_url']) && $seo_meta['canonical_url'] ? $seo_meta['canonical_url'] : url('/?page=portfolio');

// Fallback projects if database is empty
if (empty($projects)) {
    $projects = [
        [
            'project_id' => 1,
            'title' => 'SPOTFLI Mobile App',
            'slug' => 'spotfli-mobile-app',
            'short_description' => 'A revolutionary mobile application for music streaming and discovery.',
            'full_description' => 'SPOTFLI is a cutting-edge music streaming platform designed for African audiences. The app features personalized playlists, offline listening, and social sharing capabilities. Built with React Native for cross-platform compatibility, it delivers a seamless experience on both iOS and Android devices.',
            'client_name' => 'Mr Pitu',
            'project_url' => 'https://www.project.com',
            'github_url' => 'https://github.com',
            'completion_date' => '2025-02-10',
            'category_id' => 2,
            'category_name' => 'Mobile Applications',
            'featured_image' => '/assets/uploads/projects/6988bdfd6e811_1770569213.jpeg',
            'status' => 'completed',
            'is_featured' => 1,
            'technologies' => 'React Native,Node.js,MongoDB,Firebase',
            'images' => [
                ['image_url' => '/assets/uploads/projects/gallery/6988bdfd7096b_1770569213_0.jpeg', 'alt_text' => 'SPOTFLI Mobile App Screen 1']
            ]
        ],
        [
            'project_id' => 2,
            'title' => 'E-Commerce Platform for African Artisans',
            'slug' => 'ecommerce-platform-artisans',
            'short_description' => 'Online marketplace connecting local artisans with global customers.',
            'full_description' => 'A comprehensive e-commerce platform that enables African artisans to showcase and sell their products worldwide. Features include multi-vendor support, secure payment processing, and integrated shipping solutions.',
            'client_name' => 'Artisan Connect',
            'project_url' => 'https://example.com',
            'completion_date' => '2025-01-15',
            'category_id' => 1,
            'category_name' => 'E-commerce',
            'featured_image' => 'https://images.unsplash.com/photo-1557821552-17105176677c?ixlib=rb-4.0.3&auto=format&fit=crop&w=2089&q=80',
            'status' => 'completed',
            'is_featured' => 0,
            'technologies' => 'Laravel,Vue.js,MySQL,Stripe',
            'images' => []
        ],
        [
            'project_id' => 3,
            'title' => 'Hospital Management System',
            'slug' => 'hospital-management-system',
            'short_description' => 'Complete digital solution for healthcare facility management.',
            'full_description' => 'A robust hospital management system that streamlines patient records, appointment scheduling, billing, and inventory management. Built with security and scalability in mind.',
            'client_name' => 'Yaounde General Hospital',
            'project_url' => 'https://example.com',
            'completion_date' => '2024-12-20',
            'category_id' => 4,
            'category_name' => 'Web Applications',
            'featured_image' => 'https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80',
            'status' => 'completed',
            'is_featured' => 0,
            'technologies' => 'PHP,MySQL,Bootstrap,jQuery',
            'images' => []
        ],
        [
            'project_id' => 4,
            'title' => 'Real Estate Listing Platform',
            'slug' => 'real-estate-listing-platform',
            'short_description' => 'Modern property listing website with advanced search features.',
            'full_description' => 'A feature-rich real estate platform that allows agents to list properties and buyers to search with advanced filters including location, price range, and property type.',
            'client_name' => 'Cameroon Properties',
            'project_url' => 'https://example.com',
            'completion_date' => '2024-11-05',
            'category_id' => 2,
            'category_name' => 'Business Websites',
            'featured_image' => 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?ixlib=rb-4.0.3&auto=format&fit=crop&w=1973&q=80',
            'status' => 'completed',
            'is_featured' => 0,
            'technologies' => 'WordPress,Elementor,ACF',
            'images' => []
        ]
    ];
    
    // Set featured project
    $featured_project = $projects[0];
}

// Process technologies for each project
foreach ($projects as &$project) {
    if (!empty($project['technologies'])) {
        $tech_names = explode(',', $project['technologies']);
        $tech_icons = !empty($project['tech_icons']) ? explode(',', $project['tech_icons']) : [];
        $project['tech_list'] = [];
        
        foreach ($tech_names as $index => $name) {
            $project['tech_list'][] = [
                'name' => trim($name),
                'icon' => isset($tech_icons[$index]) ? trim($tech_icons[$index]) : ''
            ];
        }
    } else {
        $project['tech_list'] = [];
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

<!-- JSON-LD Schema Markup for Portfolio -->
<?php if (!empty($projects)): ?>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "ItemList",
    "itemListElement": [
        <?php foreach ($projects as $index => $project): ?>
        {
            "@type": "ListItem",
            "position": <?php echo $index + 1; ?>,
            "item": {
                "@type": "CreativeWork",
                "name": "<?php echo e($project['title']); ?>",
                "description": "<?php echo e($project['short_description']); ?>",
                "creator": {
                    "@type": "Organization",
                    "name": "Mira Edge Technologies"
                },
                "dateCreated": "<?php echo e($project['completion_date'] ?? $project['created_at'] ?? date('Y-m-d')); ?>"
            }
        }<?php echo $index < count($projects) - 1 ? ',' : ''; ?>
        <?php endforeach; ?>
    ]
}
</script>
<?php endif; ?>

<!-- BreadcrumbList Schema JSON-LD -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [
        {
            "@type": "ListItem",
            "position": 1,
            "name": "Home",
            "item": "<?php echo url('/?page=home'); ?>"
        },
        {
            "@type": "ListItem",
            "position": 2,
            "name": "Portfolio",
            "item": "<?php echo url('/?page=portfolio'); ?>"
        }
    ]
}
</script>

<!-- Portfolio Hero Section -->
<section class="portfolio-hero">
    <div class="container">
        <br><br><br><br>
        <div class="portfolio-hero-content">
            <h1 class="animate-up">Our Portfolio</h1>
            <p class="animate-up" style="animation-delay: 0.2s;">Explore our successful projects and see how we've helped businesses transform through technology</p>
        </div>
    </div>
</section>

<!-- Featured Project Section (if exists) -->
<?php if ($featured_project): ?>
<section class="featured-project">
    <div class="container">
        <div class="featured-container animate-up">
            <div class="featured-image">
                <img src="<?php echo !empty($featured_project['featured_image']) ? url($featured_project['featured_image']) : 'https://images.unsplash.com/photo-1467232004584-a241de8bcf5d?ixlib=rb-4.0.3&auto=format&fit=crop&w=2069&q=80'; ?>" 
                     alt="<?php echo e($featured_project['title']); ?>">
            </div>
            <div class="featured-content">
                <span class="featured-badge">Featured Project</span>
                <h2><?php echo e($featured_project['title']); ?></h2>
                <p><?php echo e($featured_project['short_description']); ?></p>
                
                <div class="featured-details">
                    <?php if (!empty($featured_project['client_name'])): ?>
                    <div class="featured-detail-item">
                        <i class="fas fa-user-tie"></i>
                        <h4>Client</h4>
                        <p><?php echo e($featured_project['client_name']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($featured_project['completion_date'])): ?>
                    <div class="featured-detail-item">
                        <i class="fas fa-calendar-alt"></i>
                        <h4>Completed</h4>
                        <p><?php echo date('M Y', strtotime($featured_project['completion_date'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="featured-detail-item">
                        <i class="fas fa-tag"></i>
                        <h4>Category</h4>
                        <p><?php echo e($featured_project['category_name'] ?? 'Web Development'); ?></p>
                    </div>
                </div>
                
                <?php if (!empty($featured_project['tech_list'])): ?>
                <div class="featured-tech">
                    <?php foreach (array_slice($featured_project['tech_list'], 0, 4) as $tech): ?>
                    <span class="tech-tag"><?php echo e($tech['name']); ?></span>
                    <?php endforeach; ?>
                    <?php if (count($featured_project['tech_list']) > 4): ?>
                    <span class="tech-tag">+<?php echo count($featured_project['tech_list']) - 4; ?> more</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <button class="btn" onclick="openProjectModal(<?php echo htmlspecialchars(json_encode($featured_project), ENT_QUOTES, 'UTF-8'); ?>)">
                    <i class="fas fa-eye"></i> View Project Details
                </button>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Portfolio Filter Section -->
<section class="portfolio-filter">
    <div class="container">
        <div class="filter-container animate-up">
            <button class="filter-btn active" data-filter="all">All Projects</button>
            <?php if (!empty($categories)): ?>
                <?php foreach ($categories as $category): ?>
                <button class="filter-btn" data-filter="<?php echo e($category['slug']); ?>">
                    <?php echo e($category['category_name']); ?>
                </button>
                <?php endforeach; ?>
            <?php else: ?>
                <button class="filter-btn" data-filter="e-commerce">E-commerce</button>
                <button class="filter-btn" data-filter="business-websites">Business Websites</button>
                <button class="filter-btn" data-filter="mobile-applications">Mobile Apps</button>
                <button class="filter-btn" data-filter="web-applications">Web Apps</button>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Portfolio Grid Section -->
<section class="portfolio-grid-section">
    <div class="container">
        <div class="portfolio-grid" id="portfolio-grid">
            <?php foreach ($projects as $index => $project): ?>
            <?php 
                // Skip featured project from grid if it's shown in featured section
                if ($featured_project && $project['project_id'] == $featured_project['project_id']) {
                    continue;
                }
                
                // Determine category slug for filtering
                $category_slug = '';
                if (!empty($project['category_slug'])) {
                    $category_slug = $project['category_slug'];
                } elseif (!empty($project['category_name'])) {
                    $category_slug = strtolower(str_replace(' ', '-', $project['category_name']));
                }
            ?>
            <div class="portfolio-item" 
                 data-category="<?php echo e($category_slug); ?>"
                 data-project-id="<?php echo $project['project_id']; ?>"
                 onclick="openProjectModal(<?php echo htmlspecialchars(json_encode($project), ENT_QUOTES, 'UTF-8'); ?>)"
                 style="animation-delay: <?php echo $index * 0.1; ?>s;">
                
                <div class="portfolio-image">
                    <img src="<?php echo !empty($project['featured_image']) ? url($project['featured_image']) : 'https://images.unsplash.com/photo-1498050108023-c5249f4df085?ixlib=rb-4.0.3&auto=format&fit=crop&w=2072&q=80'; ?>" 
                         alt="<?php echo e($project['title']); ?>">
                    
                    <div class="portfolio-overlay">
                        <div class="portfolio-overlay-content">
                            <h3><?php echo e($project['title']); ?></h3>
                            <p><?php echo e($project['short_description']); ?></p>
                            <span class="portfolio-category-badge">
                                <?php echo e($project['category_name'] ?? 'Web Development'); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="portfolio-info">
                    <h3><?php echo e($project['title']); ?></h3>
                    <p><?php echo e(substr($project['short_description'], 0, 80)) . (strlen($project['short_description']) > 80 ? '...' : ''); ?></p>
                    
                    <?php if (!empty($project['tech_list'])): ?>
                    <div class="portfolio-tech-stack">
                        <?php foreach (array_slice($project['tech_list'], 0, 3) as $tech): ?>
                        <span class="tech-tag"><?php echo e($tech['name']); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="portfolio-meta">
                        <span><i class="far fa-calendar-alt"></i> <?php echo !empty($project['completion_date']) ? date('M Y', strtotime($project['completion_date'])) : '2025'; ?></span>
                        <span><i class="far fa-eye"></i> <?php echo $project['views_count'] ?? 0; ?> views</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (empty($projects)): ?>
        <div class="no-projects animate-up">
            <i class="fas fa-folder-open"></i>
            <h3>No Projects Yet</h3>
            <p>We're currently working on some exciting projects. Check back soon to see our portfolio!</p>
            <a href="<?php echo url('/?page=contact'); ?>" class="btn">Contact Us</a>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Project Detail Modal -->
<div id="projectModal" class="project-modal" style="display: none;">
    <div class="project-modal-content">
        <button class="project-modal-close" onclick="closeProjectModal()">&times;</button>
        <div class="project-modal-header" id="projectModalHeader">
            <img src="" alt="" id="projectModalImage">
            <div class="project-modal-header-overlay">
                <h2 id="projectModalTitle"></h2>
                <p id="projectModalCategory"></p>
            </div>
        </div>
        
        <div class="project-modal-body" id="projectModalBody">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<!-- Portfolio Page JavaScript -->
<script>
// Filter functionality
document.addEventListener('DOMContentLoaded', function() {
    const filterBtns = document.querySelectorAll('.filter-btn');
    const portfolioItems = document.querySelectorAll('.portfolio-item');
    
    if (filterBtns.length > 0) {
        filterBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active button
                filterBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const filterValue = this.getAttribute('data-filter');
                
                // Filter items
                portfolioItems.forEach(item => {
                    if (filterValue === 'all' || item.getAttribute('data-category') === filterValue) {
                        item.style.display = 'block';
                        setTimeout(() => {
                            item.style.opacity = '1';
                            item.style.transform = 'translateY(0)';
                        }, 50);
                    } else {
                        item.style.opacity = '0';
                        item.style.transform = 'translateY(20px)';
                        setTimeout(() => {
                            item.style.display = 'none';
                        }, 300);
                    }
                });
            });
        });
    }
});

// Project Modal Functions
function openProjectModal(project) {
    const modal = document.getElementById('projectModal');
    const modalImage = document.getElementById('projectModalImage');
    const modalTitle = document.getElementById('projectModalTitle');
    const modalCategory = document.getElementById('projectModalCategory');
    const modalBody = document.getElementById('projectModalBody');
    
    // Set header content
    modalImage.src = project.featured_image ? 
        (project.featured_image.startsWith('http') ? project.featured_image : '<?php echo url('/'); ?>' + project.featured_image) : 
        'https://images.unsplash.com/photo-1498050108023-c5249f4df085?ixlib=rb-4.0.3&auto=format&fit=crop&w=2072&q=80';
    modalImage.alt = project.title || 'Project Image';
    modalTitle.textContent = project.title || 'Project Title';
    modalCategory.textContent = project.category_name || 'Web Development';
    
    // Process technologies
    let techHTML = '';
    if (project.tech_list && project.tech_list.length > 0) {
        techHTML = project.tech_list.map(tech => 
            `<span class="tech-tag">${escapeHtml(tech.name)}</span>`
        ).join('');
    } else if (project.technologies) {
        const techs = project.technologies.split(',');
        techHTML = techs.map(tech => 
            `<span class="tech-tag">${escapeHtml(tech.trim())}</span>`
        ).join('');
    }
    
    // Process gallery images
    let galleryHTML = '';
    if (project.images && project.images.length > 0) {
        galleryHTML = `
            <div class="project-modal-section">
                <h3>Project Gallery</h3>
                <div class="project-gallery">
                    ${project.images.map(img => `
                        <div class="gallery-image" onclick="openImageModal('${img.image_url.startsWith('http') ? img.image_url : '<?php echo url('/'); ?>' + img.image_url}')">
                            <img src="${img.image_url.startsWith('http') ? img.image_url : '<?php echo url('/'); ?>' + img.image_url}" 
                                 alt="${escapeHtml(img.alt_text || 'Project image')}">
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    // Build modal body
    modalBody.innerHTML = `
        <div class="project-modal-section">
            <h3>Project Overview</h3>
            <p>${escapeHtml(project.full_description || project.short_description || 'No description available.')}</p>
        </div>
        
        <div class="project-modal-section">
            <h3>Project Details</h3>
            <div class="project-details-grid">
                ${project.client_name ? `
                <div class="detail-item">
                    <i class="fas fa-user-tie"></i>
                    <div>
                        <h4>Client</h4>
                        <p>${escapeHtml(project.client_name)}</p>
                    </div>
                </div>
                ` : ''}
                
                ${project.completion_date ? `
                <div class="detail-item">
                    <i class="fas fa-calendar-check"></i>
                    <div>
                        <h4>Completion Date</h4>
                        <p>${new Date(project.completion_date).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}</p>
                    </div>
                </div>
                ` : ''}
                
                <div class="detail-item">
                    <i class="fas fa-tag"></i>
                    <div>
                        <h4>Category</h4>
                        <p>${escapeHtml(project.category_name || 'Web Development')}</p>
                    </div>
                </div>
                
                ${project.views_count ? `
                <div class="detail-item">
                    <i class="fas fa-eye"></i>
                    <div>
                        <h4>Views</h4>
                        <p>${project.views_count}</p>
                    </div>
                </div>
                ` : ''}
            </div>
        </div>
        
        <div class="project-modal-section">
            <h3>Technologies Used</h3>
            <div class="featured-tech">
                ${techHTML || '<p>No technology information available.</p>'}
            </div>
        </div>
        
        ${galleryHTML}
        
        <div class="project-modal-section">
            <h3>Project Links</h3>
            <div class="project-links">
                ${project.project_url ? `
                <a href="${escapeHtml(project.project_url)}" target="_blank" class="project-link-btn">
                    <i class="fas fa-external-link-alt"></i> View Live Project
                </a>
                ` : ''}
                
                ${project.github_url ? `
                <a href="${escapeHtml(project.github_url)}" target="_blank" class="project-link-btn">
                    <i class="fab fa-github"></i> View Source Code
                </a>
                ` : ''}
            </div>
        </div>
    `;
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeProjectModal() {
    document.getElementById('projectModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Image Modal for Gallery
function openImageModal(imageUrl) {
    const modal = document.createElement('div');
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.9);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    `;
    
    const img = document.createElement('img');
    img.src = imageUrl;
    img.style.cssText = `
        max-width: 90%;
        max-height: 90%;
        object-fit: contain;
        border-radius: 5px;
    `;
    
    modal.appendChild(img);
    modal.onclick = function() {
        document.body.removeChild(modal);
    };
    
    document.body.appendChild(modal);
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('projectModal');
    if (event.target === modal) {
        closeProjectModal();
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeProjectModal();
    }
});

// Increment view count when project is viewed
function incrementViewCount(projectId) {
    // You can implement this with an AJAX call to track views
    console.log('Project viewed:', projectId);
}
</script>

<!-- Link to portfolio.css -->
<link rel="stylesheet" href="<?php echo url('/pages/assets/css/portfolio.css'); ?>">