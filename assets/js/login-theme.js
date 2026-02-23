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

    function createThemeFooter() {
        var footer = document.getElementById('loginThemeFooter');
        if (footer) {
            footer.style.position = 'fixed';
            footer.style.left = 'auto';
            footer.style.right = '12px';
            footer.style.bottom = '12px';
            footer.style.display = 'flex';
            footer.style.justifyContent = 'flex-end';
            footer.style.pointerEvents = 'none';
            return footer;
        }

        footer = document.createElement('div');
        footer.id = 'loginThemeFooter';
        footer.className = 'login-theme-footer';
        footer.style.position = 'fixed';
        footer.style.left = 'auto';
        footer.style.right = '12px';
        footer.style.bottom = '12px';
        footer.style.display = 'flex';
        footer.style.justifyContent = 'flex-end';
        footer.style.pointerEvents = 'none';
        document.body.appendChild(footer);
        return footer;
    }

    function createThemeButton() {
        var footer = createThemeFooter();
        var existing = document.getElementById('loginThemeToggleBtn');
        if (existing) {
            if (existing.parentElement !== footer) {
                footer.appendChild(existing);
            }
            return existing;
        }

        var btn = document.createElement('button');
        btn.id = 'loginThemeToggleBtn';
        btn.type = 'button';
        btn.className = 'login-theme-toggle';
        btn.setAttribute('aria-live', 'polite');
        btn.style.position = 'relative';
        btn.style.top = 'auto';
        btn.style.right = 'auto';
        btn.style.bottom = 'auto';
        btn.style.left = 'auto';
        btn.style.width = '36px';
        btn.style.height = '36px';
        footer.appendChild(btn);
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
