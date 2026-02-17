<?php
/**
 * Developer Header Component
 */
$user = $session->getUser();
?>
<header class="admin-header developer-header">
    <div class="header-left">
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
        
        <div class="logo">
            <a href="/developer/">
                <span class="logo-text">MIRA</span>
                <span class="logo-accent">EDGE</span>
                <span class="logo-badge">Developer</span>
            </a>
        </div>
    </div>
    
    <div class="header-right">
        <!-- Quick Project Search -->
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" 
                   placeholder="Search your projects, tasks..." 
                   class="search-input" 
                   id="devSearch"
                   autocomplete="off">
            <div class="search-results" id="searchResults"></div>
        </div>
        
        <!-- Notifications -->
        <div class="notifications-dropdown">
            <button class="notifications-toggle" id="notificationsToggle">
                <i class="fas fa-bell"></i>
                <?php
                // Get unread notifications count
                try {
                    $stmt = $db->prepare("
                        SELECT COUNT(*) as count 
                        FROM notifications 
                        WHERE user_id = ? AND is_read = 0
                    ");
                    $stmt->execute([$user['user_id']]);
                    $unreadCount = $stmt->fetch()['count'];
                } catch (PDOException $e) {
                    $unreadCount = 0;
                }
                ?>
                <?php if ($unreadCount > 0): ?>
                    <span class="notification-count"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </button>
            <div class="notifications-menu">
                <div class="notifications-header">
                    <h4>Notifications</h4>
                    <?php if ($unreadCount > 0): ?>
                        <a href="#" class="mark-all-read" id="markAllRead">Mark all as read</a>
                    <?php endif; ?>
                </div>
                <div class="notifications-list" id="notificationsList">
                    <?php
                    try {
                        $stmt = $db->prepare("
                            SELECT * FROM notifications 
                            WHERE user_id = ? 
                            ORDER BY created_at DESC 
                            LIMIT 5
                        ");
                        $stmt->execute([$user['user_id']]);
                        $notifications = $stmt->fetchAll();
                        
                        if (empty($notifications)):
                    ?>
                        <div class="notification-item text-center">
                            <div class="notification-content">
                                <p>No notifications</p>
                            </div>
                        </div>
                    <?php 
                        else:
                            foreach ($notifications as $notif):
                    ?>
                        <div class="notification-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>" 
                             data-id="<?php echo $notif['notification_id']; ?>">
                            <div class="notification-icon">
                                <i class="fas fa-<?php 
                                    echo match($notif['notification_type']) {
                                        'task' => 'tasks',
                                        'project' => 'project-diagram',
                                        'order' => 'shopping-cart',
                                        'message' => 'envelope',
                                        default => 'bell'
                                    };
                                ?>"></i>
                            </div>
                            <div class="notification-content">
                                <p><?php echo e($notif['title']); ?></p>
                                <small class="notification-time">
                                    <?php echo formatDate($notif['created_at'], 'M d, H:i'); ?>
                                </small>
                            </div>
                        </div>
                    <?php 
                            endforeach;
                        endif;
                    } catch (PDOException $e) {
                        error_log("Notification fetch error: " . $e->getMessage());
                    }
                    ?>
                </div>
                <div class="notifications-footer">
                    <a href="/developer/notifications.php" class="view-all">View all notifications</a>
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
                    <span class="user-role">Developer</span>
                </div>
                <i class="fas fa-chevron-down"></i>
            </button>
            <div class="user-profile-menu">
                <a href="/developer/profile.php" class="profile-menu-item">
                    <i class="fas fa-user"></i>
                    <span>My Profile</span>
                </a>
                <a href="/developer/settings.php" class="profile-menu-item">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
                <div class="profile-menu-divider"></div>
                <a href="/developer/help.php" class="profile-menu-item">
                    <i class="fas fa-question-circle"></i>
                    <span>Help & Support</span>
                </a>
                <a href="/logout.php" class="profile-menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Include developer-specific CSS -->
<style>
/* Developer Header Enhancements */
.developer-header .logo-badge {
    background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    color: white;
}

/* Search Results Dropdown */
.search-box {
    position: relative;
}

.search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-lg);
    max-height: 400px;
    overflow-y: auto;
    z-index: var(--z-dropdown);
    display: none;
    margin-top: 5px;
}

.search-results.active {
    display: block;
    animation: slideInDown 0.3s ease-out;
}

.search-result-item {
    display: flex;
    align-items: center;
    padding: var(--space-md);
    border-bottom: 1px solid var(--color-gray-100);
    transition: background-color var(--transition-fast);
    cursor: pointer;
}

.search-result-item:hover {
    background-color: var(--color-gray-50);
}

.search-result-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md);
    background: var(--color-gray-100);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: var(--space-md);
    color: var(--color-gray-600);
}

.search-result-info {
    flex: 1;
}

.search-result-title {
    font-size: 0.95rem;
    font-weight: 500;
    color: var(--color-gray-900);
    margin-bottom: 2px;
}

.search-result-meta {
    font-size: 0.75rem;
    color: var(--color-gray-500);
    display: flex;
    gap: var(--space-sm);
}

.search-result-badge {
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: var(--radius-full);
    background: var(--color-gray-200);
    color: var(--color-gray-700);
}

.search-result-badge.high {
    background: rgba(244, 67, 54, 0.1);
    color: var(--color-error);
}

.search-result-badge.medium {
    background: rgba(255, 152, 0, 0.1);
    color: var(--color-warning);
}

/* Notification Item States */
.notification-item.unread {
    background-color: var(--color-gray-50);
    border-left: 3px solid var(--color-primary);
}

.notification-item.unread .notification-content p {
    font-weight: 500;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .developer-header .user-info {
        display: none;
    }
    
    .search-box {
        display: none;
    }
}
</style>

<script>
// Developer header specific JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Search functionality
    const searchInput = document.getElementById('devSearch');
    const searchResults = document.getElementById('searchResults');
    
    if (searchInput) {
        let searchTimeout;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => {
                    performDeveloperSearch(query);
                }, 300);
            } else {
                searchResults.classList.remove('active');
            }
        });
        
        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('active');
            }
        });
    }
    
    // Mark all notifications as read
    const markAllRead = document.getElementById('markAllRead');
    if (markAllRead) {
        markAllRead.addEventListener('click', function(e) {
            e.preventDefault();
            
            fetch('/api/notifications.php?action=mark_all_read', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update UI
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    
                    const notificationCount = document.querySelector('.notification-count');
                    if (notificationCount) {
                        notificationCount.remove();
                    }
                    
                    showNotification('All notifications marked as read', 'success');
                }
            })
            .catch(error => console.error('Error:', error));
        });
    }
});

function performDeveloperSearch(query) {
    fetch(`/api/search.php?q=${encodeURIComponent(query)}&type=developer`)
        .then(response => response.json())
        .then(data => {
            const searchResults = document.getElementById('searchResults');
            if (!searchResults) return;
            
            if (data.results && data.results.length > 0) {
                let html = '';
                data.results.forEach(result => {
                    const icon = result.type === 'project' ? 'project-diagram' : 
                                result.type === 'task' ? 'tasks' : 'flag-checkered';
                    
                    html += `
                        <a href="${result.url}" class="search-result-item">
                            <div class="search-result-icon">
                                <i class="fas fa-${icon}"></i>
                            </div>
                            <div class="search-result-info">
                                <div class="search-result-title">${result.title}</div>
                                <div class="search-result-meta">
                                    <span>${result.project_name || ''}</span>
                                    ${result.priority ? `
                                        <span class="search-result-badge ${result.priority}">
                                            ${result.priority}
                                        </span>
                                    ` : ''}
                                </div>
                            </div>
                        </a>
                    `;
                });
                searchResults.innerHTML = html;
                searchResults.classList.add('active');
            } else {
                searchResults.innerHTML = `
                    <div class="search-result-item">
                        <div class="search-result-info">
                            <div class="search-result-title">No results found</div>
                        </div>
                    </div>
                `;
                searchResults.classList.add('active');
            }
        })
        .catch(error => console.error('Search error:', error));
}
</script>