/**
 * Projects Management JavaScript
 * Interactive and Beautiful UI with Animations
 */

class ProjectsManager {
    constructor() {
        this.selectAllCheckbox = document.getElementById('select-all');
        this.projectCheckboxes = document.querySelectorAll('.project-checkbox');
        this.deleteButtons = document.querySelectorAll('.btn-delete');
        this.featureButtons = document.querySelectorAll('.btn-feature');
        this.bulkActionForm = document.querySelector('.bulk-actions-form');
        this.deleteModal = document.getElementById('deleteModal');
        this.featureModal = document.getElementById('featureModal');
        this.projectsTable = document.querySelector('.projects-table');
        this.filterForm = document.querySelector('.filters-form');
    }
    
    init() {
        this.setupEventListeners();
        this.setupTableAnimations();
        this.setupFilterAnimations();
        this.setupTooltips();
        this.setupHoverEffects();
    }
    
    setupEventListeners() {
        // Select all checkbox
        if (this.selectAllCheckbox) {
            this.selectAllCheckbox.addEventListener('change', (e) => {
                this.toggleAllCheckboxes(e.target.checked);
            });
        }
        
        // Individual checkboxes
        this.projectCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                this.updateSelectAllCheckbox();
            });
        });
        
        // Delete buttons
        this.deleteButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                this.showDeleteModal(e);
            });
        });
        
        // Feature buttons
        this.featureButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                this.toggleFeature(e);
            });
        });
        
        // Bulk action form
        if (this.bulkActionForm) {
            this.bulkActionForm.addEventListener('submit', (e) => {
                this.handleBulkAction(e);
            });
        }
        
        // Modal close buttons
        document.querySelectorAll('.modal-close, .modal-cancel, .modal-backdrop').forEach(element => {
            element.addEventListener('click', () => {
                this.closeAllModals();
            });
        });
        
        // Filter form submission with debounce
        if (this.filterForm) {
            const searchInput = this.filterForm.querySelector('#search');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', debounce(() => {
                    this.filterForm.submit();
                }, 500));
            }
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            this.handleKeyboardShortcuts(e);
        });
    }
    
    setupTableAnimations() {
        if (!this.projectsTable) return;
        
        const rows = this.projectsTable.querySelectorAll('tbody tr');
        
        // Animate rows on load
        rows.forEach((row, index) => {
            row.style.opacity = '0';
            row.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                row.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            }, index * 50);
        });
        
        // Add hover animations
        rows.forEach(row => {
            row.addEventListener('mouseenter', () => {
                row.style.transform = 'translateY(-2px)';
                row.style.boxShadow = '0 8px 16px rgba(0, 0, 0, 0.1)';
            });
            
            row.addEventListener('mouseleave', () => {
                row.style.transform = 'translateY(0)';
                row.style.boxShadow = 'none';
            });
        });
    }
    
    setupFilterAnimations() {
        const filterInputs = document.querySelectorAll('.filter-input, .filter-select');
        
        filterInputs.forEach(input => {
            input.addEventListener('focus', () => {
                input.parentElement.style.transform = 'translateY(-2px)';
            });
            
            input.addEventListener('blur', () => {
                input.parentElement.style.transform = 'translateY(0)';
            });
        });
    }
    
    setupTooltips() {
        // Use browser tooltips for now, could be enhanced with custom tooltips
        const tooltipElements = document.querySelectorAll('[data-tooltip]');
        
        tooltipElements.forEach(element => {
            element.addEventListener('mouseenter', (e) => {
                this.showCustomTooltip(e.target, e.target.dataset.tooltip);
            });
            
            element.addEventListener('mouseleave', (e) => {
                this.hideCustomTooltip();
            });
        });
    }
    
    setupHoverEffects() {
        // Add ripple effect to buttons
        const buttons = document.querySelectorAll('.btn-action, .btn, .filter-select');
        
        buttons.forEach(button => {
            button.addEventListener('click', (e) => {
                this.createRippleEffect(e, button);
            });
        });
    }
    
    toggleAllCheckboxes(checked) {
        this.projectCheckboxes.forEach(checkbox => {
            checkbox.checked = checked;
            this.animateCheckbox(checkbox, checked);
        });
    }
    
    updateSelectAllCheckbox() {
        if (!this.selectAllCheckbox) return;
        
        const checkedCount = Array.from(this.projectCheckboxes).filter(cb => cb.checked).length;
        const allChecked = checkedCount === this.projectCheckboxes.length;
        const someChecked = checkedCount > 0 && !allChecked;
        
        this.selectAllCheckbox.checked = allChecked;
        this.selectAllCheckbox.indeterminate = someChecked;
    }
    
    showDeleteModal(event) {
        event.preventDefault();
        
        const button = event.currentTarget;
        const projectId = button.dataset.projectId;
        const projectTitle = button.dataset.projectTitle;
        
        document.getElementById('projectToDelete').textContent = projectTitle;
        document.getElementById('deleteProjectId').value = projectId;
        
        // Update form action to include project ID
        const deleteForm = document.getElementById('deleteForm');
        deleteForm.action = `?action=delete&id=${projectId}`;
        
        this.showModal(this.deleteModal);
    }
    
    toggleFeature(event) {
        event.preventDefault();
        
        const button = event.currentTarget;
        const projectId = button.dataset.projectId;
        const isFeatured = button.dataset.featured === '1';
        
        // Show loading state
        button.classList.add('loading');
        button.disabled = true;
        
        // Send AJAX request
        fetch(`${window.location.pathname}?action=toggle_feature&id=${projectId}`, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `feature=${!isFeatured ? 1 : 0}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update button state
                button.dataset.featured = !isFeatured ? '1' : '0';
                button.classList.toggle('featured', !isFeatured);
                
                // Update featured badge in table
                const featuredBadge = button.closest('.project-row').querySelector('.featured-badge');
                if (featuredBadge) {
                    featuredBadge.classList.toggle('featured', !isFeatured);
                    featuredBadge.innerHTML = `<i class="fas fa-star"></i> ${!isFeatured ? 'Featured' : 'No'}`;
                    featuredBadge.textContent = !isFeatured ? 'Featured' : 'No';
                }
                
                // Show success animation
                this.animateSuccess(button);
                
                // Show notification
                showNotification(
                    `Project ${!isFeatured ? 'featured' : 'unfeatured'} successfully!`,
                    'success'
                );
            } else {
                showNotification(data.message || 'Error updating project', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Network error occurred', 'error');
        })
        .finally(() => {
            button.classList.remove('loading');
            button.disabled = false;
        });
    }
    
    handleBulkAction(event) {
        const selectedProjects = Array.from(this.projectCheckboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.value);
        
        if (selectedProjects.length === 0) {
            event.preventDefault();
            showNotification('Please select at least one project', 'warning');
            return;
        }
        
        const actionSelect = this.bulkActionForm.querySelector('.bulk-action-select');
        if (!actionSelect.value) {
            event.preventDefault();
            showNotification('Please select a bulk action', 'warning');
        }
    }
    
    showModal(modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Animate modal content
        const modalContent = modal.querySelector('.modal-content');
        modalContent.style.animation = 'bounceIn 0.5s ease-out';
    }
    
    closeAllModals() {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
    
    animateCheckbox(checkbox, checked) {
        checkbox.style.transform = 'scale(1.2)';
        setTimeout(() => {
            checkbox.style.transform = 'scale(1)';
        }, 200);
    }
    
    animateSuccess(element) {
        element.style.color = 'var(--color-success)';
        element.style.transform = 'scale(1.2)';
        
        setTimeout(() => {
            element.style.color = '';
            element.style.transform = 'scale(1)';
        }, 500);
    }
    
    createRippleEffect(event, element) {
        const ripple = document.createElement('span');
        const rect = element.getBoundingClientRect();
        
        const size = Math.max(rect.width, rect.height);
        const x = event.clientX - rect.left - size / 2;
        const y = event.clientY - rect.top - size / 2;
        
        ripple.style.cssText = `
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.7);
            transform: scale(0);
            animation: ripple-animation 0.6s linear;
            width: ${size}px;
            height: ${size}px;
            left: ${x}px;
            top: ${y}px;
            pointer-events: none;
        `;
        
        element.style.position = 'relative';
        element.style.overflow = 'hidden';
        element.appendChild(ripple);
        
        setTimeout(() => {
            ripple.remove();
        }, 600);
    }
    
    showCustomTooltip(element, text) {
        // Remove existing tooltip
        this.hideCustomTooltip();
        
        // Create tooltip
        const tooltip = document.createElement('div');
        tooltip.className = 'custom-tooltip';
        tooltip.textContent = text;
        
        // Position tooltip
        const rect = element.getBoundingClientRect();
        tooltip.style.cssText = `
            position: fixed;
            background: var(--color-black);
            color: var(--color-white);
            padding: 6px 10px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            white-space: nowrap;
            z-index: var(--z-tooltip);
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.2s ease;
        `;
        
        tooltip.style.left = `${rect.left + rect.width / 2}px`;
        tooltip.style.top = `${rect.top - 10}px`;
        
        document.body.appendChild(tooltip);
        
        // Animate in
        setTimeout(() => {
            tooltip.style.opacity = '1';
            tooltip.style.transform = 'translateX(-50%) translateY(-10px)';
        }, 10);
        
        this.currentTooltip = tooltip;
    }
    
    hideCustomTooltip() {
        if (this.currentTooltip) {
            this.currentTooltip.remove();
            this.currentTooltip = null;
        }
    }
    
    handleKeyboardShortcuts(event) {
        // Ctrl/Cmd + A to select all
        if ((event.ctrlKey || event.metaKey) && event.key === 'a') {
            event.preventDefault();
            this.toggleAllCheckboxes(true);
        }
        
        // Escape to close modals
        if (event.key === 'Escape') {
            this.closeAllModals();
        }
        
        // Delete key to delete selected
        if (event.key === 'Delete') {
            const selected = Array.from(this.projectCheckboxes).filter(cb => cb.checked);
            if (selected.length > 0) {
                this.showDeleteModal({ currentTarget: selected[0].closest('.btn-delete') });
            }
        }
    }
    
    // Utility method to refresh projects list
    refreshProjects() {
        const rows = this.projectsTable.querySelectorAll('tbody tr');
        rows.forEach(row => {
            row.style.opacity = '0';
            row.style.transform = 'translateY(-20px)';
            
            setTimeout(() => {
                row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            }, 100);
        });
    }
}

// Helper function for debounce
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

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Add CSS for animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes ripple-animation {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
        
        .custom-tooltip {
            animation: slideInUp 0.2s ease-out;
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(-5px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(-10px);
            }
        }
    `;
    document.head.appendChild(style);
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ProjectsManager;
}