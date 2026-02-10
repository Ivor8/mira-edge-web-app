/**
 * Services Management JavaScript
 */

class ServicesManager {
    constructor() {
        this.selectAllCheckbox = document.getElementById('select-all');
        this.serviceCheckboxes = document.querySelectorAll('.service-checkbox');
        this.deleteButtons = document.querySelectorAll('.btn-delete');
        this.deleteModal = document.getElementById('deleteModal');
        this.bulkActionForm = document.querySelector('.bulk-actions-form');
    }
    
    init() {
        this.setupEventListeners();
        this.setupAnimations();
        this.setupFormValidation();
    }
    
    setupEventListeners() {
        // Select all checkbox
        if (this.selectAllCheckbox) {
            this.selectAllCheckbox.addEventListener('change', (e) => {
                this.toggleAllCheckboxes(e.target.checked);
            });
        }
        
        // Individual checkboxes
        this.serviceCheckboxes.forEach(checkbox => {
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
        
        // Bulk action form
        if (this.bulkActionForm) {
            this.bulkActionForm.addEventListener('submit', (e) => {
                this.handleBulkAction(e);
            });
        }
        
        // Modal close handlers
        document.querySelectorAll('.modal-close, .modal-cancel, .modal-backdrop').forEach(element => {
            element.addEventListener('click', () => {
                this.closeModal();
            });
        });
        
        // Escape key to close modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
            }
        });
    }
    
    setupAnimations() {
        // Animate table rows on load
        const rows = document.querySelectorAll('.service-row');
        rows.forEach((row, index) => {
            row.style.opacity = '0';
            row.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                row.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            }, index * 50);
        });
        
        // Add hover effects to action buttons
        const actionButtons = document.querySelectorAll('.btn-action');
        actionButtons.forEach(button => {
            button.addEventListener('mouseenter', () => {
                button.style.transform = 'translateY(-2px) scale(1.1)';
            });
            
            button.addEventListener('mouseleave', () => {
                button.style.transform = 'translateY(0) scale(1)';
            });
        });
        
        // Add ripple effect to buttons
        const buttons = document.querySelectorAll('.btn');
        buttons.forEach(button => {
            button.addEventListener('click', (e) => {
                this.createRippleEffect(e, button);
            });
        });
    }
    
    setupFormValidation() {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const requiredFields = this.querySelectorAll('[required]');
                let isValid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.classList.add('error');
                        
                        if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('form-error')) {
                            const error = document.createElement('div');
                            error.className = 'form-error';
                            error.textContent = 'This field is required';
                            field.parentNode.insertBefore(error, field.nextSibling);
                        }
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        });
    }
    
    toggleAllCheckboxes(checked) {
        this.serviceCheckboxes.forEach(checkbox => {
            checkbox.checked = checked;
            this.animateCheckbox(checkbox);
        });
    }
    
    updateSelectAllCheckbox() {
        if (!this.selectAllCheckbox) return;
        
        const checkedCount = Array.from(this.serviceCheckboxes).filter(cb => cb.checked).length;
        const allChecked = checkedCount === this.serviceCheckboxes.length;
        const someChecked = checkedCount > 0 && !allChecked;
        
        this.selectAllCheckbox.checked = allChecked;
        this.selectAllCheckbox.indeterminate = someChecked;
    }
    
    showDeleteModal(event) {
        event.preventDefault();
        
        const button = event.currentTarget;
        const serviceId = button.dataset.serviceId;
        const serviceName = button.dataset.serviceName;
        
        document.getElementById('serviceToDelete').textContent = serviceName;
        document.getElementById('deleteServiceId').value = serviceId;
        
        this.deleteModal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    closeModal() {
        this.deleteModal.classList.remove('active');
        document.body.style.overflow = '';
    }
    
    handleBulkAction(event) {
        const selectedServices = Array.from(this.serviceCheckboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.value);
        
        if (selectedServices.length === 0) {
            event.preventDefault();
            this.showNotification('Please select at least one service', 'warning');
            return;
        }
        
        const actionSelect = this.bulkActionForm.querySelector('.bulk-action-select');
        if (!actionSelect.value) {
            event.preventDefault();
            this.showNotification('Please select a bulk action', 'warning');
        }
    }
    
    animateCheckbox(checkbox) {
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
    
    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas fa-${this.getNotificationIcon(type)}"></i>
                <span>${message}</span>
            </div>
            <button class="notification-close">&times;</button>
        `;
        
        // Add to page
        const container = document.querySelector('.notifications-container') || this.createNotificationsContainer();
        container.appendChild(notification);
        
        // Show with animation
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            this.closeNotification(notification);
        }, 5000);
        
        // Close button
        notification.querySelector('.notification-close').addEventListener('click', () => {
            this.closeNotification(notification);
        });
    }
    
    getNotificationIcon(type) {
        const icons = {
            'success': 'check-circle',
            'error': 'exclamation-circle',
            'warning': 'exclamation-triangle',
            'info': 'info-circle'
        };
        return icons[type] || 'info-circle';
    }
    
    closeNotification(notification) {
        notification.classList.remove('show');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }
    
    createNotificationsContainer() {
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
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const servicesManager = new ServicesManager();
    servicesManager.init();
    
    // Add CSS for ripple animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes ripple-animation {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
        
        .notification {
            background: var(--color-white);
            border-radius: var(--radius-md);
            padding: var(--space-md);
            margin-bottom: var(--space-sm);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--color-gray-200);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-md);
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification-success {
            border-left: 4px solid var(--color-success);
        }
        
        .notification-error {
            border-left: 4px solid var(--color-error);
        }
        
        .notification-warning {
            border-left: 4px solid var(--color-warning);
        }
        
        .notification-info {
            border-left: 4px solid var(--color-info);
        }
        
        .notification-content {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            flex: 1;
        }
        
        .notification-close {
            background: none;
            border: none;
            color: var(--color-gray-500);
            cursor: pointer;
            padding: 0;
            font-size: 1.25rem;
        }
    `;
    document.head.appendChild(style);
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ServicesManager;
}