<?php
/**
 * Admin Sidebar Component
 */
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="admin-sidebar" id="adminSidebar">
    <nav class="sidebar-nav">

        <!-- Dashboard -->
        <a href="<?php echo url('admin/'); ?>" 
           class="nav-item <?php echo ($current_page == 'index.php' || $current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <div class="nav-icon">
                <i class="fas fa-tachometer-alt"></i>
            </div>
            <span class="nav-label">Dashboard</span>
        </a>

        <!-- Projects -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-project-diagram"></i></div>
                <span class="nav-label">Projects</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('admin/modules/projects/index.php'); ?>" class="nav-subitem">All Projects</a>
                <a href="<?php echo url('admin/modules/projects/add.php'); ?>" class="nav-subitem">Add New</a>
                <a href="<?php echo url('admin/modules/projects/categories.php'); ?>" class="nav-subitem">Categories</a>
            </div>
        </div>

        <!-- Services -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-cogs"></i></div>
                <span class="nav-label">Services</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('admin/modules/services/index.php'); ?>" class="nav-subitem">All Services</a>
                <a href="<?php echo url('admin/modules/services/add.php'); ?>" class="nav-subitem">Add Service</a>
                <a href="<?php echo url('admin/modules/services/packages.php'); ?>" class="nav-subitem">Packages</a>
                <a href="<?php echo url('admin/modules/services/orders.php'); ?>" class="nav-subitem">Orders</a>
                <a href="<?php echo url('admin/modules/services/categories.php'); ?>" class="nav-subitem">Categories</a>
            </div>
        </div>

        <!-- Blog -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-blog"></i></div>
                <span class="nav-label">Blog</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('admin/modules/blog/index.php'); ?>" class="nav-subitem">All Posts</a>
                <a href="<?php echo url('admin/modules/blog/add.php'); ?>" class="nav-subitem">Add New</a>
                <a href="<?php echo url('admin/modules/blog/categories.php'); ?>" class="nav-subitem">Categories</a>
                <a href="<?php echo url('admin/modules/blog/tags.php'); ?>" class="nav-subitem">Tags</a>
                <a href="<?php echo url('admin/modules/blog/comments.php'); ?>" class="nav-subitem">Comments</a>
            </div>
        </div>

        <!-- Team -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-users"></i></div>
                <span class="nav-label">Team</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('admin/modules/team/index.php'); ?>" class="nav-subitem">All Members</a>
                <a href="<?php echo url('admin/modules/team/add.php'); ?>" class="nav-subitem">Add Member</a>
                <a href="<?php echo url('admin/modules/team/teams.php'); ?>" class="nav-subitem">Teams</a>
                <a href="<?php echo url('admin/modules/team/roles.php'); ?>" class="nav-subitem">Roles</a>
            </div>
        </div>

        <!-- Jobs -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-briefcase"></i></div>
                <span class="nav-label">Careers</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('admin/modules/jobs/index.php'); ?>" class="nav-subitem">Job Listings</a>
                <a href="<?php echo url('admin/modules/jobs/add.php'); ?>" class="nav-subitem">Add Listing</a>
                <a href="<?php echo url('admin/modules/jobs/applications.php'); ?>" class="nav-subitem">Applications</a>
                <a href="<?php echo url('admin/modules/jobs/categories.php'); ?>" class="nav-subitem">Categories</a>
            </div>
        </div>

        <!-- Internal Projects -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-tasks"></i></div>
                <span class="nav-label">Internal Projects</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('admin/modules/internal-projects/index.php'); ?>" class="nav-subitem">All Projects</a>
                <a href="<?php echo url('admin/modules/internal-projects/add.php'); ?>" class="nav-subitem">New Project</a>
                <a href="<?php echo url('admin/modules/internal-projects/tasks.php'); ?>" class="nav-subitem">Tasks</a>
                <a href="<?php echo url('admin/modules/internal-projects/milestones.php'); ?>" class="nav-subitem">Milestones</a>
            </div>
        </div>

        <!-- Website -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-globe"></i></div>
                <span class="nav-label">Website</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('admin/modules/website/pages.php'); ?>" class="nav-subitem">Pages</a>
                <a href="<?php echo url('admin/modules/website/menu.php'); ?>" class="nav-subitem">Menu</a>
                <a href="<?php echo url('admin/modules/website/sliders.php'); ?>" class="nav-subitem">Sliders</a>
                <a href="<?php echo url('admin/modules/website/testimonials.php'); ?>" class="nav-subitem">Testimonials</a>
            </div>
        </div>

        <!-- Settings -->
        <div class="nav-group">
            <div class="nav-group-header">
                <div class="nav-icon"><i class="fas fa-sliders-h"></i></div>
                <span class="nav-label">Settings</span>
                <i class="fas fa-chevron-right group-arrow"></i>
            </div>
            <div class="nav-group-content">
                <a href="<?php echo url('admin/modules/settings/general.php'); ?>" class="nav-subitem">General</a>
                <a href="<?php echo url('admin/modules/settings/seo.php'); ?>" class="nav-subitem">SEO</a>
                <a href="<?php echo url('admin/modules/settings/contact.php'); ?>" class="nav-subitem">Contact</a>
                <a href="<?php echo url('admin/modules/settings/social.php'); ?>" class="nav-subitem">Social Media</a>
                <a href="<?php echo url('admin/modules/settings/email.php'); ?>" class="nav-subitem">Email</a>
                <a href="<?php echo url('admin/modules/settings/backup.php'); ?>" class="nav-subitem">Backup</a>
            </div>
        </div>

        <!-- Analytics -->
        <a href="<?php echo url('admin/modules/analytics/index.php'); ?>" class="nav-item">
            <div class="nav-icon"><i class="fas fa-chart-bar"></i></div>
            <span class="nav-label">Analytics</span>
        </a>

        <!-- Messages -->
        <a href="<?php echo url('admin/modules/messages/index.php'); ?>" class="nav-item">
            <div class="nav-icon"><i class="fas fa-envelope"></i></div>
            <span class="nav-label">Messages</span>
            <span class="nav-badge">24</span>
        </a>

        <!-- Back to Site -->
        <a href="<?php echo url(); ?>" class="nav-item nav-back-to-site" target="_blank">
            <div class="nav-icon"><i class="fas fa-external-link-alt"></i></div>
            <span class="nav-label">View Site</span>
        </a>

    </nav>
</aside>