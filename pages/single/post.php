<?php
/**
 * Single Blog Post Page - Mira Edge Technologies
 */

// Get post ID from URL
$post_id = isset($_GET['post_id']) || isset($_GET['id']) ? (int)($_GET['post_id'] ?? $_GET['id'] ?? 0) : 0;
$slug = isset($_GET['slug']) ? sanitize($_GET['slug']) : '';

if (!$post_id) {
    header('Location: ' . url('/?page=blog'));
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get post details
    $stmt = $db->prepare("
        SELECT p.*, 
               c.category_name, c.slug as category_slug,
               CONCAT(u.first_name, ' ', u.last_name) as author_name,
               u.profile_image as author_image,
               u.bio as author_bio
        FROM blog_posts p
        LEFT JOIN blog_categories c ON p.blog_category_id = c.blog_category_id
        LEFT JOIN users u ON p.author_id = u.user_id
        WHERE p.post_id = ? AND p.status = 'published' AND p.published_at <= NOW()
    ");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();
    
    if (!$post) {
        header('Location: ' . url('/?page=blog'));
        exit;
    }
    
    // Increment view count
    $stmt = $db->prepare("UPDATE blog_posts SET views_count = views_count + 1 WHERE post_id = ?");
    $stmt->execute([$post_id]);
    
    // Get post tags
    $stmt = $db->prepare("
        SELECT t.* FROM blog_tags t
        JOIN blog_post_tags pt ON t.tag_id = pt.tag_id
        WHERE pt.post_id = ?
        ORDER BY t.tag_name ASC
    ");
    $stmt->execute([$post_id]);
    $tags = $stmt->fetchAll() ?: [];
    
    // Get approved comments
    $stmt = $db->prepare("
        SELECT * FROM blog_comments 
        WHERE post_id = ? AND is_approved = 1 AND is_spam = 0
        ORDER BY created_at ASC
    ");
    $stmt->execute([$post_id]);
    $comments = $stmt->fetchAll() ?: [];
    
    // Get related posts (same category, excluding current)
    $stmt = $db->prepare("
        SELECT post_id, title, slug, excerpt, featured_image, published_at
        FROM blog_posts
        WHERE blog_category_id = ? AND post_id != ? AND status = 'published' AND published_at <= NOW()
        ORDER BY published_at DESC
        LIMIT 3
    ");
    $stmt->execute([$post['blog_category_id'], $post_id]);
    $related_posts = $stmt->fetchAll() ?: [];
    
    // Get SEO metadata for this post
    $seo_title = !empty($post['seo_title']) ? $post['seo_title'] : $post['title'] . ' | Mira Edge Technologies Blog';
    $seo_description = !empty($post['seo_description']) ? $post['seo_description'] : $post['excerpt'];
    $seo_keywords = !empty($post['seo_keywords']) ? $post['seo_keywords'] : implode(', ', array_column($tags, 'tag_name'));
    
} catch (PDOException $e) {
    error_log("Single Post Error: " . $e->getMessage());
    header('Location: ' . url('/?page=blog'));
    exit;
}
?>

<!-- Page Specific Meta Tags -->
<meta name="description" content="<?php echo e($seo_description); ?>">
<meta name="keywords" content="<?php echo e($seo_keywords); ?>">
<link rel="canonical" href="<?php echo url('/?page=blog&post_id=' . $post_id . '&slug=' . $slug); ?>">

<!-- Open Graph / Facebook -->
<meta property="og:title" content="<?php echo e($seo_title); ?>">
<meta property="og:description" content="<?php echo e($seo_description); ?>">
<meta property="og:image" content="<?php echo !empty($post['featured_image']) ? (strpos($post['featured_image'], 'http') === 0 ? $post['featured_image'] : url($post['featured_image'])) : url('/assets/images/og-image.jpg'); ?>">
<meta property="og:url" content="<?php echo url('/?page=blog&post_id=' . $post_id . '&slug=' . $slug); ?>">
<meta property="og:type" content="article">
<meta property="og:site_name" content="Mira Edge Technologies">
<meta property="article:published_time" content="<?php echo e($post['published_at']); ?>">
<meta property="article:author" content="<?php echo e($post['author_name']); ?>">
<?php foreach ($tags as $tag): ?>
<meta property="article:tag" content="<?php echo e($tag['tag_name']); ?>">
<?php endforeach; ?>

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo e($seo_title); ?>">
<meta name="twitter:description" content="<?php echo e($seo_description); ?>">
<meta name="twitter:image" content="<?php echo !empty($post['featured_image']) ? (strpos($post['featured_image'], 'http') === 0 ? $post['featured_image'] : url($post['featured_image'])) : url('/assets/images/og-image.jpg'); ?>">
<meta name="twitter:site" content="@miraedgetech">

<!-- JSON-LD Schema Markup for Blog Post -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "BlogPosting",
    "headline": "<?php echo e($post['title']); ?>",
    "description": "<?php echo e($post['excerpt']); ?>",
    "image": "<?php echo !empty($post['featured_image']) ? (strpos($post['featured_image'], 'http') === 0 ? $post['featured_image'] : url($post['featured_image'])) : ''; ?>",
    "datePublished": "<?php echo e($post['published_at']); ?>",
    "dateModified": "<?php echo e($post['updated_at'] ?? $post['published_at']); ?>",
    "author": {
        "@type": "Person",
        "name": "<?php echo e($post['author_name']); ?>"
    },
    "publisher": {
        "@type": "Organization",
        "name": "Mira Edge Technologies",
        "logo": {
            "@type": "ImageObject",
            "url": "<?php echo url('/assets/images/Mira Edge Logo.png'); ?>"
        }
    },
    "mainEntityOfPage": {
        "@type": "WebPage",
        "@id": "<?php echo url('/?page=blog&post_id=' . $post_id . '&slug=' . $slug); ?>"
    },
    "keywords": "<?php echo e($seo_keywords); ?>"
}
</script>

<!-- Single Post Section -->
<section class="single-post">
    <div class="container">
        <div class="single-post-container">

            <!-- Post Header -->
            <header class="single-post-header">
                <?php if (!empty($post['category_name'])): ?>
                <span class="single-post-category"><?php echo e($post['category_name']); ?></span>
                <?php endif; ?>
                
                <h1 class="single-post-title"><?php echo e($post['title']); ?></h1>
                
                <div class="single-post-meta">
                    <span><i class="far fa-calendar-alt"></i> <?php echo date('F d, Y', strtotime($post['published_at'])); ?></span>
                    <span><i class="far fa-user"></i> <?php echo e($post['author_name']); ?></span>
                    <span><i class="far fa-clock"></i> <?php echo $post['reading_time'] ?? 5; ?> min read</span>
                    <span><i class="far fa-eye"></i> <?php echo number_format($post['views_count'] ?? 0); ?> views</span>
                </div>
            </header>
            
            <!-- Featured Image -->
            <?php if (!empty($post['featured_image'])): ?>
            <div class="single-post-featured-image">
                <img src="<?php echo strpos($post['featured_image'], 'http') === 0 ? $post['featured_image'] : url($post['featured_image']); ?>" 
                     alt="<?php echo e($post['image_alt'] ?? $post['title']); ?>">
            </div>
            <?php endif; ?>
            
            <!-- Post Content -->
            <div class="single-post-content">
                <?php echo $post['content']; ?>
            </div>
            
            <!-- Tags -->
            <?php if (!empty($tags)): ?>
            <div class="post-tags">
                <?php foreach ($tags as $tag): ?>
                <a href="<?php echo url('/?page=blog&tag=' . $tag['tag_id']); ?>" class="tag">#<?php echo e($tag['tag_name']); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Share Buttons -->
            <div class="post-share">
                <span>Share this post:</span>
                <div class="share-buttons">
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(url('/?page=blog&post_id=' . $post_id . '&slug=' . $slug)); ?>" 
                       target="_blank" class="share-btn" rel="noopener noreferrer">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode(url('/?page=blog&post_id=' . $post_id . '&slug=' . $slug)); ?>&text=<?php echo urlencode($post['title']); ?>" 
                       target="_blank" class="share-btn" rel="noopener noreferrer">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode(url('/?page=blog&post_id=' . $post_id . '&slug=' . $slug)); ?>&title=<?php echo urlencode($post['title']); ?>" 
                       target="_blank" class="share-btn" rel="noopener noreferrer">
                        <i class="fab fa-linkedin-in"></i>
                    </a>
                    <a href="https://wa.me/?text=<?php echo urlencode($post['title'] . ' - ' . url('/?page=blog&post_id=' . $post_id . '&slug=' . $slug)); ?>" 
                       target="_blank" class="share-btn" rel="noopener noreferrer">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                </div>
            </div>
            
            <!-- Comments Section -->
            <div class="comments-section">
                <h3 class="comments-title">
                    Comments (<?php echo count($comments); ?>)
                </h3>
                
                <!-- Comments List -->
                <?php if (!empty($comments)): ?>
                    <?php foreach ($comments as $comment): ?>
                    <div class="comment">
                        <div class="comment-avatar">
                            <?php if (!empty($comment['website'])): ?>
                            <a href="<?php echo e($comment['website']); ?>" target="_blank" rel="nofollow">
                            <?php endif; ?>
                            
                            <?php if (!empty($comment['avatar'])): ?>
                                <img src="<?php echo e($comment['avatar']); ?>" alt="<?php echo e($comment['name']); ?>">
                            <?php else: ?>
                                <div class="comment-avatar-placeholder">
                                    <?php echo strtoupper(substr($comment['name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($comment['website'])): ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="comment-content">
                            <div class="comment-header">
                                <span class="comment-author">
                                    <?php if (!empty($comment['website'])): ?>
                                    <a href="<?php echo e($comment['website']); ?>" target="_blank" rel="nofollow" style="color: inherit;">
                                        <?php echo e($comment['name']); ?>
                                    </a>
                                    <?php else: ?>
                                        <?php echo e($comment['name']); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="comment-date">
                                    <i class="far fa-clock"></i> <?php echo date('M d, Y \a\t H:i', strtotime($comment['created_at'])); ?>
                                </span>
                            </div>
                            
                            <div class="comment-text">
                                <?php echo nl2br(e($comment['comment'])); ?>
                            </div>
                            
                            <button class="comment-reply-btn" onclick="replyToComment('<?php echo e($comment['name']); ?>')">
                                <i class="fas fa-reply"></i> Reply
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: var(--dark-gray); margin-bottom: 30px;">No comments yet. Be the first to comment!</p>
                <?php endif; ?>
                
                <!-- Comment Form -->
                <div class="comment-form">
                    <h3>Leave a Comment</h3>
                    <form id="commentForm" onsubmit="submitComment(event, <?php echo $post_id; ?>)">
                        <div class="comment-form-grid">
                            <div class="comment-form-group">
                                <label for="comment_name"><i class="fas fa-user"></i> Name *</label>
                                <input type="text" id="comment_name" name="name" class="comment-form-control" required>
                            </div>
                            
                            <div class="comment-form-group">
                                <label for="comment_email"><i class="fas fa-envelope"></i> Email *</label>
                                <input type="email" id="comment_email" name="email" class="comment-form-control" required>
                                <small style="color: var(--dark-gray);">Your email will not be published</small>
                            </div>
                            
                            <div class="comment-form-group">
                                <label for="comment_website"><i class="fas fa-globe"></i> Website</label>
                                <input type="url" id="comment_website" name="website" class="comment-form-control">
                            </div>
                            
                            <div class="comment-form-group full-width">
                                <label for="comment_content"><i class="fas fa-comment"></i> Comment *</label>
                                <textarea id="comment_content" name="comment" class="comment-form-control" rows="6" required></textarea>
                            </div>
                            
                            <div class="comment-form-group full-width">
                                <button type="submit" class="submit-comment-btn" id="submitCommentBtn">
                                    <i class="fas fa-paper-plane"></i> Post Comment
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Related Posts -->
            <?php if (!empty($related_posts)): ?>
            <section style="margin-top: 60px;">
                <h3 style="font-size: 1.8rem; color: var(--primary-color); margin-bottom: 30px;">Related Posts</h3>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 25px;">
                    <?php foreach ($related_posts as $related): ?>
                    <div style="background: var(--light-gray); border-radius: 10px; overflow: hidden; box-shadow: var(--box-shadow);">
                        <div style="height: 150px; overflow: hidden;">
                            <img src="<?php 
                                if (!empty($related['featured_image'])) {
                                    echo strpos($related['featured_image'], 'http') === 0 ? $related['featured_image'] : url($related['featured_image']);
                                } else {
                                    echo 'https://images.unsplash.com/photo-1499750310107-5fef28a66643?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80';
                                }
                            ?>" alt="<?php echo e($related['title']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                        <div style="padding: 20px;">
                            <h4 style="font-size: 1.1rem; margin-bottom: 10px;">
                                <a href="<?php echo url('/?page=blog&post_id=' . $related['post_id'] . '&slug=' . $related['slug']); ?>" style="color: var(--primary-color); text-decoration: none;">
                                    <?php echo e($related['title']); ?>
                                </a>
                            </h4>
                            <p style="color: var(--dark-gray); font-size: 0.9rem; margin-bottom: 10px;"><?php echo e(substr($related['excerpt'], 0, 80)) . '...'; ?></p>
                            <div style="color: var(--dark-gray); font-size: 0.85rem;">
                                <i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($related['published_at'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Comment Submission JavaScript -->
<script>
async function submitComment(event, postId) {
    event.preventDefault();
    
    const submitBtn = document.getElementById('submitCommentBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner"></span> Submitting...';
    submitBtn.disabled = true;
    
    const formData = new FormData(event.target);
    const data = {
        post_id: postId,
        name: formData.get('name'),
        email: formData.get('email'),
        website: formData.get('website'),
        comment: formData.get('comment')
    };
    
    try {
        const response = await fetch('<?php echo url('/api/comments.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('success', 'Your comment has been submitted and is awaiting approval.');
            event.target.reset();
        } else {
            showNotification('error', result.message || 'Failed to submit comment. Please try again.');
        }
    } catch (error) {
        console.error('Error:', error);
        showNotification('error', 'An error occurred. Please try again.');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

function replyToComment(authorName) {
    const commentField = document.getElementById('comment_content');
    commentField.value = `@${authorName} `;
    commentField.focus();
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
</script>

<!-- Link to blog.css -->
<link rel="stylesheet" href="<?php echo url('/pages/assets/css/blog.css'); ?>">