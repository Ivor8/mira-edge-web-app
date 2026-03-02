<?php
/**
 * Reviews Page - Mira Edge Technologies
 * Displays approved reviews and provides a form for visitors to submit new ones
 */

$errors = [];
success: $success_message = '';
try {
    $db = Database::getInstance()->getConnection();

    // handle submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
        $name = trim($_POST['name'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $link = trim($_POST['link'] ?? '');

        if (empty($name)) {
            $errors[] = 'Please enter your name.';
        }
        if (empty($content) || strlen($content) < 10) {
            $errors[] = 'Please enter a review (at least 10 characters).';
        }

        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO reviews (name, content, link, source, status, created_at) VALUES (?, ?, ?, 'website', 'pending', NOW())");
            $stmt->execute([$name, $content, $link ?: null]);
            $success_message = 'Thank you! Your review has been submitted and is awaiting approval.';
        }
    }

    // fetch approved reviews
    $stmt = $db->prepare("SELECT * FROM reviews WHERE status = 'approved' ORDER BY created_at DESC");
    $stmt->execute();
    $reviews = $stmt->fetchAll();

    // SEO metadata (could fetch from seo_metadata table if exists)
    $page_title = 'Reviews | Mira Edge Technologies';
    $page_description = 'Read what clients are saying about Mira Edge Technologies. Submit your own review!';
    $canonical_url = url('/?page=reviews');
} catch (PDOException $e) {
    error_log('Reviews page error: ' . $e->getMessage());
    $reviews = [];
}
?>

<!-- Page Specific Meta Tags -->
<meta name="description" content="<?php echo e($page_description); ?>">
<link rel="canonical" href="<?php echo e($canonical_url); ?>">

<!-- Open Graph -->
<meta property="og:title" content="<?php echo e($page_title); ?>">
<meta property="og:description" content="<?php echo e($page_description); ?>">
<meta property="og:url" content="<?php echo e($canonical_url); ?>">
<meta property="og:type" content="website">

<!-- Content -->
<section class="reviews-page" style="padding-top: 120px; padding-bottom: 80px;">
    <div class="container">
        <h1 class="section-title" style="text-align:center;">What Our Clients Say</h1>
        <p class="section-subtitle" style="text-align:center;">Testimonials & reviews from our amazing customers</p>

        <?php if (!empty($reviews)): ?>
        <div class="reviews-grid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:30px; margin-top:50px;">
            <?php foreach ($reviews as $rev): ?>
            <div class="review-card" style="background:white; padding:30px; border-radius:12px; box-shadow:var(--box-shadow); position:relative;">
                <p style="font-style:italic; line-height:1.7; color:var(--dark-color);"><?php echo e($rev['content']); ?></p>
                <div style="margin-top:20px; font-weight:600; color:var(--primary-color);">
                    &mdash; <?php echo e($rev['name']); ?><?php if (!empty($rev['link'])): ?> (<a href="<?php echo e($rev['link']); ?>" target="_blank" rel="noopener noreferrer">source</a>)<?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="text-align:center; margin-top:50px; color:var(--dark-gray);">No reviews yet. Be the first to leave one!</p>
        <?php endif; ?>

        <!-- Submission Form -->
        <section id="review-form" style="margin-top:80px; max-width:700px; margin-left:auto; margin-right:auto;">
            <h2 style="text-align:center; color:var(--primary-color); margin-bottom:30px;">Submit Your Review</h2>
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success" style="margin-bottom:20px;">
                    <?php echo e($success_message); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error" style="margin-bottom:20px;">
                    <?php echo e(implode('<br>',$errors)); ?>
                </div>
            <?php endif; ?>
            <form method="post" style="display:grid; gap:20px;">
                <div class="form-group">
                    <label for="rev_name">Name *</label>
                    <input type="text" id="rev_name" name="name" class="form-control" value="<?php echo e($_POST['name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="rev_content">Review *</label>
                    <textarea id="rev_content" name="content" class="form-control" rows="5" required><?php echo e($_POST['content'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="rev_link">Link (optional)</label>
                    <input type="url" id="rev_link" name="link" class="form-control" value="<?php echo e($_POST['link'] ?? ''); ?>">
                </div>
                <div class="form-group" style="text-align:center; margin-top:10px;">
                    <button type="submit" name="submit_review" class="btn">Submit Review</button>
                </div>
            </form>
        </section>

    </div>
</section>

<!-- simple styling for reviews page -->
<style>
.reviews-page .section-title { font-size:2.5rem; }
.reviews-page .section-subtitle { font-size:1.2rem; color:var(--dark-gray); }
.review-card a { color: var(--primary-color); text-decoration: underline; }
@media(max-width:768px){ .reviews-grid { grid-template-columns:1fr !important; } }
</style>
