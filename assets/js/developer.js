/**
 * Mira Edge Technologies - Developer Dashboard JavaScript
 */

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {

    // ============================================
    // 1. SIDEBAR TOGGLE FUNCTIONALITY
    // ============================================

    const sidebarToggle = document.getElementById('sidebarToggle');
    const devSidebar = document.getElementById('devSidebar');
    const adminMain = document.querySelector('.admin-main');

    if (sidebarToggle && devSidebar) {
        sidebarToggle.addEventListener('click', function() {
            devSidebar.classList.toggle('active');
            this.classList.toggle('active');

            // Only adjust margin on larger screens
            if (window.innerWidth > 1200) {
                if (devSidebar.classList.contains('active')) {
                    adminMain.style.marginLeft = '0';
                    devSidebar.style.transform = 'translateX(-100%)';
                } else {
                    adminMain.style.marginLeft = '260px';
                    devSidebar.style.transform = 'translateX(0)';
                }
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (!devSidebar.contains(event.target) &&
                !sidebarToggle.contains(event.target) &&
                devSidebar.classList.contains('active') &&
                window.innerWidth <= 1200) {
                devSidebar.classList.remove('active');
                sidebarToggle.classList.remove('active');
            }
        });
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
            // Preserve original button content so we can restore it
            if (!submitBtn.dataset.originalText) {
                submitBtn.dataset.originalText = submitBtn.innerHTML.trim();
            }

            form.addEventListener('submit', function(event) {
                // Allow native validation to run first; if it's invalid, don't proceed
                if (!form.checkValidity()) return;

                // Disable submit button and show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

                // Re-enable after 10 seconds as a fallback in case submission stalls
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = submitBtn.dataset.originalText || 'Submit';
                }, 10000);
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
        fetch('/developer/api/notifications.php')
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
            // On mobile, sidebar should be hidden by default but toggleable
            if (devSidebar && !sidebarToggle.classList.contains('active')) {
                devSidebar.classList.remove('active');
            }
        } else {
            // On desktop, remove active class to show sidebar by default
            if (devSidebar) devSidebar.classList.remove('active');
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
        const currentTheme = localStorage.getItem('developer-theme') || 'light';

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
            localStorage.setItem('developer-theme', newTheme);

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

    // ============================================
    // 13. UTILITY FUNCTIONS
    // ============================================

    // Function to perform search
    function performSearch(query) {
        // Implement search functionality here
        console.log('Searching for:', query);
    }

    // Function to animate counter
    function animateCounter(element) {
        const target = parseInt(element.textContent.replace(/,/g, ''));
        const duration = 2000;
        const step = target / (duration / 16);
        let current = 0;

        const timer = setInterval(() => {
            current += step;
            if (current >= target) {
                element.textContent = target.toLocaleString();
                clearInterval(timer);
            } else {
                element.textContent = Math.floor(current).toLocaleString();
            }
        }, 16);
    }

    // Function to show notifications
    function showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `alert alert-${type}`;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            ${message}
            <button class="alert-close">&times;</button>
        `;

        // Add to page
        const container = document.querySelector('.flash-messages') || document.body;
        container.appendChild(notification);

        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.style.opacity = '0';
            setTimeout(() => notification.remove(), 300);
        }, 5000);

        // Close button functionality
        notification.querySelector('.alert-close').addEventListener('click', () => {
            notification.style.opacity = '0';
            setTimeout(() => notification.remove(), 300);
        });
    }

    // Function to update notifications UI
    function updateNotificationsUI(notifications) {
        const notificationsList = document.getElementById('notificationsList');
        const notificationCount = document.querySelector('.notification-count');

        if (notificationsList && notifications) {
            // Update notification count
            const unreadCount = notifications.filter(n => !n.is_read).length;
            if (notificationCount) {
                notificationCount.textContent = unreadCount;
                notificationCount.style.display = unreadCount > 0 ? 'flex' : 'none';
            }

            // Update notifications list (simplified)
            // This would need more implementation for full functionality
        }
    }

    // ============================================
    // 14. TASK MANAGEMENT FUNCTIONS
    // ============================================

    // Function to update task status
    window.updateTaskStatus = function(taskId, status) {
        fetch(`/developer/api/tasks.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'update_status',
                task_id: taskId,
                status: status
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Task status updated successfully', 'success');
                // Reload page or update UI
                setTimeout(() => location.reload(), 1000);
            } else {
                showNotification('Failed to update task status', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred', 'error');
        });
    };

    // Function to view task details
    window.viewTaskDetails = function(taskId) {
        // Redirect to task details page
        window.location.href = `/developer/tasks.php?id=${taskId}`;
    };

});
