/**
 * Team Management JavaScript
 */

class TeamManager {
    constructor() {
        this.selectAllCheckbox = document.getElementById('select-all');
        this.userCheckboxes = document.querySelectorAll('.user-checkbox');
        this.deactivateButtons = document.querySelectorAll('.btn-deactivate');
        this.activateButtons = document.querySelectorAll('.btn-activate');
        this.bulkActionForm = document.querySelector('.bulk-actions-form');
        this.deactivateModal = document.getElementById('deactivateModal');
        this.activateModal = document.getElementById('activateModal');
    }
    
    init() {
        this.setupEventListeners();
        this.setupTableAnimations();
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
        this.userCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                this.updateSelectAllCheckbox();
            });
        });
        
        // Deactivate buttons
        this.deactivateButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                this.showDeactivateModal(e);
            });
        });
        
        // Activate buttons
        this.activateButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                this.showActivateModal(e);
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
    }
    
    setupTableAnimations() {
        const rows = document.querySelectorAll('.team-table tbody tr');
        
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
    
    setupHoverEffects() {
        // Add hover effects to user cards
        const userCards = document.querySelectorAll('.user-avatar-small');
        userCards.forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.style.transform = 'scale(1.1) rotate(5deg)';
            });
            
            card.addEventListener('mouseleave', () => {
                card.style.transform = 'scale(1) rotate(0)';
            });
        });
        
        // Add ripple effect to buttons
        const buttons = document.querySelectorAll('.btn-action');
        buttons.forEach(button => {
            button.addEventListener('click', (e) => {
                this.createRippleEffect(e, button);
            });
        });
    }
    
    toggleAllCheckboxes(checked) {
        this.userCheckboxes.forEach(checkbox => {
            if (!checkbox.disabled) {
                checkbox.checked = checked;
                this.animateCheckbox(checkbox, checked);
            }
        });
    }
    
    updateSelectAllCheckbox() {
        if (!this.selectAllCheckbox) return;
        
        const enabledCheckboxes = Array.from(this.userCheckboxes).filter(cb => !cb.disabled);
        const checkedCount = enabledCheckboxes.filter(cb => cb.checked).length;
        const allChecked = checkedCount === enabledCheckboxes.length;
        const someChecked = checkedCount > 0 && !allChecked;
        
        this.selectAllCheckbox.checked = allChecked;
        this.selectAllCheckbox.indeterminate = someChecked;
    }
    
    showDeactivateModal(event) {
        event.preventDefault();
        
        const button = event.currentTarget;
        const userId = button.dataset.userId;
        const userName = button.dataset.userName;
        
        document.getElementById('userToDeactivate').textContent = userName;
        document.getElementById('deactivateUserId').value = userId;
        
        // Update form action
        const deactivateForm = document.getElementById('deactivateForm');
        deactivateForm.action = `?action=deactivate&id=${userId}`;
        
        this.showModal(this.deactivateModal);
    }
    
    showActivateModal(event) {
        event.preventDefault();
        
        const button = event.currentTarget;
        const userId = button.dataset.userId;
        const userName = button.dataset.userName;
        
        document.getElementById('userToActivate').textContent = userName;
        document.getElementById('activateUserId').value = userId;
        
        // Update form action
        const activateForm = document.getElementById('activateForm');
        activateForm.action = `?action=activate&id=${userId}`;
        
        this.showModal(this.activateModal);
    }
    
    handleBulkAction(event) {
        const selectedUsers = Array.from(this.userCheckboxes)
            .filter(cb => cb.checked && !cb.disabled)
            .map(cb => cb.value);
        
        if (selectedUsers.length === 0) {
            event.preventDefault();
            this.showNotification('Please select at least one team member', 'warning');
            return;
        }
        
        const actionSelect = this.bulkActionForm.querySelector('.bulk-action-select');
        if (!actionSelect.value) {
            event.preventDefault();
            this.showNotification('Please select a bulk action', 'warning');
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
    
    createRippleEffect(event, element) {
        const ripple = document.createElement('span');
        const rect = element.getBoundingClientRect();
        
        const size = Math.max(rect.width, rect.height);
        const x = event.clientX - rect.left - size / 2;
        const y = event.clientY - rect.top - size / 2;
        
        ripple.style.cssText = `
            position: absolute;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.1);
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
    
    showNotification(message, type = 'info') {
        // Use the existing notification system from admin.js
        if (typeof showNotification === 'function') {
            showNotification(message, type);
        } else {
            // Fallback notification
            const notification = document.createElement('div');
            notification.className = `alert alert-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
                <button class="alert-close">&times;</button>
            `;
            
            const container = document.querySelector('.flash-messages') || document.body;
            container.prepend(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                notification.remove();
            }, 5000);
            
            // Close button
            notification.querySelector('.alert-close').addEventListener('click', () => {
                notification.remove();
            });
        }
    }
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
        
        @keyframes bounceIn {
            0% {
                opacity: 0;
                transform: scale(0.3);
            }
            50% {
                opacity: 1;
                transform: scale(1.05);
            }
            70% {
                transform: scale(0.9);
            }
            100% {
                transform: scale(1);
            }
        }
    `;
    document.head.appendChild(style);
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = TeamManager;
}