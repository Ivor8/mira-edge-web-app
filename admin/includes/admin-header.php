<?php
/**
 * Admin Header Component
 */
?>
<header class="admin-header">
    <div class="header-left">
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
        
        <div class="logo">
            <a href="/admin/">
                <span class="logo-text">MIRA</span>
                <span class="logo-accent">EDGE</span>
                <span class="logo-badge">Admin</span>
            </a>
        </div>
    </div>
    
    <div class="header-right">
        <!-- Search -->
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="Search..." class="search-input">
        </div>
        
        <!-- Notifications -->
        <div class="notifications-dropdown">
            <button class="notifications-toggle" id="notificationsToggle">
                <i class="fas fa-bell"></i>
                <span class="notification-count">3</span>
            </button>
            <div class="notifications-menu">
                <div class="notifications-header">
                    <h4>Notifications</h4>
                    <a href="#" class="mark-all-read">Mark all as read</a>
                </div>
                <div class="notifications-list">
                    <!-- Notification items will be loaded via AJAX -->
                    <div class="notification-item">
                        <div class="notification-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="notification-content">
                            <p>New order received from John Doe</p>
                            <span class="notification-time">2 minutes ago</span>
                        </div>
                    </div>
                    <div class="notification-item">
                        <div class="notification-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="notification-content">
                            <p>New user registered</p>
                            <span class="notification-time">1 hour ago</span>
                        </div>
                    </div>
                </div>
                <div class="notifications-footer">
                    <a href="modules/notifications.php" class="view-all">View all notifications</a>
                </div>
            </div>
        </div>
        
        <!-- User Profile -->
        <div class="user-profile-dropdown">
            <button class="user-profile-toggle" id="userProfileToggle">
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
                    <!-- <span class="user-role"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span> -->
                </div>
                <i class="fas fa-chevron-down"></i>
            </button>
            <div class="user-profile-menu">
                <a href="<?php echo url('/admin/profile.php'); ?>" class="profile-menu-item">
                    <i class="fas fa-user"></i>
                    <span>My Profile</span>
                </a>
                <a href="/admin/settings.php" class="profile-menu-item">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
                <div class="profile-menu-divider"></div>
                <a href="<?php echo url('/logout.php'); ?>" class="profile-menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>
</header>