<?php
/**
 * Services Page - Mira Edge Technologies
 * Displays all services and packages with ordering functionality
 */

// Get data from database
try {
    $db = Database::getInstance()->getConnection();
    
    // Get all service categories
    $stmt = $db->prepare("
        SELECT * FROM service_categories 
        WHERE is_active = 1 
        ORDER BY display_order ASC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll() ?: [];
    
    // Get all services with their categories
    $stmt = $db->prepare("
        SELECT s.*, sc.category_name, sc.icon_class as category_icon
        FROM services s
        LEFT JOIN service_categories sc ON s.service_category_id = sc.service_category_id
        WHERE s.is_active = 1 
        ORDER BY s.is_popular DESC, s.display_order ASC
    ");
    $stmt->execute();
    $services = $stmt->fetchAll() ?: [];
    
    // Get all packages with their features
    $stmt = $db->prepare("
        SELECT sp.*, s.service_name, s.slug as service_slug
        FROM service_packages sp
        LEFT JOIN services s ON sp.service_id = s.service_id
        WHERE s.is_active = 1 OR s.is_active IS NULL
        ORDER BY s.display_order ASC, sp.display_order ASC
    ");
    $stmt->execute();
    $packages = $stmt->fetchAll() ?: [];
    
    // Get features for each package
    foreach ($packages as &$package) {
        $stmt = $db->prepare("
            SELECT * FROM package_features 
            WHERE package_id = ? 
            ORDER BY display_order ASC
        ");
        $stmt->execute([$package['package_id']]);
        $package['features'] = $stmt->fetchAll() ?: [];
    }
    
    // Get SEO metadata for services page
    $stmt = $db->prepare("
        SELECT * FROM seo_metadata 
        WHERE page_type = 'services' OR (page_type = 'custom' AND page_slug = 'services')
        LIMIT 1
    ");
    $stmt->execute();
    $seo_meta = $stmt->fetch() ?: [];
    
} catch (PDOException $e) {
    error_log("Services Page Error: " . $e->getMessage());
    $categories = [];
    $services = [];
    $packages = [];
    $seo_meta = [];
}

// Set page-specific SEO metadata
$page_title = isset($seo_meta['meta_title']) && $seo_meta['meta_title'] ? $seo_meta['meta_title'] : 'Our Services | Mira Edge Technologies - Web, Mobile & Digital Solutions';
$page_description = isset($seo_meta['meta_description']) && $seo_meta['meta_description'] ? $seo_meta['meta_description'] : 'Explore our comprehensive tech services including web development, mobile apps, digital marketing, and custom software solutions. Transform your business with Mira Edge Technologies.';
$page_keywords = isset($seo_meta['meta_keywords']) && $seo_meta['meta_keywords'] ? $seo_meta['meta_keywords'] : 'web development cameroon, mobile app development, digital marketing services, software development company, tech solutions africa, custom software cameroon';
$og_title = isset($seo_meta['og_title']) && $seo_meta['og_title'] ? $seo_meta['og_title'] : 'Mira Edge Technologies Services - Digital Solutions for Your Business';
$og_description = isset($seo_meta['og_description']) && $seo_meta['og_description'] ? $seo_meta['og_description'] : 'Discover our range of technology services designed to help your business grow and succeed in the digital age.';
$og_image = isset($seo_meta['og_image']) && $seo_meta['og_image'] ? url($seo_meta['og_image']) : url('/assets/images/services-hero.jpg');
$canonical_url = isset($seo_meta['canonical_url']) && $seo_meta['canonical_url'] ? $seo_meta['canonical_url'] : url('/?page=services');
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

<!-- JSON-LD Schema Markup for Services -->
<?php if (!empty($services)): ?>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "ItemList",
    "itemListElement": [
        <?php foreach ($services as $index => $service): ?>
        {
            "@type": "ListItem",
            "position": <?php echo $index + 1; ?>,
            "item": {
                "@type": "Service",
                "name": "<?php echo e($service['service_name']); ?>",
                "description": "<?php echo e($service['short_description']); ?>",
                "provider": {
                    "@type": "Organization",
                    "name": "Mira Edge Technologies"
                }<?php if (!empty($service['base_price'])): ?>,
                "offers": {
                    "@type": "Offer",
                    "price": "<?php echo e($service['base_price']); ?>",
                    "priceCurrency": "XAF"
                }
                <?php endif; ?>
            }
        }<?php echo $index < count($services) - 1 ? ',' : ''; ?>
        <?php endforeach; ?>
    ]
}
</script>
<?php endif; ?>

<!-- Services Hero Section -->
<section class="services-hero">
    <div class="container">
        <div class="services-hero-content">
            <h1 class="animate-up">Our Services</h1>
            <p class="animate-up" style="animation-delay: 0.2s;">Comprehensive tech solutions tailored to transform your business and drive digital success</p>
        </div>
    </div>
</section>

<!-- Services Overview Section -->
<section class="services-overview">
    <div class="container">
        <h2 class="section-title animate-up">What We Offer</h2>
        <p class="section-subtitle animate-up">From concept to completion, we deliver excellence at every step</p>
        
        <!-- Category Tabs -->
        <?php if (!empty($categories)): ?>
        <div class="services-category-tabs animate-up">
            <button class="category-tab active" data-category="all">All Services</button>
            <?php foreach ($categories as $category): ?>
            <button class="category-tab" data-category="<?php echo e($category['slug']); ?>">
                <?php echo e($category['category_name']); ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Services Grid -->
        <div class="services-grid" id="services-grid">
            <?php if (!empty($services)): ?>
                <?php foreach ($services as $index => $service): ?>
                <?php 
                    // Determine category slug for filtering
                    $categorySlug = '';
                    foreach ($categories as $cat) {
                        if ($cat['service_category_id'] == $service['service_category_id']) {
                            $categorySlug = $cat['slug'];
                            break;
                        }
                    }
                ?>
                <div class="service-card animate-up" 
                     style="animation-delay: <?php echo $index * 0.1; ?>s;"
                     data-category="<?php echo e($categorySlug); ?>"
                     data-service-id="<?php echo $service['service_id']; ?>"
                     onclick="openServiceModal(<?php echo htmlspecialchars(json_encode($service), ENT_QUOTES, 'UTF-8'); ?>)">
                    
                    <?php if (!empty($service['is_popular']) && $service['is_popular'] == 1): ?>
                    <span class="popular-badge">Popular</span>
                    <?php endif; ?>
                    
                    <div class="service-image">
                        <img src="<?php echo !empty($service['featured_image']) ? url($service['featured_image']) : 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?ixlib=rb-4.0.3&auto=format&fit=crop&w=2072&q=80'; ?>" 
                             alt="<?php echo e($service['service_name']); ?>">
                    </div>
                    
                    <div class="service-content">
                        <?php if (!empty($service['category_name'])): ?>
                        <span class="service-category"><?php echo e($service['category_name']); ?></span>
                        <?php endif; ?>
                        
                        <h3><?php echo e($service['service_name']); ?></h3>
                        <p><?php echo e($service['short_description']); ?></p>
                        
                        <div class="service-meta">
                            <?php if (!empty($service['base_price'])): ?>
                            <div class="service-price">
                                <?php echo number_format($service['base_price']); ?> <small>XAF</small>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($service['estimated_duration'])): ?>
                            <div class="service-duration">
                                <i class="far fa-clock"></i>
                                <span><?php echo e($service['estimated_duration']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="service-actions">
                            <button class="btn btn-outline" onclick="event.stopPropagation(); openServiceModal(<?php echo htmlspecialchars(json_encode($service), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                            <button class="btn" onclick="event.stopPropagation(); openOrderForm(<?php echo htmlspecialchars(json_encode($service), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="fas fa-shopping-cart"></i> Order Now
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Fallback Services -->
                <?php
                $fallbackServices = [
                    [
                        'service_id' => 1,
                        'service_name' => 'Custom Website Development',
                        'slug' => 'custom-website-development',
                        'category_name' => 'Web Development',
                        'short_description' => 'Professional, responsive websites tailored to your brand and business needs.',
                        'full_description' => 'We create stunning, responsive websites that not only look great but also perform exceptionally well. Our websites are built with modern technologies and SEO best practices to ensure your business stands out online.',
                        'base_price' => 250000,
                        'estimated_duration' => '2-3 weeks',
                        'is_popular' => 0,
                        'featured_image' => 'https://images.unsplash.com/photo-1461749280684-dccba630e2f6?ixlib=rb-4.0.3&auto=format&fit=crop&w=2069&q=80'
                    ],
                    [
                        'service_id' => 2,
                        'service_name' => 'Mobile App Development',
                        'slug' => 'mobile-app-development',
                        'category_name' => 'Mobile Apps',
                        'short_description' => 'Native and cross-platform mobile applications for iOS and Android.',
                        'full_description' => 'Transform your ideas into powerful mobile applications. We develop feature-rich, user-friendly apps that provide seamless experiences across all devices and platforms.',
                        'base_price' => 500000,
                        'estimated_duration' => '8-12 weeks',
                        'is_popular' => 1,
                        'featured_image' => 'https://images.unsplash.com/photo-1512941937669-90a1b58e7e9c?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80'
                    ],
                    [
                        'service_id' => 3,
                        'service_name' => 'SEO & Digital Marketing',
                        'slug' => 'seo-digital-marketing',
                        'category_name' => 'Digital Marketing',
                        'short_description' => 'Comprehensive SEO and digital marketing strategies to boost your online presence.',
                        'full_description' => 'Increase your visibility and reach more customers with our data-driven digital marketing strategies. We optimize your online presence to drive targeted traffic and maximize conversions.',
                        'base_price' => 150000,
                        'estimated_duration' => 'Ongoing',
                        'is_popular' => 0,
                        'featured_image' => 'https://images.unsplash.com/photo-1557838923-2985c318be48?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80'
                    ]
                ];
                ?>
                <?php foreach ($fallbackServices as $index => $service): ?>
                <div class="service-card animate-up <?php echo $service['is_popular'] ? 'featured' : ''; ?>" 
                     style="animation-delay: <?php echo $index * 0.1; ?>s;"
                     data-category="<?php echo strtolower(str_replace(' ', '-', $service['category_name'])); ?>"
                     data-service-id="<?php echo $service['service_id']; ?>"
                     onclick='openServiceModal(<?php echo json_encode($service); ?>)'>
                    
                    <?php if ($service['is_popular']): ?>
                    <span class="popular-badge">Popular</span>
                    <?php endif; ?>
                    
                    <div class="service-image">
                        <img src="<?php echo $service['featured_image']; ?>" alt="<?php echo e($service['service_name']); ?>">
                    </div>
                    
                    <div class="service-content">
                        <span class="service-category"><?php echo e($service['category_name']); ?></span>
                        <h3><?php echo e($service['service_name']); ?></h3>
                        <p><?php echo e($service['short_description']); ?></p>
                        
                        <div class="service-meta">
                            <div class="service-price">
                                <?php echo number_format($service['base_price']); ?> <small>XAF</small>
                            </div>
                            <div class="service-duration">
                                <i class="far fa-clock"></i>
                                <span><?php echo e($service['estimated_duration']); ?></span>
                            </div>
                        </div>
                        
                        <div class="service-actions">
                            <button class="btn btn-outline" onclick="event.stopPropagation(); openServiceModal(<?php echo htmlspecialchars(json_encode($service), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                            <button class="btn" onclick="event.stopPropagation(); openOrderForm(<?php echo htmlspecialchars(json_encode($service), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="fas fa-shopping-cart"></i> Order Now
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Service Detail Modal -->
<div id="serviceModal" class="service-modal" style="display: none;">
    <div class="modal-content">
        <button class="modal-close" onclick="closeServiceModal()">&times;</button>
        <div class="modal-header" id="modalHeader">
            <h2 id="modalTitle">Service Details</h2>
            <p id="modalSubtitle">Complete information about this service</p>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<!-- Order Form Modal -->
<div id="orderModal" class="service-modal" style="display: none;">
    <div class="modal-content">
        <button class="modal-close" onclick="closeOrderModal()">&times;</button>
        <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-color) 0%, #333 100%);">
            <h2>Place Your Order</h2>
            <p>Fill in the details below to get started with your project</p>
        </div>
        <div class="modal-body">
            <div class="selected-service-info" id="selectedServiceInfo">
                <!-- Selected service info will be displayed here -->
            </div>
            
            <form id="orderForm" class="order-form" onsubmit="submitOrder(event)">
                <div class="form-group full-width">
                    <label for="service_id"><i class="fas fa-cog"></i> Service</label>
                    <select id="service_id" name="service_id" class="form-control" required onchange="updatePackages(this.value)">
                        <option value="">Select a service</option>
                        <?php if (!empty($services)): ?>
                            <?php foreach ($services as $service): ?>
                            <option value="<?php echo $service['service_id']; ?>" data-service='<?php echo htmlspecialchars(json_encode($service), ENT_QUOTES, 'UTF-8'); ?>'>
                                <?php echo e($service['service_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($fallbackServices ?? [] as $service): ?>
                            <option value="<?php echo $service['service_id']; ?>" data-service='<?php echo htmlspecialchars(json_encode($service), ENT_QUOTES, 'UTF-8'); ?>'>
                                <?php echo e($service['service_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group full-width">
                    <label for="package_id"><i class="fas fa-box"></i> Select Package</label>
                    <select id="package_id" name="package_id" class="form-control" required>
                        <option value="">First select a service</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="name"><i class="fas fa-user"></i> Full Name *</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="phone"><i class="fas fa-phone"></i> Phone Number *</label>
                    <input type="tel" id="phone" name="phone" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="company"><i class="fas fa-building"></i> Company Name</label>
                    <input type="text" id="company" name="company" class="form-control">
                </div>
                
                <div class="form-group full-width">
                    <label for="requirements"><i class="fas fa-clipboard-list"></i> Project Requirements</label>
                    <textarea id="requirements" name="requirements" class="form-control" rows="5" placeholder="Tell us about your project requirements, goals, and any specific features you need..."></textarea>
                </div>
                
                <div class="form-row full-width">
                    <div class="form-group">
                        <label for="budget"><i class="fas fa-money-bill"></i> Estimated Budget (XAF)</label>
                        <input type="number" id="budget" name="budget" class="form-control" placeholder="e.g., 500000">
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_method"><i class="fas fa-credit-card"></i> Payment Method</label>
                        <select id="payment_method" name="payment_method" class="form-control">
                            <option value="mobile_money">Mobile Money</option>
                            <option value="orange_money">Orange Money</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="invoice">Invoice</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <button type="submit" class="submit-order-btn" id="submitOrderBtn">
                        <i class="fas fa-paper-plane"></i> Submit Order Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div id="successModal" class="service-modal" style="display: none;">
    <div class="modal-content" style="max-width: 500px; text-align: center;">
        <button class="modal-close" onclick="closeSuccessModal()">&times;</button>
        <div class="modal-body" style="padding: 50px 30px;">
            <i class="fas fa-check-circle" style="font-size: 5rem; color: #28a745; margin-bottom: 20px;"></i>
            <h2 style="color: var(--primary-color); margin-bottom: 15px;">Order Submitted Successfully!</h2>
            <p style="color: var(--dark-gray); margin-bottom: 25px;">Thank you for your interest. Our team will contact you within 24 hours to discuss your project details.</p>
            <p style="color: var(--primary-color); font-weight: 500; margin-bottom: 30px;">Your order number: <span id="orderNumberDisplay" style="font-weight: 700;"></span></p>
            <button class="btn" onclick="closeSuccessModal()">Close</button>
        </div>
    </div>
</div>

<!-- Services Page JavaScript -->
<script>
// Store packages data for quick access
const packagesData = <?php echo !empty($packages) ? json_encode($packages) : '[]'; ?>;

// Filter services by category
document.addEventListener('DOMContentLoaded', function() {
    const categoryTabs = document.querySelectorAll('.category-tab');
    
    if (categoryTabs.length > 0) {
        categoryTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Update active tab
                document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                const category = this.getAttribute('data-category');
                const serviceCards = document.querySelectorAll('.service-card');
                
                serviceCards.forEach(card => {
                    if (category === 'all' || card.getAttribute('data-category') === category) {
                        card.style.display = 'block';
                        setTimeout(() => {
                            card.style.opacity = '1';
                            card.style.transform = 'translateY(0)';
                        }, 50);
                    } else {
                        card.style.opacity = '0';
                        card.style.transform = 'translateY(20px)';
                        setTimeout(() => {
                            card.style.display = 'none';
                        }, 300);
                    }
                });
            });
        });
    }
});

// Service Modal Functions
function openServiceModal(service) {
    const modal = document.getElementById('serviceModal');
    const modalHeader = document.getElementById('modalHeader');
    const modalBody = document.getElementById('modalBody');
    
    // Get packages for this service
    const servicePackages = packagesData.filter(p => parseInt(p.service_id) === parseInt(service.service_id));
    
    // Build modal content
    let packagesHTML = '';
    if (servicePackages.length > 0) {
        packagesHTML = `
            <div class="packages-section">
                <h3>Available Packages</h3>
                <div class="packages-grid">
                    ${servicePackages.map(pkg => `
                        <div class="package-card ${pkg.is_featured ? 'featured' : ''}">
                            ${pkg.is_featured ? '<span class="package-badge">Best Value</span>' : ''}
                            <div class="package-header">
                                <h4>${escapeHtml(pkg.package_name || 'Standard Package')}</h4>
                                <div class="package-price">
                                    ${pkg.price ? Number(pkg.price).toLocaleString() : 'Custom'} <small>XAF</small>
                                </div>
                            </div>
                            <ul class="package-features">
                                ${pkg.features && pkg.features.length > 0 ? pkg.features.map(f => `
                                    <li class="${f.is_included ? '' : 'disabled'}">
                                        <i class="fas fa-${f.is_included ? 'check' : 'times'}"></i>
                                        ${escapeHtml(f.feature_text || 'Feature')}
                                    </li>
                                `).join('') : '<li>Contact us for package details</li>'}
                            </ul>
                            <div class="package-actions">
                                <button class="btn btn-outline" onclick="selectPackage(${pkg.package_id})">
                                    Select Package
                                </button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    modalBody.innerHTML = `
        <div style="margin-bottom: 30px;">
            <h3 style="font-size: 1.3rem; margin-bottom: 15px; color: var(--primary-color);">Service Description</h3>
            <p style="line-height: 1.8; margin-bottom: 20px;">${escapeHtml(service.full_description || service.short_description || 'No description available.')}</p>
            
            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin: 20px 0;">
                ${service.base_price ? `
                <div style="background: var(--light-gray); padding: 15px 25px; border-radius: 8px;">
                    <small style="color: var(--dark-gray);">Starting from</small>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color);">
                        ${Number(service.base_price).toLocaleString()} XAF
                    </div>
                </div>
                ` : ''}
                
                ${service.estimated_duration ? `
                <div style="background: var(--light-gray); padding: 15px 25px; border-radius: 8px;">
                    <small style="color: var(--dark-gray);">Estimated Duration</small>
                    <div style="font-size: 1.3rem; font-weight: 600; color: var(--primary-color);">
                        <i class="far fa-clock" style="margin-right: 5px;"></i>
                        ${escapeHtml(service.estimated_duration)}
                    </div>
                </div>
                ` : ''}
            </div>
        </div>
        
        ${packagesHTML}
        
        <div style="text-align: center; margin-top: 30px;">
            <button class="btn" onclick="openOrderForm(${JSON.stringify(service).replace(/"/g, '&quot;')})">
                <i class="fas fa-shopping-cart"></i> Order This Service
            </button>
        </div>
    `;
    
    modalHeader.style.background = 'linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%)';
    document.getElementById('modalTitle').textContent = service.service_name || 'Service Details';
    document.getElementById('modalSubtitle').textContent = service.category_name || 'Premium Service';
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeServiceModal() {
    document.getElementById('serviceModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Order Modal Functions
function openOrderForm(service) {
    closeServiceModal();
    
    const modal = document.getElementById('orderModal');
    const serviceSelect = document.getElementById('service_id');
    const selectedInfo = document.getElementById('selectedServiceInfo');
    
    if (serviceSelect) {
        // Set the service select value
        serviceSelect.value = service.service_id;
    }
    
    // Update selected service info
    selectedInfo.innerHTML = `
        <p><strong>Selected Service:</strong> ${escapeHtml(service.service_name || 'Service')}</p>
        ${service.base_price ? `<p><strong>Starting Price:</strong> ${Number(service.base_price).toLocaleString()} XAF</p>` : ''}
    `;
    
    // Update packages dropdown
    if (service.service_id) {
        updatePackages(service.service_id);
    }
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeOrderModal() {
    document.getElementById('orderModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    const form = document.getElementById('orderForm');
    if (form) form.reset();
}

function updatePackages(serviceId) {
    const packageSelect = document.getElementById('package_id');
    if (!packageSelect) return;
    
    const servicePackages = packagesData.filter(p => parseInt(p.service_id) === parseInt(serviceId));
    
    if (servicePackages.length > 0) {
        packageSelect.innerHTML = '<option value="">Select a package</option>' + 
            servicePackages.map(pkg => 
                `<option value="${pkg.package_id}" data-price="${pkg.price || 0}">
                    ${escapeHtml(pkg.package_name || 'Standard Package')} - ${pkg.price ? Number(pkg.price).toLocaleString() : 'Custom'} XAF
                </option>`
            ).join('');
    } else {
        packageSelect.innerHTML = '<option value="">Custom quote (no packages available)</option>';
    }
}

function selectPackage(packageId) {
    closeServiceModal();
    
    const modal = document.getElementById('orderModal');
    const packageSelect = document.getElementById('package_id');
    
    if (!packageSelect) return;
    
    // Find and select the package
    const package = packagesData.find(p => parseInt(p.package_id) === parseInt(packageId));
    if (package) {
        // Ensure packages dropdown is populated for the package's service
        const serviceId = package.service_id;
        const serviceSelect = document.getElementById('service_id');
        if (serviceSelect) {
            serviceSelect.value = serviceId;
        }

        // Populate packages for this service then set the selected package
        updatePackages(serviceId);
        packageSelect.value = packageId;

        // Update selected info
        document.getElementById('selectedServiceInfo').innerHTML = `
            <p><strong>Selected Service:</strong> ${escapeHtml(package.service_name || 'Service')}</p>
            <p><strong>Selected Package:</strong> ${escapeHtml(package.package_name || 'Standard Package')}</p>
            <p><strong>Price:</strong> ${package.price ? Number(package.price).toLocaleString() : 'Custom'} XAF</p>
        `;
    }
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Success Modal Functions
function openSuccessModal(orderNumber) {
    closeOrderModal();
    
    const modal = document.getElementById('successModal');
    document.getElementById('orderNumberDisplay').textContent = orderNumber || 'N/A';
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeSuccessModal() {
    document.getElementById('successModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Form Submission
async function submitOrder(event) {
    event.preventDefault();
    
    const submitBtn = document.getElementById('submitOrderBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner"></span> Submitting...';
    submitBtn.disabled = true;
    
    const formData = new FormData(event.target);
    const data = Object.fromEntries(formData.entries());
    
    // Add client_name field for the database
    data.client_name = data.name;
    
    try {
        const response = await fetch('<?php echo url('/api/orders.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            openSuccessModal(result.order_number);
            event.target.reset();
        } else {
            alert('Error: ' + (result.message || 'Failed to submit order'));
        }
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred. Please try again or contact us directly.');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modals when clicking outside
window.onclick = function(event) {
    const serviceModal = document.getElementById('serviceModal');
    const orderModal = document.getElementById('orderModal');
    const successModal = document.getElementById('successModal');
    
    if (event.target === serviceModal) {
        closeServiceModal();
    }
    if (event.target === orderModal) {
        closeOrderModal();
    }
    if (event.target === successModal) {
        closeSuccessModal();
    }
}

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeServiceModal();
        closeOrderModal();
        closeSuccessModal();
    }
});

// Initialize service select change handler
const serviceSelect = document.getElementById('service_id');
if (serviceSelect) {
    serviceSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const serviceData = selectedOption ? selectedOption.getAttribute('data-service') : null;
        
        if (serviceData) {
            try {
                const service = JSON.parse(serviceData);
                document.getElementById('selectedServiceInfo').innerHTML = `
                    <p><strong>Selected Service:</strong> ${escapeHtml(service.service_name || 'Service')}</p>
                    ${service.base_price ? `<p><strong>Starting Price:</strong> ${Number(service.base_price).toLocaleString()} XAF</p>` : ''}
                `;
            } catch (e) {
                console.error('Error parsing service data:', e);
            }
        }
    });
}
</script>

<!-- Link to services.css -->
<link rel="stylesheet" href="<?php echo url('/pages/assets/css/services.css'); ?>">