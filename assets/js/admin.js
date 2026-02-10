/**
 * Mira Edge Technologies - Admin Dashboard JavaScript
 */

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================
    // 1. SIDEBAR TOGGLE FUNCTIONALITY
    // ============================================
    
    const sidebarToggle = document.getElementById('sidebarToggle');
    const adminSidebar = document.getElementById('adminSidebar');
    const adminMain = document.querySelector('.admin-main');
    
    if (sidebarToggle && adminSidebar) {
        sidebarToggle.addEventListener('click', function() {
            adminSidebar.classList.toggle('active');
            this.classList.toggle('active');
            
            if (window.innerWidth > 1200) {
                if (adminSidebar.classList.contains('active')) {
                    adminMain.style.marginLeft = '0';
                    adminSidebar.style.transform = 'translateX(-100%)';
                } else {
                    adminMain.style.marginLeft = '260px';
                    adminSidebar.style.transform = 'translateX(0)';
                }
            }
        });
        
        // Close sidebar when clicking outside on mobile
        if (window.innerWidth <= 1200) {
            document.addEventListener('click', function(event) {
                if (!adminSidebar.contains(event.target) && 
                    !sidebarToggle.contains(event.target) && 
                    adminSidebar.classList.contains('active')) {
                    adminSidebar.classList.remove('active');
                    sidebarToggle.classList.remove('active');
                }
            });
        }
    }
    
    // ============================================
    // 2. NAVIGATION GROUP TOGGLE
    // ============================================
    
    document.querySelectorAll('.nav-group-header').forEach(header => {
        header.addEventListener('click', function() {
            const group = this.parentElement;
            group.classList.toggle('active');
            
            // Close other groups if this one opens
            if (group.classList.contains('active')) {
                document.querySelectorAll('.nav-group').forEach(otherGroup => {
                    if (otherGroup !== group && otherGroup.classList.contains('active')) {
                        otherGroup.classList.remove('active');
                    }
                });
            }
        });
    });
    
    // ============================================
    // 3. DROPDOWN TOGGLES
    // ============================================
    
    // Notifications dropdown
    const notificationsToggle = document.getElementById('notificationsToggle');
    const userProfileToggle = document.getElementById('userProfileToggle');
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(event) {
        // Notifications dropdown
        if (notificationsToggle && !notificationsToggle.contains(event.target)) {
            const notificationsMenu = document.querySelector('.notifications-menu');
            if (notificationsMenu) {
                notificationsMenu.style.opacity = '0';
                notificationsMenu.style.visibility = 'hidden';
                notificationsMenu.style.transform = 'translateY(-10px)';
            }
        }
        
        // User profile dropdown
        if (userProfileToggle && !userProfileToggle.contains(event.target)) {
            const userProfileMenu = document.querySelector('.user-profile-menu');
            if (userProfileMenu) {
                userProfileMenu.style.opacity = '0';
                userProfileMenu.style.visibility = 'hidden';
                userProfileMenu.style.transform = 'translateY(-10px)';
            }
        }
    });
    
    // ============================================
    // 4. SEARCH FUNCTIONALITY
    // ============================================
    
    const searchInput = document.querySelector('.search-input');
    if (searchInput) {
        let searchTimeout;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const query = this.value.trim();
                if (query.length >= 2) {
                    performSearch(query);
                }
            }, 300);
        });
        
        // Handle Enter key
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const query = this.value.trim();
                if (query) {
                    performSearch(query);
                }
            }
        });
    }
    
    // ============================================
    // 5. TABLE INTERACTIONS
    // ============================================
    
    // Add hover effects to table rows
    document.querySelectorAll('.table tbody tr').forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'var(--color-gray-50)';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });
    
    // ============================================
    // 6. CARD INTERACTIONS
    // ============================================
    
    // Add hover effects to cards
    document.querySelectorAll('.card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.boxShadow = 'var(--shadow-lg)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.boxShadow = 'var(--shadow-md)';
        });
    });
    
    // ============================================
    // 7. STATS CARDS ANIMATIONS
    // ============================================
    
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const statsObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                
                // Animate stat numbers
                const statValue = entry.target.querySelector('.stat-value');
                if (statValue && statValue.textContent) {
                    animateCounter(statValue);
                }
            }
        });
    }, observerOptions);
    
    document.querySelectorAll('.stat-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        statsObserver.observe(card);
    });
    
    // ============================================
    // 8. FORM VALIDATION
    // ============================================
    
    document.querySelectorAll('form').forEach(form => {
        const submitBtn = form.querySelector('button[type="submit"]');
        
        if (submitBtn) {
            form.addEventListener('submit', function() {
                // Disable submit button and show loading
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                
                // Re-enable after 5 seconds (in case of error)
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = submitBtn.dataset.originalText || 'Submit';
                }, 5000);
            });
        }
    });
    
    // ============================================
    // 9. NOTIFICATIONS
    // ============================================
    
    // Mark all as read
    const markAllReadBtn = document.querySelector('.mark-all-read');
    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Update notification count
            const notificationCount = document.querySelector('.notification-count');
            if (notificationCount) {
                notificationCount.textContent = '0';
                notificationCount.style.display = 'none';
            }
            
            // Mark all notifications as read visually
            document.querySelectorAll('.notification-item').forEach(item => {
                item.style.opacity = '0.6';
            });
            
            // Show success message
            showNotification('All notifications marked as read', 'success');
        });
    }
    
    // ============================================
    // 10. AJAX LOADING FOR DASHBOARD
    // ============================================
    
    // Function to load notifications via AJAX
    function loadNotifications() {
        fetch('/admin/api/notifications.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateNotificationsUI(data.notifications);
                }
            })
            .catch(error => console.error('Error loading notifications:', error));
    }
    
    // Load notifications every 30 seconds
    setInterval(loadNotifications, 30000);
    
    // ============================================
    // 11. RESPONSIVE BEHAVIOR
    // ============================================
    
    function handleResponsive() {
        if (window.innerWidth <= 1200) {
            adminSidebar.classList.remove('active');
            if (sidebarToggle) sidebarToggle.classList.remove('active');
        }
    }
    
    window.addEventListener('resize', handleResponsive);
    handleResponsive(); // Initial check
    
    // ============================================
    // 12. THEME SWITCHER (DARK/LIGHT MODE)
    // ============================================
    
    const themeToggle = document.querySelector('.theme-toggle');
    if (themeToggle) {
        const currentTheme = localStorage.getItem('admin-theme') || 'light';
        
        // Apply saved theme
        if (currentTheme === 'dark') {
            document.body.setAttribute('data-theme', 'dark');
            themeToggle.querySelector('i').classList.remove('fa-moon');
            themeToggle.querySelector('i').classList.add('fa-sun');
        }
        
        themeToggle.addEventListener('click', function() {
            const currentTheme = document.body.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            document.body.setAttribute('data-theme', newTheme);
            localStorage.setItem('admin-theme', newTheme);
            
            const icon = this.querySelector('i');
            if (newTheme === 'dark') {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        });
    }
});

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Perform search
 */
function performSearch(query) {
    // This would typically make an AJAX request
    console.log('Searching for:', query);
    
    // For now, just show a notification
    showNotification(`Searching for "${query}"...`, 'info');
}

/**
 * Animate counter numbers
 */
function animateCounter(element) {
    const target = parseInt(element.textContent.replace(/,/g, ''));
    if (isNaN(target)) return;
    
    let current = 0;
    const increment = target / 50;
    const timer = setInterval(() => {
        current += increment;
        element.textContent = Math.floor(current).toLocaleString();
        
        if (current >= target) {
            element.textContent = target.toLocaleString();
            clearInterval(timer);
        }
    }, 30);
}

/**
 * Show notification
 */
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas fa-${getNotificationIcon(type)}"></i>
            <span>${message}</span>
        </div>
        <button class="notification-close">&times;</button>
    `;
    
    // Add to page
    const container = document.querySelector('.notifications-container') || createNotificationsContainer();
    container.appendChild(notification);
    
    // Show with animation
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        closeNotification(notification);
    }, 5000);
    
    // Close button
    notification.querySelector('.notification-close').addEventListener('click', () => {
        closeNotification(notification);
    });
    
    return notification;
}

/**
 * Get notification icon based on type
 */
function getNotificationIcon(type) {
    const icons = {
        'success': 'check-circle',
        'error': 'exclamation-circle',
        'warning': 'exclamation-triangle',
        'info': 'info-circle'
    };
    return icons[type] || 'info-circle';
}

/**
 * Close notification
 */
function closeNotification(notification) {
    notification.classList.remove('show');
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 300);
}

/**
 * Create notifications container if it doesn't exist
 */
function createNotificationsContainer() {
    const container = document.createElement('div');
    container.className = 'notifications-container';
    container.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 1000;
        max-width: 350px;
    `;
    document.body.appendChild(container);
    return container;
}

/**
 * Update notifications UI
 */
function updateNotificationsUI(notifications) {
    const notificationsList = document.querySelector('.notifications-list');
    const notificationCount = document.querySelector('.notification-count');
    
    if (!notificationsList || !notificationCount) return;
    
    // Clear current notifications
    notificationsList.innerHTML = '';
    
    // Add new notifications
    notifications.forEach(notification => {
        const item = document.createElement('div');
        item.className = 'notification-item';
        item.innerHTML = `
            <div class="notification-icon">
                <i class="fas fa-${notification.icon}"></i>
            </div>
            <div class="notification-content">
                <p>${notification.message}</p>
                <span class="notification-time">${notification.time}</span>
            </div>
        `;
        notificationsList.appendChild(item);
    });
    
    // Update count
    notificationCount.textContent = notifications.length;
    notificationCount.style.display = notifications.length > 0 ? 'flex' : 'none';
}

/**
 * Format date for display
 */
function formatDate(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;
    
    if (diff < 60000) { // Less than 1 minute
        return 'Just now';
    } else if (diff < 3600000) { // Less than 1 hour
        const minutes = Math.floor(diff / 60000);
        return `${minutes} minute${minutes !== 1 ? 's' : ''} ago`;
    } else if (diff < 86400000) { // Less than 1 day
        const hours = Math.floor(diff / 3600000);
        return `${hours} hour${hours !== 1 ? 's' : ''} ago`;
    } else if (diff < 604800000) { // Less than 1 week
        const days = Math.floor(diff / 86400000);
        return `${days} day${days !== 1 ? 's' : ''} ago`;
    } else {
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric'
        });
    }
}

/**
 * Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Throttle function
 */
function throttle(func, limit) {
    let inThrottle;
    return function() {
        const args = arguments;
        const context = this;
        if (!inThrottle) {
            func.apply(context, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

// Export functions for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        showNotification,
        formatDate,
        debounce,
        throttle
    };
}