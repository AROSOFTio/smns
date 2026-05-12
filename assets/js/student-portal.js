document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector('.student-sidebar');
    var mainContent = document.getElementById('mainContent') || document.querySelector('.main-content');
    var overlay = document.querySelector('.sidebar-overlay');
    var mobileButton = document.querySelector('.mobile-menu-btn');
    var menuButtons = document.querySelectorAll('#menuBtn, #sidebarToggle');

    if (!sidebar || !mainContent) {
        return;
    }

    var mediaQuery = window.matchMedia('(max-width: 992px)');
    var touchStartX = 0;
    var touchStartY = 0;

    function syncMobileButtonOffset() {
        if (!mobileButton || !mobileButton.classList.contains('active')) {
            if (mobileButton) {
                mobileButton.style.removeProperty('left');
            }
            return;
        }

        var sidebarWidth = Math.ceil(sidebar.getBoundingClientRect().width);
        if (sidebarWidth > 0) {
            mobileButton.style.left = (sidebarWidth + 8) + 'px';
        }
    }

    function closeSidebar() {
        sidebar.classList.remove('active');
        sidebar.classList.add('sidebar-collapsed');
        mainContent.classList.add('full-width');
        document.body.classList.remove('smns-sidebar-open');
        document.body.style.overflow = '';

        if (overlay) {
            overlay.classList.remove('active');
        }
        if (mobileButton) {
            mobileButton.classList.remove('active');
            mobileButton.innerHTML = '&#9776;';
            mobileButton.style.removeProperty('left');
        }
    }

    function openSidebar() {
        sidebar.classList.add('active');
        sidebar.classList.remove('sidebar-collapsed');
        mainContent.classList.add('full-width');
        document.body.classList.add('smns-sidebar-open');
        document.body.style.overflow = 'hidden';

        if (overlay) {
            overlay.classList.add('active');
        }
        if (mobileButton) {
            mobileButton.classList.add('active');
            mobileButton.innerHTML = '&times;';
            syncMobileButtonOffset();
        }
    }

    function toggleSidebar() {
        if (sidebar.classList.contains('active') && !sidebar.classList.contains('sidebar-collapsed')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    }

    function syncLayout() {
        if (mediaQuery.matches) {
            closeSidebar();
        } else {
            sidebar.classList.remove('active');
            sidebar.classList.remove('sidebar-collapsed');
            mainContent.classList.remove('full-width');
            document.body.classList.remove('smns-sidebar-open');
            document.body.style.overflow = '';

            if (overlay) {
                overlay.classList.remove('active');
            }
            if (mobileButton) {
                mobileButton.classList.remove('active');
                mobileButton.innerHTML = '&#9776;';
            }
        }
    }

    syncLayout();

    menuButtons.forEach(function (menuButton) {
        menuButton.addEventListener('click', function (event) {
            if (!mediaQuery.matches) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            toggleSidebar();
        }, true);
    });

    if (overlay) {
        overlay.addEventListener('click', function () {
            if (mediaQuery.matches) {
                closeSidebar();
            }
        });

        overlay.addEventListener('touchstart', function () {
            if (mediaQuery.matches) {
                closeSidebar();
            }
        }, { passive: true });
    }

    sidebar.querySelectorAll('a[href]').forEach(function (link) {
        link.addEventListener('click', function () {
            if (mediaQuery.matches) {
                closeSidebar();
            }
        }, true);
    });

    sidebar.addEventListener('touchstart', function (event) {
        if (!mediaQuery.matches || !event.touches || event.touches.length !== 1) {
            return;
        }

        touchStartX = event.touches[0].clientX;
        touchStartY = event.touches[0].clientY;
    }, { passive: true });

    sidebar.addEventListener('touchend', function (event) {
        if (!mediaQuery.matches || !event.changedTouches || event.changedTouches.length !== 1) {
            return;
        }

        var touchEndX = event.changedTouches[0].clientX;
        var touchEndY = event.changedTouches[0].clientY;
        var deltaX = touchEndX - touchStartX;
        var deltaY = Math.abs(touchEndY - touchStartY);

        if (deltaX < -45 && deltaY < 55) {
            closeSidebar();
        }
    }, { passive: true });

    window.addEventListener('resize', syncMobileButtonOffset);

    if (typeof mediaQuery.addEventListener === 'function') {
        mediaQuery.addEventListener('change', syncLayout);
    } else if (typeof mediaQuery.addListener === 'function') {
        mediaQuery.addListener(syncLayout);
    }
});
