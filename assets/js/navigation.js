/**
 * Enhanced Responsive Navigation Script
 * Handles mobile menu, sidebar collapse, and responsive behaviors
 */
(function() {
    'use strict';

    // Mobile menu functionality
    function isCompactNavigationViewport() {
        return window.innerWidth <= 768 ||
            (window.innerWidth <= 1024 && window.innerHeight <= 540 && window.matchMedia('(orientation: landscape)').matches);
    }

    function initMobileMenu() {
        const isAdminPath = /\/views\/admin\/|\/admin\//.test(window.location.pathname);
        let mobileBtn = null;

        if (isAdminPath) {
            document.body.classList.add('smns-admin-mobile-shell');
        }
        document.querySelectorAll('.mobile-menu-btn').forEach(function (button) {
            button.remove();
        });

        // Create or reuse overlay
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
        }

        const sidebar = document.querySelector('.sidebar, .student-sidebar, .lecturer-sidebar, .finance-sidebar');
        if (!sidebar) {
            // Some pages (e.g., auth screens) may not render a sidebar.
            // Guard to prevent JS from crashing and blocking other inits (dropdown/logout).
            overlay.remove();
            if (mobileBtn) {
                mobileBtn.remove();
            }
            return;
        }

        const isAdminSidebar = sidebar.classList.contains('sidebar') &&
            !sidebar.classList.contains('student-sidebar') &&
            !sidebar.classList.contains('lecturer-sidebar') &&
            !sidebar.classList.contains('finance-sidebar');

        if (isAdminSidebar) {
            document.body.classList.add('smns-admin-mobile-shell');
            document.querySelectorAll('.mobile-menu-btn').forEach(function (button) {
                button.remove();
            });
            if (mobileBtn) {
                mobileBtn.remove();
                mobileBtn = null;
            }
        }

        function syncMobileButtonOffset() {
            if (!mobileBtn) {
                return;
            }
            if (!mobileBtn.classList.contains('active')) {
                mobileBtn.style.removeProperty('left');
                return;
            }

            const sidebarWidth = Math.ceil(sidebar.getBoundingClientRect().width);
            if (sidebarWidth > 0) {
                mobileBtn.style.left = (sidebarWidth + 8) + 'px';
            }
        }

        function closeMobileMenu() {
            sidebar.classList.remove('active');
            sidebar.classList.remove('collapsed');
            const mainContent = document.getElementById('mainContent') || document.querySelector('.main-content');
            if (mainContent) {
                mainContent.classList.remove('expanded');
            }
            if (sidebar.classList.contains('student-sidebar') && window.innerWidth < 993) {
                sidebar.classList.add('sidebar-collapsed');
            }
            overlay.classList.remove('active');
            if (mobileBtn) {
                mobileBtn.classList.remove('active');
                mobileBtn.innerHTML = '&#9776;';
                mobileBtn.style.removeProperty('left');
            }
            document.body.classList.remove('smns-sidebar-open');
            document.body.style.overflow = '';
        }

        function openMobileMenu() {
            sidebar.classList.add('active');
            sidebar.classList.remove('collapsed');
            sidebar.classList.remove('sidebar-collapsed');
            const mainContent = document.getElementById('mainContent') || document.querySelector('.main-content');
            if (mainContent) {
                mainContent.classList.remove('expanded');
            }
            overlay.classList.add('active');
            if (mobileBtn) {
                mobileBtn.classList.add('active');
                mobileBtn.innerHTML = '&times;';
            }
            document.body.classList.add('smns-sidebar-open');
            document.body.style.overflow = 'hidden';
            syncMobileButtonOffset();
        }

        window.SMNSMobileNav = {
            open: openMobileMenu,
            close: closeMobileMenu,
            toggle: function () {
                if (sidebar.classList.contains('active')) {
                    closeMobileMenu();
                } else {
                    openMobileMenu();
                }
            }
        };

        if (isAdminSidebar) {
            const topbarLeft = document.querySelector('.topbar-left') || document.querySelector('.topbar');
            let adminToggle = document.getElementById('sidebarToggle') || document.querySelector('.sidebar-toggle');

            if (!adminToggle && topbarLeft) {
                adminToggle = document.createElement('button');
                adminToggle.type = 'button';
                adminToggle.className = 'sidebar-toggle';
                adminToggle.id = 'sidebarToggle';
                adminToggle.title = 'Toggle Sidebar';
                adminToggle.setAttribute('aria-label', 'Toggle Sidebar');
                adminToggle.innerHTML = '<i class="fas fa-bars"></i>';
                topbarLeft.insertBefore(adminToggle, topbarLeft.firstChild);
            }

            if (adminToggle && adminToggle.dataset.smnsMobileNavBound !== '1') {
                adminToggle.dataset.smnsMobileNavBound = '1';
                adminToggle.addEventListener('click', function (event) {
                    if (!isCompactNavigationViewport()) {
                        return;
                    }

                    event.preventDefault();
                    event.stopImmediatePropagation();
                    window.SMNSMobileNav.toggle();
                }, true);
            }
        }
         
        // Toggle mobile menu
        function toggleMobileMenu() {
            const isActive = sidebar.classList.contains('active');
            
            if (isActive) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        }

        // Event listeners
        if (mobileBtn) {
            mobileBtn.addEventListener('click', toggleMobileMenu);
        }
        overlay.addEventListener('click', closeMobileMenu);
        overlay.addEventListener('touchstart', closeMobileMenu, { passive: true });

        // Close menu when clicking nav links on mobile
        const navLinks = document.querySelectorAll('.nav-link, .sidebar-menu a[href]:not(.has-submenu), .student-sidebar a[href], .lecturer-sidebar a[href], .finance-sidebar a[href]');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 993) {
                    closeMobileMenu();
                }
            }, true);
        });

        // Handle window resize
        window.addEventListener('resize', () => {
            if (!isCompactNavigationViewport()) {
                closeMobileMenu();
            } else {
                syncMobileButtonOffset();
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
        const dropdowns = document.querySelectorAll('.user-dropdown');

        dropdowns.forEach((dropdownWrap) => {
            const dropdown = dropdownWrap.querySelector('.user-dropdown-toggle');
            const dropdownMenu = dropdownWrap.querySelector('.user-dropdown-menu');

            if (!dropdown || !dropdownMenu || dropdown.dataset.smnsDropdownBound === '1') {
                return;
            }

            dropdown.dataset.smnsDropdownBound = '1';

            dropdown.addEventListener('click', (e) => {
                e.stopPropagation();

                document.querySelectorAll('.user-dropdown.active').forEach((openWrap) => {
                    if (openWrap !== dropdownWrap) {
                        openWrap.classList.remove('active');
                        const openMenu = openWrap.querySelector('.user-dropdown-menu');
                        if (openMenu) {
                            openMenu.classList.remove('show');
                        }
                    }
                });

                dropdownWrap.classList.toggle('active');
                dropdownMenu.classList.toggle('show');
            });

            dropdownMenu.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        });

        document.addEventListener('click', () => {
            document.querySelectorAll('.user-dropdown.active').forEach((dropdownWrap) => {
                dropdownWrap.classList.remove('active');
                const dropdownMenu = dropdownWrap.querySelector('.user-dropdown-menu');
                if (dropdownMenu) {
                    dropdownMenu.classList.remove('show');
                }
            });
        });
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
                
                const sidebar = document.querySelector('.sidebar.active, .student-sidebar.active, .lecturer-sidebar.active, .finance-sidebar.active');
                if (sidebar && window.innerWidth < 768) {
                    if (window.SMNSMobileNav && typeof window.SMNSMobileNav.close === 'function') {
                        window.SMNSMobileNav.close();
                    } else {
                        const overlay = document.querySelector('.sidebar-overlay');
                        if (overlay) {
                            const event = new Event('click');
                            overlay.dispatchEvent(event);
                        }
                    }
                }
            }
        });
        
        // Focus management for mobile menu
        const focusableElements = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Tab') {
                const sidebar = document.querySelector('.sidebar, .student-sidebar, .lecturer-sidebar, .finance-sidebar');
                if (sidebar && sidebar.classList.contains('active') && window.innerWidth < 768) {
                    const focusableContent = sidebar.querySelectorAll(focusableElements);
                    if (!focusableContent.length) {
                        return;
                    }
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
