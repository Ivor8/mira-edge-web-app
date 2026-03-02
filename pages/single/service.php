<?php
/**
 * Single Service Page - Mira Edge Technologies
 * Displays detailed service information with packages and order functionality
 */

// Get service ID from URL
$service_id = isset($_GET['service_id']) || isset($_GET['id']) ? (int)($_GET['service_id'] ?? $_GET['id'] ?? 0) : 0;
$slug = isset($_GET['slug']) ? sanitize($_GET['slug']) : '';

if (!$service_id) {
    header('Location: ' . url('/?page=services'));
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get service details
    $stmt = $db->prepare("
        SELECT s.*, 
               sc.category_name, sc.slug as category_slug, sc.icon_class as category_icon
        FROM services s
        LEFT JOIN service_categories sc ON s.service_category_id = sc.service_category_id
        WHERE s.service_id = ? AND s.is_active = 1
    ");
    $stmt->execute([$service_id]);
    $service = $stmt->fetch();
    
    if (!$service) {
        header('Location: ' . url('/?page=services'));
        exit;
    }
    
    // Get packages for this service with their features
    $stmt = $db->prepare("
        SELECT sp.* FROM service_packages sp
        WHERE sp.service_id = ?
        ORDER BY sp.is_featured DESC, sp.display_order ASC
    ");
    $stmt->execute([$service_id]);
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
    unset($package);
    
    // Get related services (same category, excluding current)
    $stmt = $db->prepare("
        SELECT service_id, service_name, slug, short_description, featured_image, base_price
        FROM services
        WHERE service_category_id = ? AND service_id != ? AND is_active = 1
        ORDER BY display_order ASC
        LIMIT 3
    ");
    $stmt->execute([$service['service_category_id'], $service_id]);
    $related_services = $stmt->fetchAll() ?: [];
    
    // Get SEO metadata for this service
    $seo_title = !empty($service['seo_title']) ? $service['seo_title'] : $service['service_name'] . ' | Mira Edge Technologies';
    $seo_description = !empty($service['seo_description']) ? $service['seo_description'] : $service['short_description'];
    $seo_keywords = !empty($service['seo_keywords']) ? $service['seo_keywords'] : $service['service_name'] . ', ' . $service['category_name'] . ', web development, digital solutions';
    
} catch (PDOException $e) {
    error_log("Single Service Error: " . $e->getMessage());
    header('Location: ' . url('/?page=services'));
    exit;
}
?>

<!-- Page Specific Meta Tags -->
<meta name="description" content="<?php echo e($seo_description); ?>">
<meta name="keywords" content="<?php echo e($seo_keywords); ?>">
<link rel="canonical" href="<?php echo url('/?page=service&service_id=' . $service_id . '&slug=' . $slug); ?>">

<!-- JSON-LD Schema Markup for Service -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Service",
    "name": "<?php echo e($service['service_name']); ?>",
    "description": "<?php echo e($seo_description); ?>",
    "provider": {
        "@type": "Organization",
        "name": "Mira Edge Technologies",
        "url": "<?php echo url('/?page=home'); ?>",
        "logo": "<?php echo url('/assets/images/Mira Edge Logo.png'); ?>"
    },
    "image": "<?php echo !empty($service['featured_image']) ? (strpos($service['featured_image'], 'http') === 0 ? $service['featured_image'] : url($service['featured_image'])) : ''; ?>",
    <?php if (!empty($service['base_price'])): ?>
    "offers": {
        "@type": "Offer",
        "price": "<?php echo $service['base_price']; ?>",
        "priceCurrency": "XAF"
    },
    <?php endif; ?>
    "areaServed": {
        "@type": "Country",
        "name": "Cameroon"
    },
    "url": "<?php echo url('/?page=service&service_id=' . $service_id . '&slug=' . $slug); ?>"
}
</script>

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
            "name": "Services",
            "item": "<?php echo url('/?page=services'); ?>"
        },
        {
            "@type": "ListItem",
            "position": 3,
            "name": "<?php echo e($service['category_name']); ?>",
            "item": "<?php echo url('/?page=services'); ?>"
        },
        {
            "@type": "ListItem",
            "position": 4,
            "name": "<?php echo e($service['service_name']); ?>",
            "item": "<?php echo url('/?page=service&service_id=' . $service_id . '&slug=' . $slug); ?>"
        }
    ]
}
</script>

<!-- Open Graph / Facebook -->
<meta property="og:title" content="<?php echo e($seo_title); ?>">
<meta property="og:description" content="<?php echo e($seo_description); ?>">
<meta property="og:image" content="<?php echo !empty($service['featured_image']) ? (strpos($service['featured_image'], 'http') === 0 ? $service['featured_image'] : url($service['featured_image'])) : url('/assets/images/og-image.jpg'); ?>">
<meta property="og:url" content="<?php echo url('/?page=service&service_id=' . $service_id . '&slug=' . $slug); ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Mira Edge Technologies">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo e($seo_title); ?>">
<meta name="twitter:description" content="<?php echo e($seo_description); ?>">
<meta name="twitter:image" content="<?php echo !empty($service['featured_image']) ? (strpos($service['featured_image'], 'http') === 0 ? $service['featured_image'] : url($service['featured_image'])) : url('/assets/images/og-image.jpg'); ?>">
<meta name="twitter:site" content="@miraedgetech">

<!-- Single Service Section -->
<section class="single-service" style="padding-top: 150px;">
    <div class="container">
        <div class="single-service-container">

            <!-- Service Header -->
            <div class="single-service-header">
                <?php if (!empty($service['category_name'])): ?>
                <span class="single-service-category">
                    <?php if (!empty($service['category_icon'])): ?>
                    <i class="<?php echo e($service['category_icon']); ?>"></i>
                    <?php endif; ?>
                    <?php echo e($service['category_name']); ?>
                </span>
                <?php endif; ?>
                
                <h1 class="single-service-title"><?php echo e($service['service_name']); ?></h1>
                
                <div class="single-service-meta">
                    <?php if (!empty($service['base_price'])): ?>
                    <span><i class="fas fa-tag"></i> Starting from <?php echo e(number_format($service['base_price'])); ?> XAF</span>
                    <?php endif; ?>
                    
                    <?php if (!empty($service['estimated_duration'])): ?>
                    <span><i class="far fa-clock"></i> <?php echo e($service['estimated_duration']); ?></span>
                    <?php endif; ?>
                    
                    <?php if ($service['is_popular']): ?>
                    <span><i class="fas fa-star"></i> Popular Service</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Featured Image -->
            <div class="single-service-featured-image">
                <img src="<?php 
                    if (!empty($service['featured_image'])) {
                        if (strpos($service['featured_image'], 'http') === 0) {
                            echo $service['featured_image'];
                        } else {
                            echo url($service['featured_image']);
                        }
                    } else {
                        echo 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80';
                    }
                ?>" alt="<?php echo e($service['service_name']); ?>">
            </div>
            
            <!-- Service Content -->
            <div class="single-service-content">
                <h2 style="font-size: 1.5rem; margin-bottom: 20px; color: var(--primary-color);">Overview</h2>
                <div style="line-height: 1.8; margin-bottom: 40px;">
                    <?php 
                        if (!empty($service['full_description'])) {
                            echo nl2br(e($service['full_description']));
                        } else {
                            echo nl2br(e($service['short_description']));
                        }
                    ?>
                </div>
                
                <!-- Service Details -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 40px 0;">
                    <?php if (!empty($service['base_price'])): ?>
                    <div style="background: linear-gradient(135deg, var(--primary-color), var(--accent-color)); color: white; padding: 25px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 0.9rem; opacity: 0.9; margin-bottom: 10px;">Starting Price</div>
                        <div style="font-size: 2rem; font-weight: 700;">
                            <?php echo e(number_format($service['base_price'])); ?> XAF
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($service['estimated_duration'])): ?>
                    <div style="background: var(--light-gray); padding: 25px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 0.9rem; color: var(--dark-gray); margin-bottom: 10px;">Estimated Timeline</div>
                        <div style="font-size: 1.3rem; font-weight: 700; color: var(--primary-color);">
                            <i class="far fa-clock"></i> <?php echo e($service['estimated_duration']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Packages Section -->
            <?php if (!empty($packages)): ?>
            <section class="service-packages-section" style="margin-top: 60px;">
                <h2 style="font-size: 1.8rem; color: var(--primary-color); margin-bottom: 40px; text-align: center;">
                    <i class="fas fa-box"></i> Available Packages
                </h2>
                
                <div class="packages-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px;">
                    <?php foreach ($packages as $package): ?>
                    <div class="package-card <?php echo $package['is_featured'] ? 'featured-package' : ''; ?>" style="
                        background: white;
                        border: 2px solid <?php echo $package['is_featured'] ? 'var(--primary-color)' : 'var(--light-gray)'; ?>;
                        border-radius: 12px;
                        padding: 30px;
                        transition: all 0.3s ease;
                        position: relative;
                        <?php echo $package['is_featured'] ? 'box-shadow: 0 10px 30px rgba(var(--primary-rgb), 0.2); transform: scale(1.02);' : ''; ?>
                    ">
                        <?php if ($package['is_featured']): ?>
                        <span class="package-badge" style="
                            position: absolute;
                            top: -15px;
                            left: 50%;
                            transform: translateX(-50%);
                            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
                            color: white;
                            padding: 8px 20px;
                            border-radius: 20px;
                            font-size: 0.85rem;
                            font-weight: 600;
                        ">
                            <i class="fas fa-star"></i> Best Value
                        </span>
                        <?php endif; ?>
                        
                        <div class="package-header" style="margin-bottom: 25px;">
                            <h3 style="font-size: 1.3rem; margin-bottom: 10px; color: <?php echo $package['is_featured'] ? 'var(--primary-color)' : 'var(--dark-color)'; ?>;">
                                <?php echo e($package['package_name'] ?? 'Standard Package'); ?>
                            </h3>
                            <?php if (!empty($package['description'])): ?>
                            <p style="color: var(--dark-gray); font-size: 0.9rem;">
                                <?php echo e($package['description']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="package-price" style="
                            background: <?php echo $package['is_featured'] ? 'linear-gradient(135deg, var(--primary-color), var(--accent-color))' : 'var(--light-gray)'; ?>;
                            color: <?php echo $package['is_featured'] ? 'white' : 'var(--primary-color)'; ?>;
                            padding: 20px;
                            border-radius: 8px;
                            margin-bottom: 25px;
                            text-align: center;
                        ">
                            <div style="font-size: 0.9rem; opacity: 0.9; margin-bottom: 5px;">Price</div>
                            <div style="font-size: 2rem; font-weight: 700;">
                                <?php echo e($package['price'] ? number_format($package['price']) : 'Custom'); ?>
                                <?php if (!empty($package['price'])): ?>
                                <small style="font-size: 0.6em; display: block; margin-top: 5px;">XAF</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <ul class="package-features" style="list-style: none; padding: 0; margin-bottom: 25px;">
                            <?php foreach ($package['features'] as $feature): ?>
                            <li style="
                                padding: 12px 0;
                                border-bottom: 1px solid var(--light-gray);
                                color: <?php echo $feature['is_included'] ? 'var(--dark-color)' : 'var(--light-gray)'; ?>;
                                <?php echo !$feature['is_included'] ? 'text-decoration: line-through; opacity: 0.6;' : ''; ?>
                            ">
                                <i class="fas fa-<?php echo $feature['is_included'] ? 'check-circle' : 'times-circle'; ?>" 
                                   style="margin-right: 10px; color: <?php echo $feature['is_included'] ? 'var(--primary-color)' : 'var(--light-gray)'; ?>;"></i>
                                <?php echo e($feature['feature_text'] ?? 'Feature'); ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <div class="package-actions" style="display: grid; gap: 10px;">
                            <button class="btn" onclick="selectAndOrder(<?php echo $package['package_id']; ?>, <?php echo $service_id; ?>, '<?php echo e($package['package_name']); ?>')" style="width: 100%;">
                                <i class="fas fa-shopping-cart"></i> Order This Package
                            </button>
                            <button class="btn btn-outline" onclick="scrollToOrderForm()" style="width: 100%;">
                                <i class="fas fa-question-circle"></i> Have Questions?
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
            
            <!-- Order Form Section -->
            <section id="order-form-section" class="order-form-section" style="margin-top: 80px; padding: 50px; background: linear-gradient(135deg, var(--light-gray), white); border-radius: 15px;">
                <h2 style="font-size: 1.8rem; color: var(--primary-color); margin-bottom: 40px; text-align: center;">
                    <i class="fas fa-paper-plane"></i> Place Your Order
                </h2>
                
                <div style="max-width: 700px; margin: 0 auto;">
                    <form id="serviceOrderForm" class="order-form" onsubmit="submitServiceOrder(event)">
                        <div class="form-group full-width">
                            <label for="service_id_form"><i class="fas fa-cog"></i> Service</label>
                            <input type="text" value="<?php echo e($service['service_name']); ?>" class="form-control" disabled style="background: var(--light-gray);">
                            <input type="hidden" id="service_id_form" name="service_id" value="<?php echo $service_id; ?>">
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="package_id_form"><i class="fas fa-box"></i> Select Package</label>
                            <select id="package_id_form" name="package_id" class="form-control" required>
                                <option value="">Select a package</option>
                                <?php if (!empty($packages)): ?>
                                    <?php foreach ($packages as $pkg): ?>
                                    <option value="<?php echo $pkg['package_id']; ?>">
                                        <?php echo e($pkg['package_name'] ?? 'Standard Package'); ?> - <?php echo e($pkg['price'] ? number_format($pkg['price']) : 'Custom'); ?> XAF
                                    </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">Custom quote (contact for details)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="name_form"><i class="fas fa-user"></i> Full Name *</label>
                            <input type="text" id="name_form" name="name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email_form"><i class="fas fa-envelope"></i> Email Address *</label>
                            <input type="email" id="email_form" name="email" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone_form"><i class="fas fa-phone"></i> Phone Number *</label>
                            <input type="tel" id="phone_form" name="phone" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="company_form"><i class="fas fa-building"></i> Company Name</label>
                            <input type="text" id="company_form" name="company" class="form-control">
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="requirements_form"><i class="fas fa-clipboard-list"></i> Project Requirements</label>
                            <textarea id="requirements_form" name="requirements" class="form-control" rows="5" placeholder="Tell us about your project requirements, goals, and any specific features you need..."></textarea>
                        </div>
                        
                        <div class="form-row full-width">
                            <div class="form-group">
                                <label for="budget_form"><i class="fas fa-money-bill"></i> Estimated Budget (XAF)</label>
                                <input type="number" id="budget_form" name="budget" class="form-control" placeholder="e.g., 500000">
                            </div>
                            
                            <div class="form-group">
                                <label for="payment_method_form"><i class="fas fa-credit-card"></i> Payment Method</label>
                                <select id="payment_method_form" name="payment_method" class="form-control">
                                    <option value="mobile_money">Mobile Money</option>
                                    <option value="orange_money">Orange Money</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="invoice">Invoice</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group full-width">
                            <button type="submit" class="submit-order-btn" id="submitServiceOrderBtn" style="width: 100%; padding: 15px; font-size: 1rem;">
                                <i class="fas fa-paper-plane"></i> Submit Order Request
                            </button>
                        </div>
                    </form>
                </div>
            </section>
            
            <!-- Success Message -->
            <div id="successMessage" style="display: none; position: fixed; bottom: 20px; right: 20px; background: #28a745; color: white; padding: 20px 30px; border-radius: 8px; box-shadow: var(--box-shadow); z-index: 1000;">
                <i class="fas fa-check-circle"></i> <span id="successText">Order submitted successfully!</span>
            </div>

            <!-- Related Services -->
            <?php if (!empty($related_services)): ?>
            <section style="margin-top: 80px;">
                <h2 style="font-size: 1.8rem; color: var(--primary-color); margin-bottom: 40px; text-align: center;">
                    <i class="fas fa-cogs"></i> Related Services
                </h2>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 30px;">
                    <?php foreach ($related_services as $related): ?>
                    <div style="background: white; border: 1px solid var(--light-gray); border-radius: 12px; overflow: hidden; box-shadow: var(--box-shadow); transition: all 0.3s ease; hover: transform 0.3s ease;">
                        <div style="height: 180px; overflow: hidden;">
                            <img src="<?php 
                                if (!empty($related['featured_image'])) {
                                    if (strpos($related['featured_image'], 'http') === 0) {
                                        echo $related['featured_image'];
                                    } else {
                                        echo url($related['featured_image']);
                                    }
                                } else {
                                    echo 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80';
                                }
                            ?>" alt="<?php echo e($related['service_name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                        <div style="padding: 25px;">
                            <h3 style="font-size: 1.2rem; margin-bottom: 10px; color: var(--primary-color);">
                                <a href="<?php echo url('/?page=service&service_id=' . $related['service_id'] . '&slug=' . $related['slug']); ?>" style="color: var(--primary-color); text-decoration: none; transition: color 0.3s ease;">
                                    <?php echo e($related['service_name']); ?>
                                </a>
                            </h3>
                            <p style="color: var(--dark-gray); font-size: 0.95rem; margin-bottom: 15px; line-height: 1.6;">
                                <?php echo e(substr($related['short_description'], 0, 100)) . '...'; ?>
                            </p>
                            <?php if (!empty($related['base_price'])): ?>
                            <div style="font-size: 1.1rem; font-weight: 600; color: var(--primary-color); margin-bottom: 15px;">
                                <i class="fas fa-tag"></i> From <?php echo e(number_format($related['base_price'])); ?> XAF
                            </div>
                            <?php endif; ?>
                            <a href="<?php echo url('/?page=service&service_id=' . $related['service_id'] . '&slug=' . $related['slug']); ?>" style="display: inline-block; background: var(--primary-color); color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 0.95rem; transition: all 0.3s ease;">
                                View Details <i class="fas fa-arrow-right" style="margin-left: 5px;"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Service Order JavaScript -->
<script>
function scrollToOrderForm() {
    const formSection = document.getElementById('order-form-section');
    formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function selectAndOrder(packageId, serviceId, packageName) {
    const packageSelect = document.getElementById('package_id_form');
    if (packageSelect) {
        packageSelect.value = packageId;
    }
    scrollToOrderForm();
}

async function submitServiceOrder(event) {
    event.preventDefault();
    
    const submitBtn = document.getElementById('submitServiceOrderBtn');
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
            // Show success message
            const successMsg = document.getElementById('successMessage');
            const successText = document.getElementById('successText');
            successText.innerHTML = 'Order #' + (result.order_number || 'N/A') + ' submitted successfully! We will contact you within 24 hours.';
            successMsg.style.display = 'block';
            
            // Reset form
            event.target.reset();
            
            // Hide success message after 5 seconds
            setTimeout(() => {
                successMsg.style.display = 'none';
            }, 5000);
            
            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
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

// Close success message on click
document.addEventListener('DOMContentLoaded', function() {
    const successMsg = document.getElementById('successMessage');
    if (successMsg) {
        successMsg.addEventListener('click', function() {
            this.style.display = 'none';
        });
    }
});
</script>

<!-- Link to services.css for styling -->
<link rel="stylesheet" href="<?php echo url('/pages/assets/css/services.css'); ?>">

<style>
/* Override styles for single service page */
.single-service {
    background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.05), rgba(var(--accent-rgb), 0.05));
}

.single-service-container {
    max-width: 1000px;
    margin: 0 auto;
}

.single-service-header {
    text-align: center;
    margin-bottom: 50px;
}

.single-service-category {
    display: inline-block;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    padding: 10px 20px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.single-service-title {
    font-size: 3rem;
    color: var(--primary-color);
    margin-bottom: 20px;
    line-height: 1.2;
}

.single-service-meta {
    display: flex;
    justify-content: center;
    gap: 30px;
    flex-wrap: wrap;
    color: var(--dark-gray);
    font-size: 1.1rem;
    margin-top: 20px;
}

.single-service-meta span {
    display: flex;
    align-items: center;
    gap: 8px;
}

.single-service-meta i {
    color: var(--primary-color);
    font-size: 1.2rem;
}

.single-service-featured-image {
    width: 100%;
    height: 400px;
    border-radius: 15px;
    overflow: hidden;
    margin-bottom: 50px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
}

.single-service-featured-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.single-service-content {
    margin-bottom: 50px;
    background: white;
    padding: 40px;
    border-radius: 12px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
}

@media (max-width: 768px) {
    .single-service-title {
        font-size: 2rem;
    }
    
    .single-service-meta {
        flex-direction: column;
        gap: 15px;
    }
    
    .single-service-featured-image {
        height: 250px;
    }
    
    .single-service-content {
        padding: 25px;
    }
}
</style>
