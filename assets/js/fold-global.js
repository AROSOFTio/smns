(function () {
    if (window.__smnsFoldGlobalInit) return;
    window.__smnsFoldGlobalInit = true;

    function initAutoFoldSections(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var selectors = [
            '.content-area .table-responsive',
            '.content-area .health-checks',
            '.content-area .notification-list',
            '.content-area .saved-list',
            '.content-area pre'
        ];
        var threshold = window.innerWidth <= 768 ? 260 : 340;

        selectors.forEach(function (selector) {
            var nodes = scope.querySelectorAll(selector);
            nodes.forEach(function (node) {
                if (!(node instanceof HTMLElement)) return;
                if (node.dataset.foldInit === '1') return;
                if (node.closest('.dropdown-menu, .modal, .notification-dropdown')) return;

                requestAnimationFrame(function () {
                    if (!(node instanceof HTMLElement)) return;
                    if (node.dataset.foldInit === '1') return;
                    if (node.scrollHeight <= threshold + 24) return;

                    node.dataset.foldInit = '1';
                    node.classList.add('foldable-content', 'is-collapsed');

                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'fold-toggle-btn';
                    btn.textContent = 'Show more';
                    btn.setAttribute('aria-expanded', 'false');

                    btn.addEventListener('click', function () {
                        var expanded = !node.classList.contains('is-collapsed');
                        if (expanded) {
                            node.classList.add('is-collapsed');
                            btn.textContent = 'Show more';
                            btn.setAttribute('aria-expanded', 'false');
                        } else {
                            node.classList.remove('is-collapsed');
                            btn.textContent = 'Show less';
                            btn.setAttribute('aria-expanded', 'true');
                        }
                    });

                    node.insertAdjacentElement('afterend', btn);
                });
            });
        });
    }

    function boot() {
        initAutoFoldSections(document);
        if (window.MutationObserver) {
            var foldRefreshTimer = null;
            var observer = new MutationObserver(function () {
                if (foldRefreshTimer) clearTimeout(foldRefreshTimer);
                foldRefreshTimer = setTimeout(function () {
                    initAutoFoldSections(document);
                }, 120);
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
