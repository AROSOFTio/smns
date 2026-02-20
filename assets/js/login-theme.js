(function () {
    var STORAGE_KEY = 'smns_theme_mode';
    var root = document.documentElement;

    function applyTheme(mode, btn) {
        if (mode === 'dark') {
            root.setAttribute('data-theme', 'dark');
            if (btn) {
                btn.setAttribute('aria-label', 'Switch to light mode');
                btn.setAttribute('title', 'Switch to light mode');
                btn.innerHTML = '<i class="fas fa-sun"></i>';
            }
            return;
        }

        root.removeAttribute('data-theme');
        if (btn) {
            btn.setAttribute('aria-label', 'Switch to dark mode');
            btn.setAttribute('title', 'Switch to dark mode');
            btn.innerHTML = '<i class="fas fa-moon"></i>';
        }
    }

    function getStoredTheme() {
        var saved = localStorage.getItem(STORAGE_KEY);
        return (saved === 'dark' || saved === 'light') ? saved : 'light';
    }

    function createThemeButton() {
        var existing = document.getElementById('loginThemeToggleBtn');
        if (existing) {
            return existing;
        }

        var btn = document.createElement('button');
        btn.id = 'loginThemeToggleBtn';
        btn.type = 'button';
        btn.className = 'login-theme-toggle';
        btn.setAttribute('aria-live', 'polite');
        document.body.appendChild(btn);
        return btn;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var btn = createThemeButton();
        var mode = getStoredTheme();
        applyTheme(mode, btn);

        btn.addEventListener('click', function () {
            var current = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            localStorage.setItem(STORAGE_KEY, next);
            applyTheme(next, btn);
        });
    });
})();
