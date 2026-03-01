<?php
/**
 * Job Applications Listing
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

if (!$session->isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    redirect(url('/login.php'));
}

if (!$session->isAdmin()) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect(url('/'));
}

$user = $session->getUser();
$job_id = $_GET['job_id'] ?? null;

// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['change_status']) && isset($_POST['application_id'])) {
        try {
            $stmt = $db->prepare("UPDATE job_applications SET application_status = ? WHERE application_id = ?");
            $stmt->execute([$_POST['application_status'], $_POST['application_id']]);
            $session->setFlash('success', 'Application status updated.');
        } catch (PDOException $e) {
            error_log('Application status error: ' . $e->getMessage());
            $session->setFlash('error', 'Error updating status.');
        }
    }
}

$sql = "SELECT ja.*, jl.job_title FROM job_applications ja LEFT JOIN job_listings jl ON ja.job_id = jl.job_id";
if ($job_id) {
    $sql .= " WHERE ja.job_id = " . (int)$job_id;
}
$sql .= " ORDER BY ja.applied_at DESC";
$stmt = $db->query($sql);
$applications = $stmt->fetchAll();

// Calculate stats
$total_applications = count($applications);
$status_counts = array_count_values(array_column($applications, 'application_status'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Applications | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-lg);
            margin-bottom: var(--space-xl);
        }

        .stat-card {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            display: flex;
            align-items: center;
            gap: var(--space-md);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--color-gray-200);
            transition: all var(--transition-normal);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-icon.total { background: var(--color-primary-50); color: var(--color-primary); }
        .stat-icon.submitted { background: var(--color-gray-50); color: var(--color-gray-600); }
        .stat-icon.reviewed { background: var(--color-primary-50); color: var(--color-primary); }
        .stat-icon.shortlisted { background: var(--color-info-50); color: var(--color-info); }
        .stat-icon.interviewed { background: var(--color-warning-50); color: var(--color-warning); }
        .stat-icon.hired { background: var(--color-success-50); color: var(--color-success); }
        .stat-icon.rejected { background: var(--color-danger-50); color: var(--color-danger); }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--color-gray-900);
            line-height: 1;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--color-gray-600);
            margin-top: var(--space-xs);
        }

        .applications-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: var(--space-lg);
        }

        .application-card {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--color-gray-200);
            transition: all var(--transition-normal);
            overflow: hidden;
        }

        .application-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .application-header {
            padding: var(--space-lg);
            border-bottom: 1px solid var(--color-gray-100);
            background: linear-gradient(135deg, var(--color-gray-50), var(--color-white));
        }

        .applicant-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--color-gray-900);
            margin-bottom: var(--space-xs);
        }

        .applicant-job {
            color: var(--color-primary);
            font-weight: 500;
            margin-bottom: var(--space-sm);
        }

        .application-meta {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            font-size: 0.875rem;
            color: var(--color-gray-600);
        }

        .application-body {
            padding: var(--space-lg);
        }

        .application-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-md);
            margin-bottom: var(--space-lg);
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: 0.875rem;
        }

        .detail-icon {
            width: 16px;
            color: var(--color-gray-500);
        }

        .application-actions {
            display: flex;
            gap: var(--space-sm);
            flex-wrap: wrap;
        }

        .status-badge {
            padding: var(--space-xs) var(--space-sm);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-submitted { background: var(--color-gray-100); color: var(--color-gray-700); }
        .status-reviewed { background: var(--color-primary-100); color: var(--color-primary); }
        .status-shortlisted { background: var(--color-info-100); color: var(--color-info); }
        .status-interviewed { background: var(--color-warning-100); color: var(--color-warning); }
        .status-hired { background: var(--color-success-100); color: var(--color-success); }
        .status-rejected { background: var(--color-danger-100); color: var(--color-danger); }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            overflow-y: auto;
        }

        .modal-content {
            background: var(--color-white);
            margin: 2rem auto;
            max-width: 800px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
        }

        .modal-header {
            padding: var(--space-lg);
            border-bottom: 1px solid var(--color-gray-200);
            background: var(--color-gray-50);
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--color-gray-900);
            margin: 0;
        }

        .modal-body {
            padding: var(--space-lg);
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-close {
            position: absolute;
            top: var(--space-md);
            right: var(--space-md);
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--color-gray-500);
            cursor: pointer;
            padding: var(--space-sm);
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
        }

        .modal-close:hover {
            background: var(--color-gray-100);
            color: var(--color-gray-700);
        }

        .application-detail-section {
            margin-bottom: var(--space-lg);
        }

        .detail-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--color-gray-900);
            margin-bottom: var(--space-md);
            padding-bottom: var(--space-xs);
            border-bottom: 2px solid var(--color-primary);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-md);
        }

        .detail-field {
            margin-bottom: var(--space-md);
        }

        .detail-label {
            font-weight: 600;
            color: var(--color-gray-700);
            margin-bottom: var(--space-xs);
            font-size: 0.875rem;
        }

        .detail-value {
            color: var(--color-gray-900);
            line-height: 1.5;
        }

        .cover-letter {
            background: var(--color-gray-50);
            padding: var(--space-md);
            border-radius: var(--radius-md);
            border-left: 4px solid var(--color-primary);
            white-space: pre-wrap;
        }

        .action-buttons {
            display: flex;
            gap: var(--space-sm);
            margin-top: var(--space-lg);
        }

        @media (max-width: 768px) {
            .applications-grid {
                grid-template-columns: 1fr;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .application-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Admin Header -->
    <?php include '../../includes/admin-header.php'; ?>

    <div class="admin-container">
        <!-- Sidebar -->
        <?php include '../../includes/admin-sidebar.php'; ?>

        <!-- Main Content -->
        <main class="admin-main">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-file-alt"></i>
                    Job Applications
                    <?php if ($job_id): ?>
                        <span class="page-subtitle">for Job #<?php echo $job_id; ?></span>
                    <?php endif; ?>
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/jobs/index.php'); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Jobs
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?php echo e($session->getFlash('success')); ?>
                            <button class="alert-close">&times;</button>
                        </div>
                    <?php endif; ?>

                    <?php if ($session->hasFlash('error')): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo e($session->getFlash('error')); ?>
                            <button class="alert-close">&times;</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_applications; ?></div>
                        <div class="stat-label">Total Applications</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon submitted">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $status_counts['submitted'] ?? 0; ?></div>
                        <div class="stat-label">Submitted</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon reviewed">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $status_counts['reviewed'] ?? 0; ?></div>
                        <div class="stat-label">Reviewed</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon shortlisted">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $status_counts['shortlisted'] ?? 0; ?></div>
                        <div class="stat-label">Shortlisted</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon interviewed">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $status_counts['interviewed'] ?? 0; ?></div>
                        <div class="stat-label">Interviewed</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon hired">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $status_counts['hired'] ?? 0; ?></div>
                        <div class="stat-label">Hired</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon rejected">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $status_counts['rejected'] ?? 0; ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>
            </div>

            <!-- Applications Grid -->
            <div class="applications-grid">
                <?php foreach ($applications as $app): ?>
                    <div class="application-card">
                        <div class="application-header">
                            <div class="applicant-name"><?php echo e($app['applicant_name']); ?></div>
                            <div class="applicant-job">
                                <i class="fas fa-briefcase"></i>
                                <?php echo e($app['job_title'] ?? 'Job Position'); ?>
                            </div>
                            <div class="application-meta">
                                <span><i class="fas fa-calendar"></i> <?php echo formatDate($app['applied_at'], 'M d, Y'); ?></span>
                                <span class="status-badge status-<?php echo $app['application_status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $app['application_status'])); ?>
                                </span>
                            </div>
                        </div>

                        <div class="application-body">
                            <div class="application-details">
                                <div class="detail-item">
                                    <i class="fas fa-envelope detail-icon"></i>
                                    <span><?php echo e($app['applicant_email']); ?></span>
                                </div>
                                <?php if (!empty($app['applicant_phone'])): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-phone detail-icon"></i>
                                        <span><?php echo e($app['applicant_phone']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($app['portfolio_url'])): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-globe detail-icon"></i>
                                        <a href="<?php echo e($app['portfolio_url']); ?>" target="_blank">Portfolio</a>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($app['linkedin_url'])): ?>
                                    <div class="detail-item">
                                        <i class="fab fa-linkedin detail-icon"></i>
                                        <a href="<?php echo e($app['linkedin_url']); ?>" target="_blank">LinkedIn</a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="application-actions">
                                <button class="btn btn-primary btn-sm" onclick="viewApplication(<?php echo $app['application_id']; ?>)">
                                    <i class="fas fa-eye"></i> View Details
                                </button>

                                <form method="post" style="display: inline-block;">
                                    <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                                    <select name="application_status" class="form-control form-control-sm" style="width: auto; display: inline-block; margin-right: var(--space-xs);">
                                        <option value="submitted" <?php echo ($app['application_status']=='submitted')? 'selected':''; ?>>Submitted</option>
                                        <option value="reviewed" <?php echo ($app['application_status']=='reviewed')? 'selected':''; ?>>Reviewed</option>
                                        <option value="shortlisted" <?php echo ($app['application_status']=='shortlisted')? 'selected':''; ?>>Shortlisted</option>
                                        <option value="interviewed" <?php echo ($app['application_status']=='interviewed')? 'selected':''; ?>>Interviewed</option>
                                        <option value="rejected" <?php echo ($app['application_status']=='rejected')? 'selected':''; ?>>Rejected</option>
                                        <option value="hired" <?php echo ($app['application_status']=='hired')? 'selected':''; ?>>Hired</option>
                                    </select>
                                    <button class="btn btn-outline btn-sm" type="submit" name="change_status" title="Update Status">
                                        <i class="fas fa-save"></i>
                                    </button>
                                </form>

                                <?php if (!empty($app['resume_path'])): ?>
                                    <a href="<?php echo e($app['resume_path']); ?>" class="btn btn-outline btn-sm" target="_blank" title="Download Resume">
                                        <i class="fas fa-file-pdf"></i> Resume
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($applications)): ?>
                    <div class="card" style="grid-column: 1 / -1; text-align: center; padding: var(--space-xl);">
                        <i class="fas fa-inbox" style="font-size: 3rem; color: var(--color-gray-300); margin-bottom: var(--space-lg);"></i>
                        <h3 style="color: var(--color-gray-600); margin-bottom: var(--space-md);">No Applications Yet</h3>
                        <p style="color: var(--color-gray-500);">Applications will appear here once candidates apply for jobs.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Application Details Modal -->
    <div id="applicationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Application Details</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="applicationDetails">
                <!-- Application details will be loaded here -->
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
    <script>
        // Application data for modal
        const applications = <?php echo json_encode($applications); ?>;

        function viewApplication(applicationId) {
            const app = applications.find(a => a.application_id == applicationId);
            if (!app) return;

            const statusClass = `status-${app.application_status}`;
            const statusText = app.application_status.charAt(0).toUpperCase() + app.application_status.slice(1).replace('_', ' ');

            const detailsHtml = `
                <div class="application-detail-section">
                    <h3 class="detail-section-title">
                        <i class="fas fa-user"></i> Applicant Information
                    </h3>
                    <div class="detail-grid">
                        <div class="detail-field">
                            <div class="detail-label">Full Name</div>
                            <div class="detail-value">${escapeHtml(app.applicant_name)}</div>
                        </div>
                        <div class="detail-field">
                            <div class="detail-label">Email Address</div>
                            <div class="detail-value">
                                <a href="mailto:${escapeHtml(app.applicant_email)}">${escapeHtml(app.applicant_email)}</a>
                            </div>
                        </div>
                        <div class="detail-field">
                            <div class="detail-label">Phone Number</div>
                            <div class="detail-value">${app.applicant_phone ? escapeHtml(app.applicant_phone) : 'Not provided'}</div>
                        </div>
                        <div class="detail-field">
                            <div class="detail-label">Applied For</div>
                            <div class="detail-value">${escapeHtml(app.job_title || 'Position')}</div>
                        </div>
                        <div class="detail-field">
                            <div class="detail-label">Application Date</div>
                            <div class="detail-value">${new Date(app.applied_at).toLocaleDateString('en-US', {
                                year: 'numeric',
                                month: 'long',
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit'
                            })}</div>
                        </div>
                        <div class="detail-field">
                            <div class="detail-label">Status</div>
                            <div class="detail-value">
                                <span class="status-badge ${statusClass}">${statusText}</span>
                            </div>
                        </div>
                    </div>
                </div>

                ${app.cover_letter ? `
                <div class="application-detail-section">
                    <h3 class="detail-section-title">
                        <i class="fas fa-file-alt"></i> Cover Letter
                    </h3>
                    <div class="cover-letter">${escapeHtml(app.cover_letter)}</div>
                </div>
                ` : ''}

                <div class="application-detail-section">
                    <h3 class="detail-section-title">
                        <i class="fas fa-link"></i> Links & Documents
                    </h3>
                    <div class="detail-grid">
                        ${app.portfolio_url ? `
                        <div class="detail-field">
                            <div class="detail-label">Portfolio Website</div>
                            <div class="detail-value">
                                <a href="${escapeHtml(app.portfolio_url)}" target="_blank" class="btn btn-outline btn-sm">
                                    <i class="fas fa-external-link-alt"></i> Visit Portfolio
                                </a>
                            </div>
                        </div>
                        ` : ''}

                        ${app.linkedin_url ? `
                        <div class="detail-field">
                            <div class="detail-label">LinkedIn Profile</div>
                            <div class="detail-value">
                                <a href="${escapeHtml(app.linkedin_url)}" target="_blank" class="btn btn-outline btn-sm">
                                    <i class="fab fa-linkedin"></i> View LinkedIn
                                </a>
                            </div>
                        </div>
                        ` : ''}

                        ${app.resume_path ? `
                        <div class="detail-field">
                            <div class="detail-label">Resume/CV</div>
                            <div class="detail-value">
                                <a href="${escapeHtml(app.resume_path)}" target="_blank" class="btn btn-primary btn-sm">
                                    <i class="fas fa-file-pdf"></i> Download Resume
                                </a>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                </div>

                <div class="action-buttons">
                    <button class="btn btn-outline" onclick="closeModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            `;

            document.getElementById('applicationDetails').innerHTML = detailsHtml;
            document.getElementById('applicationModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('applicationModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('applicationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('applicationModal').style.display === 'block') {
                closeModal();
            }
        });

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
    </script>
</body>
</html>