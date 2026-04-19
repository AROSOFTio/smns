/**
 * Enhanced Responsive Navigation Script
 * Handles mobile menu, sidebar collapse, and responsive behaviors
 */
(function() {
    'use strict';

    // Mobile menu functionality
    function initMobileMenu() {
        // Create mobile menu button
        const mobileBtn = document.createElement('button');
        mobileBtn.className = 'mobile-menu-btn';
        mobileBtn.innerHTML = '&#9776;';
        mobileBtn.setAttribute('aria-label', 'Toggle Navigation');
        document.body.appendChild(mobileBtn);

        // Create overlay
        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);

        const sidebar = document.querySelector('.sidebar');
        
        // Toggle mobile menu
        function toggleMobileMenu() {
            const isActive = sidebar.classList.contains('active');
            
            if (isActive) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                mobileBtn.classList.remove('active');
                mobileBtn.innerHTML = '&#9776;';
                document.body.style.overflow = '';
            } else {
                sidebar.classList.add('active');
                overlay.classList.add('active');
                mobileBtn.classList.add('active');
                mobileBtn.innerHTML = '&times;';
                document.body.style.overflow = 'hidden';
            }
        }

        // Event listeners
        mobileBtn.addEventListener('click', toggleMobileMenu);
        overlay.addEventListener('click', toggleMobileMenu);

        // Close menu when clicking nav links on mobile
        const navLinks = document.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    toggleMobileMenu();
                }
            });
        });

        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                mobileBtn.classList.remove('active');
                mobileBtn.innerHTML = '&#9776;';
                document.body.style.overflow = '';
            }
        });
    }

    // Desktop sidebar collapse is now handled by the header toggle button in main.js
    // The initSidebarCollapse function has been removed to prevent duplicate controls

    // Active navigation highlighting
    function initActiveNavigation() {
        const currentPath = window.location.pathname;
        const navLinks = document.querySelectorAll('.nav-link');
        
        navLinks.forEach(link => {
            const linkPath = link.getAttribute('href');
            
            // Check if current path matches or contains the link path
            if (linkPath && (
                currentPath === linkPath || 
                currentPath.includes(linkPath.replace('../', '')) ||
                (linkPath.includes('dashboard') && currentPath.includes('dashboard'))
            )) {
                link.classList.add('active');
            }
        });
    }

    // Enhanced user dropdown functionality
    function initUserDropdown() {
        const dropdown = document.querySelector('#userDropdown');
        const dropdownMenu = document.querySelector('#userDropdownMenu');
        
        if (dropdown && dropdownMenu) {
            dropdown.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdownMenu.classList.toggle('show');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', () => {
                dropdownMenu.classList.remove('show');
            });
            
            // Prevent dropdown from closing when clicking inside menu
            dropdownMenu.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }
    }

    // Real-time clock and date
    function initClock() {
        const timeDisplay = document.querySelector('.time-display');
        const dateDisplay = document.querySelector('.date-display');
        
        if (timeDisplay || dateDisplay) {
            function updateTime() {
                const now = new Date();
                
                if (timeDisplay) {
                    timeDisplay.textContent = now.toLocaleTimeString('en-US', {
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true
                    });
                }
                
                if (dateDisplay) {
                    dateDisplay.textContent = now.toLocaleDateString('en-US', {
                        weekday: 'long',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                }
            }
            
            updateTime();
            setInterval(updateTime, 1000);
        }
    }

    // Smooth scrolling for in-page navigation
    function initSmoothScrolling() {
        const links = document.querySelectorAll('a[href^="#"]');
        
        links.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                
                const targetId = link.getAttribute('href').substring(1);
                const targetElement = document.getElementById(targetId);
                
                if (targetElement) {
                    targetElement.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }

    // Loading states for navigation
    function initLoadingStates() {
        const interactiveLinks = document.querySelectorAll('a[href]');
        interactiveLinks.forEach((link) => {
            if (link.dataset.smnsLoadingBound === '1') {
                return;
            }
            link.dataset.smnsLoadingBound = '1';

            link.addEventListener('click', (event) => {
                if (event.defaultPrevented) {
                    return;
                }

                const href = (link.getAttribute('href') || '').trim();
                if (
                    href === '' ||
                    href.startsWith('#') ||
                    href.startsWith('javascript:') ||
                    link.hasAttribute('download') ||
                    (link.getAttribute('target') || '').toLowerCase() === '_blank' ||
                    event.metaKey ||
                    event.ctrlKey ||
                    event.shiftKey ||
                    event.altKey
                ) {
                    return;
                }

                if (typeof showLoading === 'function') {
                    showLoading();
                }
            });
        });
    }

    // Notification handling
    function initNotificationHandling() {
        const AUTO_DISMISS_MS = 10000;
        const dismissAlert = (alert) => {
            if (!(alert instanceof HTMLElement) || alert.dataset.alertRemoving === '1') {
                return;
            }
            alert.dataset.alertRemoving = '1';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        };

        const bindAlert = (alert) => {
            if (!(alert instanceof HTMLElement) || !alert.classList.contains('alert')) {
                return;
            }
            if (alert.dataset.alertBound === '1') {
                return;
            }

            alert.dataset.alertBound = '1';
            alert.style.transition = alert.style.transition || 'opacity 0.3s ease';

            if (!alert.querySelector('.close')) {
                const closeBtn = document.createElement('button');
                closeBtn.type = 'button';
                closeBtn.className = 'close';
                closeBtn.setAttribute('aria-label', 'Close');
                closeBtn.innerHTML = '&times;';
                closeBtn.addEventListener('click', () => dismissAlert(alert));
                alert.insertBefore(closeBtn, alert.firstChild);
            }

            if (alert.getAttribute('data-auto-dismiss') === 'false') {
                return;
            }

            let timer = null;
            const startTimer = () => {
                if (timer) {
                    clearTimeout(timer);
                }
                timer = setTimeout(() => dismissAlert(alert), AUTO_DISMISS_MS);
            };
            const stopTimer = () => {
                if (timer) {
                    clearTimeout(timer);
                    timer = null;
                }
            };

            startTimer();
            alert.addEventListener('mouseenter', stopTimer);
            alert.addEventListener('mouseleave', startTimer);
            alert.addEventListener('focusin', stopTimer);
            alert.addEventListener('focusout', startTimer);
        };

        document.querySelectorAll('.alert').forEach(bindAlert);

        if (typeof MutationObserver === 'function' && document.body) {
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (!(node instanceof HTMLElement)) {
                            return;
                        }
                        if (node.classList.contains('alert')) {
                            bindAlert(node);
                        }
                        node.querySelectorAll('.alert').forEach(bindAlert);
                    });
                });
            });

            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    // Accessibility improvements
    function initAccessibility() {
        // Add keyboard navigation
        document.addEventListener('keydown', (e) => {
            // ESC to close modals/dropdowns
            if (e.key === 'Escape') {
                const dropdownMenu = document.querySelector('#userDropdownMenu.show');
                if (dropdownMenu) {
                    dropdownMenu.classList.remove('show');
                }
                
                const sidebar = document.querySelector('.sidebar.active');
                if (sidebar && window.innerWidth < 768) {
                    const event = new Event('click');
                    document.querySelector('.sidebar-overlay').dispatchEvent(event);
                }
            }
        });
        
        // Focus management for mobile menu
        const focusableElements = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Tab') {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar.classList.contains('active') && window.innerWidth < 768) {
                    const focusableContent = sidebar.querySelectorAll(focusableElements);
                    const firstFocusable = focusableContent[0];
                    const lastFocusable = focusableContent[focusableContent.length - 1];
                    
                    if (e.shiftKey) {
                        if (document.activeElement === firstFocusable) {
                            lastFocusable.focus();
                            e.preventDefault();
                        }
                    } else {
                        if (document.activeElement === lastFocusable) {
                            firstFocusable.focus();
                            e.preventDefault();
                        }
                    }
                }
            }
        });
    }

    // Initialize all functionality when DOM is loaded
    function init() {
        initMobileMenu();
        // initSidebarCollapse removed - handled by header toggle in main.js
        initActiveNavigation();
        initUserDropdown();
        initClock();
        initSmoothScrolling();
        initLoadingStates();
        initNotificationHandling();
        initAccessibility();
        
        console.log('Enhanced Navigation System Initialized');
    }

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
