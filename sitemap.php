<?php
/**
 * XML Sitemap Generator - Mira Edge Technologies
 * Handles dynamic sitemap generation for all content
 */

require_once 'includes/core/Database.php';
require_once 'includes/functions/helpers.php';

// Determine which sitemap to generate
$sitemap_type = $_GET['type'] ?? 'main';

// Set headers for XML
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: inline; filename="sitemap-' . $sitemap_type . '.xml"');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . '://' . $host;

try {
    $db = Database::getInstance()->getConnection();
    
    echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . PHP_EOL;
    
    // Main pages sitemap
    if ($sitemap_type === 'main' || $sitemap_type === 'index') {
        $main_pages = [
            ['url' => '/?page=home', 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['url' => '/?page=about', 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['url' => '/?page=services', 'priority' => '0.9', 'changefreq' => 'weekly'],
            ['url' => '/?page=portfolio', 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['url' => '/?page=blog', 'priority' => '0.8', 'changefreq' => 'daily'],
            ['url' => '/?page=careers', 'priority' => '0.7', 'changefreq' => 'weekly'],
            ['url' => '/?page=contact', 'priority' => '0.7', 'changefreq' => 'monthly'],
        ];
        
        foreach ($main_pages as $page) {
            echo '  <url>' . PHP_EOL;
            echo '    <loc>' . htmlspecialchars($base_url . $page['url']) . '</loc>' . PHP_EOL;
            echo '    <lastmod>' . date('Y-m-d') . '</lastmod>' . PHP_EOL;
            echo '    <changefreq>' . $page['changefreq'] . '</changefreq>' . PHP_EOL;
            echo '    <priority>' . $page['priority'] . '</priority>' . PHP_EOL;
            echo '  </url>' . PHP_EOL;
        }
    }
    
    // Blog posts sitemap
    if ($sitemap_type === 'blog' || $sitemap_type === 'index') {
        $stmt = $db->prepare("
            SELECT post_id, slug, published_at, updated_at, featured_image
            FROM blog_posts
            WHERE status = 'published' AND published_at <= NOW()
            ORDER BY updated_at DESC
        ");
        $stmt->execute();
        $posts = $stmt->fetchAll();
        
        if (!empty($posts)) {
            foreach ($posts as $post) {
                echo '  <url>' . PHP_EOL;
                echo '    <loc>' . htmlspecialchars($base_url . '/?page=blog&post_id=' . $post['post_id'] . '&slug=' . $post['slug']) . '</loc>' . PHP_EOL;
                echo '    <lastmod>' . date('Y-m-d', strtotime($post['updated_at'])) . '</lastmod>' . PHP_EOL;
                echo '    <changefreq>monthly</changefreq>' . PHP_EOL;
                echo '    <priority>0.7</priority>' . PHP_EOL;
                if (!empty($post['featured_image'])) {
                    echo '    <image:image>' . PHP_EOL;
                    echo '      <image:loc>' . htmlspecialchars($base_url . ($post['featured_image'])) . '</image:loc>' . PHP_EOL;
                    echo '    </image:image>' . PHP_EOL;
                }
                echo '  </url>' . PHP_EOL;
            }
        }
    }
    
    // Blog categories sitemap
    if ($sitemap_type === 'blog' || $sitemap_type === 'index') {
        $stmt = $db->prepare("
            SELECT slug FROM blog_categories 
            WHERE is_active = 1
            ORDER BY display_order ASC
        ");
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        if (!empty($categories)) {
            foreach ($categories as $cat) {
                echo '  <url>' . PHP_EOL;
                echo '    <loc>' . htmlspecialchars($base_url . '/?page=blog&category_slug=' . $cat['slug']) . '</loc>' . PHP_EOL;
                echo '    <lastmod>' . date('Y-m-d') . '</lastmod>' . PHP_EOL;
                echo '    <changefreq>weekly</changefreq>' . PHP_EOL;
                echo '    <priority>0.6</priority>' . PHP_EOL;
                echo '  </url>' . PHP_EOL;
            }
        }
    }
    
    // Services sitemap
    if ($sitemap_type === 'services' || $sitemap_type === 'index') {
        $stmt = $db->prepare("
            SELECT service_id, slug, updated_at, featured_image
            FROM services
            WHERE is_active = 1
            ORDER BY updated_at DESC
        ");
        $stmt->execute();
        $services = $stmt->fetchAll();
        
        if (!empty($services)) {
            foreach ($services as $service) {
                echo '  <url>' . PHP_EOL;
                echo '    <loc>' . htmlspecialchars($base_url . '/?page=services&service_id=' . $service['service_id'] . '&slug=' . $service['slug']) . '</loc>' . PHP_EOL;
                echo '    <lastmod>' . date('Y-m-d', strtotime($service['updated_at'])) . '</lastmod>' . PHP_EOL;
                echo '    <changefreq>monthly</changefreq>' . PHP_EOL;
                echo '    <priority>0.75</priority>' . PHP_EOL;
                if (!empty($service['featured_image'])) {
                    echo '    <image:image>' . PHP_EOL;
                    echo '      <image:loc>' . htmlspecialchars($base_url . ($service['featured_image'])) . '</image:loc>' . PHP_EOL;
                    echo '    </image:image>' . PHP_EOL;
                }
                echo '  </url>' . PHP_EOL;
            }
        }
    }
    
    // Job listings sitemap
    if ($sitemap_type === 'jobs' || $sitemap_type === 'index') {
        $stmt = $db->prepare("
            SELECT job_id, slug, updated_at
            FROM job_listings
            WHERE is_active = 1 AND (application_deadline IS NULL OR application_deadline >= CURDATE())
            ORDER BY updated_at DESC
        ");
        $stmt->execute();
        $jobs = $stmt->fetchAll();
        
        if (!empty($jobs)) {
            foreach ($jobs as $job) {
                echo '  <url>' . PHP_EOL;
                echo '    <loc>' . htmlspecialchars($base_url . '/?page=careers&job_id=' . $job['job_id'] . '&slug=' . $job['slug']) . '</loc>' . PHP_EOL;
                echo '    <lastmod>' . date('Y-m-d', strtotime($job['updated_at'])) . '</lastmod>' . PHP_EOL;
                echo '    <changefreq>weekly</changefreq>' . PHP_EOL;
                echo '    <priority>0.7</priority>' . PHP_EOL;
                echo '  </url>' . PHP_EOL;
            }
        }
    }
    
    // Portfolio/Projects sitemap (if exists)
    if ($sitemap_type === 'portfolio' || $sitemap_type === 'index') {
        $stmt = $db->prepare("
            SELECT project_id, slug, updated_at, featured_image
            FROM portfolio_projects
            WHERE status = 'active'
            ORDER BY updated_at DESC
            LIMIT 100
        ");
        $stmt->execute();
        $projects = $stmt->fetchAll();
        
        if (!empty($projects)) {
            foreach ($projects as $project) {
                echo '  <url>' . PHP_EOL;
                echo '    <loc>' . htmlspecialchars($base_url . '/?page=portfolio&project_id=' . $project['project_id'] . '&slug=' . $project['slug']) . '</loc>' . PHP_EOL;
                echo '    <lastmod>' . date('Y-m-d', strtotime($project['updated_at'])) . '</lastmod>' . PHP_EOL;
                echo '    <changefreq>monthly</changefreq>' . PHP_EOL;
                echo '    <priority>0.75</priority>' . PHP_EOL;
                if (!empty($project['featured_image'])) {
                    echo '    <image:image>' . PHP_EOL;
                    echo '      <image:loc>' . htmlspecialchars($base_url . ($project['featured_image'])) . '</image:loc>' . PHP_EOL;
                    echo '    </image:image>' . PHP_EOL;
                }
                echo '  </url>' . PHP_EOL;
            }
        }
    }
    
    echo '</urlset>' . PHP_EOL;
    
} catch (PDOException $e) {
    error_log("Sitemap Generation Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>' . PHP_EOL;
}

exit;
?>
