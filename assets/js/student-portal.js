document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector('.student-sidebar');
    var mainContent = document.getElementById('mainContent') || document.querySelector('.main-content');

    if (!sidebar || !mainContent) {
        return;
    }

    var mediaQuery = window.matchMedia('(max-width: 992px)');

    function syncLayout() {
        if (mediaQuery.matches) {
            sidebar.classList.add('sidebar-collapsed');
            mainContent.classList.add('full-width');
        } else {
            sidebar.classList.remove('sidebar-collapsed');
            mainContent.classList.remove('full-width');
        }
    }

    syncLayout();

    if (typeof mediaQuery.addEventListener === 'function') {
        mediaQuery.addEventListener('change', syncLayout);
    } else if (typeof mediaQuery.addListener === 'function') {
        mediaQuery.addListener(syncLayout);
    }
});
