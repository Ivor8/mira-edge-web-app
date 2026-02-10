/**
 * Mira Edge Technologies - Main JavaScript
 */

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================
    // 1. MOBILE NAVIGATION TOGGLE
    // ============================================
    
    const mobileToggle = document.querySelector('.mobile-toggle');
    const navMenu = document.querySelector('.nav-menu');
    
    if (mobileToggle && navMenu) {
        mobileToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
            mobileToggle.classList.toggle('active');
            
            // Toggle icon
            const icon = mobileToggle.querySelector('i');
            if (icon) {
                if (navMenu.classList.contains('active')) {
                    icon.classList.remove('fa-bars');
                    icon.classList.add('fa-times');
                } else {
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            }
        });
        
        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            if (!navMenu.contains(event.target) && !mobileToggle.contains(event.target)) {
                navMenu.classList.remove('active');
                mobileToggle.classList.remove('active');
                
                const icon = mobileToggle.querySelector('i');
                if (icon) {
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            }
        });
    }
    
    // ============================================
    // 2. SCROLL ANIMATIONS
    // ============================================
    
    // Animate elements on scroll
    const animateOnScroll = () => {
        const animatedElements = document.querySelectorAll('.animate-on-scroll');
        
        animatedElements.forEach(element => {
            const elementPosition = element.getBoundingClientRect().top;
            const screenPosition = window.innerHeight / 1.2;
            
            if (elementPosition < screenPosition) {
                element.classList.add('animated');
            }
        });
    };
    
    // Initial check and on scroll
    animateOnScroll();
    window.addEventListener('scroll', animateOnScroll);
    
    // ============================================
    // 3. NAVBAR SCROLL EFFECT
    // ============================================
    
    const navbar = document.querySelector('.navbar');
    
    if (navbar) {
        window.addEventListener('scroll', () => {
            if (window.scrollY > 100) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
    }
    
    // ============================================
    // 4. SMOOTH SCROLL FOR ANCHOR LINKS
    // ============================================
    
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            
            const targetElement = document.querySelector(targetId);
            if (targetElement) {
                window.scrollTo({
                    top: targetElement.offsetTop - 80,
                    behavior: 'smooth'
                });
                
                // Close mobile menu if open
                if (navMenu && navMenu.classList.contains('active')) {
                    navMenu.classList.remove('active');
                    if (mobileToggle) {
                        mobileToggle.classList.remove('active');
                        const icon = mobileToggle.querySelector('i');
                        if (icon) {
                            icon.classList.remove('fa-times');
                            icon.classList.add('fa-bars');
                        }
                    }
                }
            }
        });
    });
    
    // ============================================
    // 5. FORM VALIDATION ENHANCEMENT
    // ============================================
    
    const forms = document.querySelectorAll('form:not(.no-validate)');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('error');
                    
                    // Add error message if not already present
                    if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('form-error')) {
                        const errorSpan = document.createElement('span');
                        errorSpan.className = 'form-error';
                        errorSpan.textContent = 'This field is required';
                        field.parentNode.insertBefore(errorSpan, field.nextSibling);
                    }
                } else {
                    field.classList.remove('error');
                    
                    // Remove error message if present
                    if (field.nextElementSibling && field.nextElementSibling.classList.contains('form-error')) {
                        field.nextElementSibling.remove();
                    }
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                
                // Scroll to first error
                const firstError = this.querySelector('.error');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstError.focus();
                }
            }
        });
        
        // Real-time validation
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                if (this.hasAttribute('required') && !this.value.trim()) {
                    this.classList.add('error');
                } else {
                    this.classList.remove('error');
                    
                    // Remove error message if present
                    if (this.nextElementSibling && this.nextElementSibling.classList.contains('form-error')) {
                        this.nextElementSibling.remove();
                    }
                }
            });
            
            input.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.classList.remove('error');
                    
                    // Remove error message if present
                    if (this.nextElementSibling && this.nextElementSibling.classList.contains('form-error')) {
                        this.nextElementSibling.remove();
                    }
                }
            });
        });
    });
    
    // ============================================
    // 6. TAB FUNCTIONALITY
    // ============================================
    
    const tabContainers = document.querySelectorAll('.tabs');
    
    tabContainers.forEach(container => {
        const tabNavItems = container.querySelectorAll('.tab-nav-item');
        const tabContents = container.querySelectorAll('.tab-content');
        
        tabNavItems.forEach((item, index) => {
            item.addEventListener('click', function() {
                // Update active tab nav
                tabNavItems.forEach(navItem => navItem.classList.remove('active'));
                this.classList.add('active');
                
                // Show corresponding tab content
                const targetTab = this.dataset.tab || tabContents[index].id;
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    if (content.id === targetTab || content.dataset.tab === targetTab) {
                        content.classList.add('active');
                    }
                });
            });
        });
    });
    
    // ============================================
    // 7. MODAL FUNCTIONALITY
    // ============================================
    
    // Open modal
    document.querySelectorAll('[data-modal]').forEach(trigger => {
        trigger.addEventListener('click', function() {
            const modalId = this.dataset.modal;
            const modal = document.getElementById(modalId);
            
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden'; // Prevent scrolling
            }
        });
    });
    
    // Close modal
    document.querySelectorAll('.modal-close, .modal-backdrop').forEach(closeBtn => {
        closeBtn.addEventListener('click', function() {
            const modal = this.closest('.modal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = ''; // Restore scrolling
            }
        });
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const openModal = document.querySelector('.modal.active');
            if (openModal) {
                openModal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
    });
    
    // ============================================
    // 8. COUNTER ANIMATION
    // ============================================
    
    const counters = document.querySelectorAll('.counter');
    
    if (counters.length > 0) {
        const animateCounter = (counter) => {
            const target = parseInt(counter.getAttribute('data-target'));
            const increment = target / 100;
            let current = 0;
            
            const timer = setInterval(() => {
                current += increment;
                counter.textContent = Math.floor(current);
                
                if (current >= target) {
                    counter.textContent = target.toLocaleString();
                    clearInterval(timer);
                }
            }, 20);
        };
        
        // Intersection Observer for counters
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });
        
        counters.forEach(counter => observer.observe(counter));
    }
    
    // ============================================
    // 9. LAZY LOADING IMAGES
    // ============================================
    
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.classList.add('loaded');
                    observer.unobserve(img);
                }
            });
        });
        
        document.querySelectorAll('img[data-src]').forEach(img => {
            imageObserver.observe(img);
        });
    }
    
    // ============================================
    // 10. BACK TO TOP BUTTON
    // ============================================
    
    const backToTop = document.querySelector('.back-to-top');
    
    if (backToTop) {
        window.addEventListener('scroll', () => {
            if (window.scrollY > 300) {
                backToTop.classList.add('visible');
            } else {
                backToTop.classList.remove('visible');
            }
        });
        
        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
    
    // ============================================
    // 11. ACCORDION FUNCTIONALITY
    // ============================================
    
    const accordionHeaders = document.querySelectorAll('.accordion-header');
    
    accordionHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const accordionItem = this.parentElement;
            const accordionContent = this.nextElementSibling;
            
            // Toggle active class
            accordionItem.classList.toggle('active');
            
            // Toggle content visibility
            if (accordionItem.classList.contains('active')) {
                accordionContent.style.maxHeight = accordionContent.scrollHeight + 'px';
            } else {
                accordionContent.style.maxHeight = '0';
            }
            
            // Close other accordion items (optional)
            // document.querySelectorAll('.accordion-item').forEach(item => {
            //     if (item !== accordionItem) {
            //         item.classList.remove('active');
            //         item.querySelector('.accordion-content').style.maxHeight = '0';
            //     }
            // });
        });
    });
    
    // ============================================
    // 12. NOTIFICATION DISMISS
    // ============================================
    
    document.querySelectorAll('.alert .close').forEach(closeBtn => {
        closeBtn.addEventListener('click', function() {
            this.parentElement.style.opacity = '0';
            setTimeout(() => {
                this.parentElement.remove();
            }, 300);
        });
    });
    
    // Auto-dismiss notifications after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert:not(.persistent)').forEach(alert => {
            alert.style.opacity = '0';
            setTimeout(() => {
                if (alert.parentElement) {
                    alert.remove();
                }
            }, 300);
        });
    }, 5000);
    
    // ============================================
    // 13. DYNAMIC YEAR UPDATE
    // ============================================
    
    const yearElements = document.querySelectorAll('.current-year');
    if (yearElements.length > 0) {
        const currentYear = new Date().getFullYear();
        yearElements.forEach(element => {
            element.textContent = currentYear;
        });
    }
    
    // ============================================
    // 14. FORM SUBMISSION LOADING STATES
    // ============================================
    
    forms.forEach(form => {
        const submitBtn = form.querySelector('[type="submit"]');
        
        if (submitBtn) {
            form.addEventListener('submit', function() {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
            });
        }
    });
    
    // ============================================
    // 15. COPY TO CLIPBOARD
    // ============================================
    
    document.querySelectorAll('[data-copy]').forEach(button => {
        button.addEventListener('click', function() {
            const textToCopy = this.getAttribute('data-copy');
            
            navigator.clipboard.writeText(textToCopy).then(() => {
                // Show success feedback
                const originalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                this.classList.add('success');
                
                setTimeout(() => {
                    this.innerHTML = originalText;
                    this.classList.remove('success');
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
                this.innerHTML = '<i class="fas fa-times"></i> Failed!';
                this.classList.add('error');
                
                setTimeout(() => {
                    this.innerHTML = originalText;
                    this.classList.remove('error');
                }, 2000);
            });
        });
    });
    
    // ============================================
    // 16. PASSWORD VISIBILITY TOGGLE
    // ============================================
    
    document.querySelectorAll('.password-toggle').forEach(toggle => {
        toggle.addEventListener('click', function() {
            const passwordInput = this.previousElementSibling;
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle icon
            const icon = this.querySelector('i');
            if (icon) {
                if (type === 'text') {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        });
    });
    
    // ============================================
    // 17. DROPDOWN MENUS
    // ============================================
    
    document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const dropdown = this.closest('.dropdown');
            dropdown.classList.toggle('active');
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function() {
        document.querySelectorAll('.dropdown').forEach(dropdown => {
            dropdown.classList.remove('active');
        });
    });
    
    // ============================================
    // 18. FILE UPLOAD PREVIEW
    // ============================================
    
    document.querySelectorAll('input[type="file"]').forEach(input => {
        input.addEventListener('change', function() {
            const preview = document.getElementById(this.dataset.preview);
            if (!preview) return;
            
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    if (preview.tagName === 'IMG') {
                        preview.src = e.target.result;
                    } else {
                        preview.innerHTML = `
                            <div class="file-preview">
                                <i class="fas fa-file"></i>
                                <span>${input.files[0].name}</span>
                                <small>(${(input.files[0].size / 1024).toFixed(2)} KB)</small>
                            </div>
                        `;
                    }
                };
                
                reader.readAsDataURL(this.files[0]);
            }
        });
    });
    
    // ============================================
    // 19. CHARACTER COUNTER FOR TEXTAREAS
    // ============================================
    
    document.querySelectorAll('textarea[maxlength]').forEach(textarea => {
        const maxLength = parseInt(textarea.getAttribute('maxlength'));
        const counter = document.createElement('div');
        counter.className = 'char-counter';
        counter.style.fontSize = '0.875rem';
        counter.style.color = 'var(--color-gray-500)';
        counter.style.textAlign = 'right';
        counter.style.marginTop = '0.5rem';
        
        textarea.parentNode.insertBefore(counter, textarea.nextSibling);
        
        const updateCounter = () => {
            const currentLength = textarea.value.length;
            counter.textContent = `${currentLength} / ${maxLength}`;
            
            if (currentLength > maxLength * 0.9) {
                counter.style.color = 'var(--color-warning)';
            } else if (currentLength > maxLength) {
                counter.style.color = 'var(--color-error)';
            } else {
                counter.style.color = 'var(--color-gray-500)';
            }
        };
        
        textarea.addEventListener('input', updateCounter);
        updateCounter(); // Initial count
    });
    
    // ============================================
    // 20. INITIALIZE TOOLTIPS
    // ============================================
    
    // Using Bootstrap's tooltip if available, otherwise simple version
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    } else {
        // Simple tooltip implementation
        document.querySelectorAll('[title]').forEach(element => {
            element.addEventListener('mouseenter', function(e) {
                const tooltip = document.createElement('div');
                tooltip.className = 'simple-tooltip';
                tooltip.textContent = this.getAttribute('title');
                document.body.appendChild(tooltip);
                
                const rect = this.getBoundingClientRect();
                tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
                
                this.tooltipElement = tooltip;
            });
            
            element.addEventListener('mouseleave', function() {
                if (this.tooltipElement) {
                    this.tooltipElement.remove();
                    this.tooltipElement = null;
                }
            });
        });
    }
    
    // ============================================
    // 21. PARALLAX EFFECT
    // ============================================
    
    const parallaxElements = document.querySelectorAll('.parallax');
    
    if (parallaxElements.length > 0) {
        window.addEventListener('scroll', () => {
            const scrolled = window.pageYOffset;
            
            parallaxElements.forEach(element => {
                const rate = element.dataset.rate || 0.5;
                const offset = scrolled * rate;
                element.style.transform = `translateY(${offset}px)`;
            });
        });
    }
    
    // ============================================
    // 22. INITIALIZE ANIMATION CLASSES
    // ============================================
    
    // Add animation classes to elements
    const animationDelay = 100;
    document.querySelectorAll('.fade-in').forEach((element, index) => {
        element.style.animationDelay = `${index * animationDelay}ms`;
    });
    
    // ============================================
    // 23. SERVICE WORKER REGISTRATION (PWA)
    // ============================================
    
    if ('serviceWorker' in navigator && window.location.protocol === 'https:') {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/service-worker.js').then(
                registration => {
                    console.log('ServiceWorker registration successful');
                },
                err => {
                    console.log('ServiceWorker registration failed: ', err);
                }
            );
        });
    }
    
    // ============================================
    // 24. DARK/LIGHT MODE TOGGLE
    // ============================================
    
    const modeToggle = document.querySelector('.mode-toggle');
    if (modeToggle) {
        const currentMode = localStorage.getItem('color-mode') || 'light';
        
        // Apply saved mode
        if (currentMode === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            modeToggle.querySelector('i').classList.add('fa-sun');
        }
        
        modeToggle.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('color-mode', newTheme);
            
            const icon = modeToggle.querySelector('i');
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
    // 25. PROGRESS BARS ANIMATION
    // ============================================
    
    const progressBars = document.querySelectorAll('.progress-bar');
    
    if (progressBars.length > 0) {
        const progressObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const progressBar = entry.target;
                    const width = progressBar.getAttribute('data-width') || '100%';
                    
                    setTimeout(() => {
                        progressBar.style.width = width;
                    }, 300);
                    
                    progressObserver.unobserve(progressBar);
                }
            });
        }, { threshold: 0.5 });
        
        progressBars.forEach(bar => progressObserver.observe(bar));
    }
});

// ============================================
// GLOBAL FUNCTIONS
// ============================================

/**
 * Debounce function to limit the rate at which a function can fire
 */
function debounce(func, wait, immediate) {
    let timeout;
    return function() {
        const context = this, args = arguments;
        const later = function() {
            timeout = null;
            if (!immediate) func.apply(context, args);
        };
        const callNow = immediate && !timeout;
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
        if (callNow) func.apply(context, args);
    };
}

/**
 * Throttle function to ensure a function is only called at most once per specified period
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

/**
 * Format date to readable string
 */
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

/**
 * Show notification message
 */
function showNotification(message, type = 'info', duration = 5000) {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        </div>
        <button class="notification-close">&times;</button>
    `;
    
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);
    
    // Auto remove
    if (duration > 0) {
        setTimeout(() => {
            closeNotification(notification);
        }, duration);
    }
    
    // Close button
    notification.querySelector('.notification-close').addEventListener('click', () => {
        closeNotification(notification);
    });
}

function closeNotification(notification) {
    notification.classList.remove('show');
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 300);
}

/**
 * AJAX form submission
 */
function submitForm(form, options = {}) {
    return new Promise((resolve, reject) => {
        const formData = new FormData(form);
        
        fetch(form.action || window.location.href, {
            method: form.method || 'POST',
            body: formData,
            headers: options.headers || {}
        })
        .then(response => response.json())
        .then(data => resolve(data))
        .catch(error => reject(error));
    });
}

/**
 * Get URL parameters
 */
function getUrlParams() {
    const params = {};
    const queryString = window.location.search.substring(1);
    const pairs = queryString.split('&');
    
    pairs.forEach(pair => {
        const [key, value] = pair.split('=');
        if (key) {
            params[decodeURIComponent(key)] = decodeURIComponent(value || '');
        }
    });
    
    return params;
}

/**
 * Set URL parameter
 */
function setUrlParam(key, value) {
    const url = new URL(window.location);
    url.searchParams.set(key, value);
    window.history.pushState({}, '', url);
}

// ============================================
// EXPORT FUNCTIONS FOR MODULE USAGE
// ============================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        debounce,
        throttle,
        formatDate,
        showNotification,
        submitForm,
        getUrlParams,
        setUrlParam
    };
}