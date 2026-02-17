<?php
/**
 * Developer Sidebar Component
 */
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="admin-sidebar developer-sidebar" id="devSidebar">
    <nav class="sidebar-nav">
        <!-- Dashboard -->
        <a href="<?php echo url('developer/dashboard.php'); ?>" 
           class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <div class="nav-icon">
                <i class="fas fa-tachometer-alt"></i>
            </div>
            <span class="nav-label">Dashboard</span>
        </a>

        <!-- My Projects -->
        <div class="nav-group <?php echo (strpos($_SERVER['REQUEST_URI'], 'projects') !== false) ? 'active' : ''; ?>">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-project-diagram"></i></div>
                <span class="nav-label">My Projects</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('developer/projects.php'); ?>" class="nav-subitem">All Projects</a>
                <a href="<?php echo url('developer/projects.php?status=active'); ?>" class="nav-subitem">Active Projects</a>
                <a href="<?php echo url('developer/projects.php?status=completed'); ?>" class="nav-subitem">Completed</a>
                <a href="<?php echo url('developer/projects.php?filter=my'); ?>" class="nav-subitem">My Assignments</a>
            </div>
        </div>

        <!-- My Tasks -->
        <div class="nav-group <?php echo (strpos($_SERVER['REQUEST_URI'], 'tasks') !== false) ? 'active' : ''; ?>">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-tasks"></i></div>
                <span class="nav-label">My Tasks</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('developer/tasks.php'); ?>" class="nav-subitem">All Tasks</a>
                <a href="<?php echo url('developer/tasks.php?status=pending'); ?>" class="nav-subitem">Pending</a>
                <a href="<?php echo url('developer/tasks.php?status=in_progress'); ?>" class="nav-subitem">In Progress</a>
                <a href="<?php echo url('developer/tasks.php?status=completed'); ?>" class="nav-subitem">Completed</a>
                <a href="<?php echo url('developer/tasks.php?priority=high'); ?>" class="nav-subitem">High Priority</a>
            </div>
        </div>

        <!-- My Teams -->
        <div class="nav-group <?php echo (strpos($_SERVER['REQUEST_URI'], 'teams') !== false) ? 'active' : ''; ?>">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-users"></i></div>
                <span class="nav-label">My Teams</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('developer/teams.php'); ?>" class="nav-subitem">My Teams</a>
                <a href="<?php echo url('developer/teams.php?view=members'); ?>" class="nav-subitem">Team Members</a>
                <a href="<?php echo url('developer/teams.php?view=performance'); ?>" class="nav-subitem">Team Performance</a>
            </div>
        </div>

        <!-- Milestones -->
        <a href="<?php echo url('developer/milestones.php'); ?>" class="nav-item">
            <div class="nav-icon"><i class="fas fa-flag-checkered"></i></div>
            <span class="nav-label">Milestones</span>
            <?php
            // Get upcoming milestones count
            try {
                $stmt = $db->prepare("
                    SELECT COUNT(*) as count 
                    FROM project_milestones pm
                    INNER JOIN project_tasks pt ON pm.internal_project_id = pt.internal_project_id
                    WHERE pt.assigned_to = ? 
                    AND pm.due_date >= CURDATE() 
                    AND pm.is_completed = 0
                ");
                $stmt->execute([$user['user_id']]);
                $upcomingCount = $stmt->fetch()['count'];
                if ($upcomingCount > 0):
            ?>
                <span class="nav-badge"><?php echo $upcomingCount; ?></span>
            <?php 
                endif;
            } catch (PDOException $e) {
                // Silent fail
            }
            ?>
        </a>

        <!-- Time Tracking -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-clock"></i></div>
                <span class="nav-label">Time Tracking</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('developer/time-tracking.php'); ?>" class="nav-subitem">Today's Log</a>
                <a href="<?php echo url('developer/time-tracking.php?view=weekly'); ?>" class="nav-subitem">Weekly Report</a>
                <a href="<?php echo url('developer/time-tracking.php?view=monthly'); ?>" class="nav-subitem">Monthly Summary</a>
            </div>
        </div>

        <!-- My Reports -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-chart-bar"></i></div>
                <span class="nav-label">Reports</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('developer/reports/performance.php'); ?>" class="nav-subitem">My Performance</a>
                <a href="<?php echo url('developer/reports/tasks.php'); ?>" class="nav-subitem">Task Completion</a>
                <a href="<?php echo url('developer/reports/projects.php'); ?>" class="nav-subitem">Project Progress</a>
            </div>
        </div>

        <!-- Messages -->
        <a href="<?php echo url('developer/messages.php'); ?>" class="nav-item">
            <div class="nav-icon"><i class="fas fa-envelope"></i></div>
            <span class="nav-label">Messages</span>
            <?php
            // Get unread messages count
            try {
                $stmt = $db->prepare("
                    SELECT COUNT(*) as count 
                    FROM notifications 
                    WHERE user_id = ? 
                    AND notification_type = 'message' 
                    AND is_read = 0
                ");
                $stmt->execute([$user['user_id']]);
                $unreadMessages = $stmt->fetch()['count'];
                if ($unreadMessages > 0):
            ?>
                <span class="nav-badge"><?php echo $unreadMessages; ?></span>
            <?php 
                endif;
            } catch (PDOException $e) {
                // Silent fail
            }
            ?>
        </a>

        <!-- Resources -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-folder-open"></i></div>
                <span class="nav-label">Resources</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('developer/resources/documents.php'); ?>" class="nav-subitem">Documents</a>
                <a href="<?php echo url('developer/resources/guides.php'); ?>" class="nav-subitem">Guides</a>
                <a href="<?php echo url('developer/resources/templates.php'); ?>" class="nav-subitem">Templates</a>
            </div>
        </div>

        <!-- Settings -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-cog"></i></div>
                <span class="nav-label">Settings</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('developer/profile.php'); ?>" class="nav-subitem">Profile</a>
                <a href="<?php echo url('developer/notifications.php'); ?>" class="nav-subitem">Notifications</a>
                <a href="<?php echo url('developer/settings/preferences.php'); ?>" class="nav-subitem">Preferences</a>
            </div>
        </div>


        <!-- Divider -->
        <div class="nav-group-divider"></div>

        <!-- Back to Site -->
        <a href="<?php echo url('/'); ?>" class="nav-item nav-back-to-site" target="_blank">
            <div class="nav-icon"><i class="fas fa-external-link-alt"></i></div>
            <span class="nav-label">View Site</span>
        </a>
        <a href="<?php echo url('/logout.php'); ?>" class="nav-item nav-back-to-site" target="_blank">
            <div class="nav-icon"><i class="fas fa-user"></i></div>
            <span class="nav-label">Sign Out</span>
        </a>
    </nav>

    <!-- Sidebar Footer with Quick Status -->
    <!-- <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar">
                <?php if (!empty($user['profile_image'])): ?>
                    <img src="<?php echo e($user['profile_image']); ?>" alt="<?php echo e($user['first_name']); ?>">
                <?php else: ?>
                    <div class="avatar-placeholder">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></span>
                <span class="user-email"><?php echo e($user['email']); ?></span>
            </div>
        </div>
        <div class="quick-status">
            <?php
            try {
                // Get today's tasks
                $stmt = $db->prepare("
                    SELECT 
                        COUNT(*) as total_tasks,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                    FROM project_tasks 
                    WHERE assigned_to = ? 
                    AND DATE(due_date) = CURDATE()
                ");
                $stmt->execute([$user['user_id']]);
                $todayTasks = $stmt->fetch();
            ?>
            <div class="status-item">
                <span class="status-label">Today's Tasks</span>
                <span class="status-value">
                    <?php echo $todayTasks['completed'] ?? 0; ?>/<?php echo $todayTasks['total_tasks'] ?? 0; ?>
                </span>
            </div>
            <?php 
            } catch (PDOException $e) {
                // Silent fail
            }
            ?>
        </div>
    </div> -->
</aside>

<style>
/* Developer Sidebar Specific Styles */
.developer-sidebar {
    background: linear-gradient(180deg, var(--color-white) 0%, var(--color-gray-50) 100%);
}

.developer-sidebar .nav-item.active {
    background: linear-gradient(90deg, var(--color-primary-50) 0%, transparent 100%);
    border-right: 3px solid var(--color-primary);
}

.developer-sidebar .nav-group-header:hover {
    background: linear-gradient(90deg, var(--color-gray-50) 0%, transparent 100%);
}

.developer-sidebar .nav-subitem {
    position: relative;
    padding-left: calc(var(--space-xl) + 28px);
}

.developer-sidebar .nav-subitem::before {
    content: '';
    position: absolute;
    left: var(--space-xl);
    top: 50%;
    transform: translateY(-50%);
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background-color: var(--color-gray-400);
    transition: all var(--transition-fast);
}

.developer-sidebar .nav-subitem:hover::before {
    background-color: var(--color-primary);
    transform: translateY(-50%) scale(1.2);
}

.developer-sidebar .nav-subitem.active {
    color: var(--color-primary);
    font-weight: 500;
}

.developer-sidebar .nav-subitem.active::before {
    background-color: var(--color-primary);
    width: 8px;
    height: 8px;
}

/* Sidebar Footer */
.sidebar-footer {
    padding: var(--space-lg);
    border-top: 1px solid var(--color-gray-200);
    background: var(--color-white);
}

.sidebar-user {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}

.quick-status {
    display: flex;
    justify-content: space-between;
    padding: var(--space-sm) 0;
    font-size: 0.75rem;
}

.status-item {
    display: flex;
    flex-direction: column;
}

.status-label {
    color: var(--color-gray-500);
    margin-bottom: 2px;
}

.status-value {
    font-weight: 600;
    color: var(--color-gray-900);
}

/* Dark Mode Support */
@media (prefers-color-scheme: dark) {
    .developer-sidebar {
        background: linear-gradient(180deg, var(--color-gray-900) 0%, var(--color-gray-800) 100%);
    }
    
    .developer-sidebar .nav-item,
    .developer-sidebar .nav-group-header {
        color: var(--color-gray-300);
    }
    
    .developer-sidebar .nav-item:hover,
    .developer-sidebar .nav-group-header:hover {
        color: var(--color-white);
    }
}
</style>