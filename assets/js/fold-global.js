(function () {
    if (window.__smnsFoldGlobalInit) return;
    window.__smnsFoldGlobalInit = true;
    var loadingOverlayTimer = null;
    var loadingOverlayDelayMs = 180;
    var loadingOverlayFailsafeTimer = null;
    var loadingOverlayMaxVisibleMs = 15000;

    function discoverBaseUrl() {
        if (typeof window.SMNS_BASE_URL === 'string' && window.SMNS_BASE_URL !== '') {
            return window.SMNS_BASE_URL.replace(/\/+$/, '');
        }

        var script = document.currentScript || document.querySelector('script[src*="/assets/js/fold-global.js"]');
        var src = script && script.src ? script.src : '';
        if (src) {
            var match = src.match(/^(.*)\/assets\/js\/fold-global\.js(?:\?.*)?$/i);
            if (match && match[1]) {
                return match[1].replace(/\/+$/, '');
            }
        }

        var logo = document.querySelector('img[src*="/assets/img/sem.PNG"]');
        var logoSrc = logo ? (logo.getAttribute('src') || '') : '';
        if (logoSrc) {
            var resolvedLogoUrl = new URL(logoSrc, window.location.href);
            var logoMatch = resolvedLogoUrl.pathname.match(/^(.*)\/assets\/img\/sem\.PNG$/i);
            if (logoMatch && logoMatch[1]) {
                return logoMatch[1].replace(/\/+$/, '');
            }
        }

        return '';
    }

    function getLogoSrc() {
        var sharedLogo = document.getElementById('smnsSharedLogoAsset');
        if (sharedLogo) {
            return sharedLogo.currentSrc || sharedLogo.src || sharedLogo.getAttribute('src') || '';
        }

        if (typeof window.SMNS_LOGO_URL === 'string' && window.SMNS_LOGO_URL !== '') {
            return window.SMNS_LOGO_URL;
        }

        var baseUrl = discoverBaseUrl();
        var version = (typeof window.SMNS_APP_VERSION === 'string' && window.SMNS_APP_VERSION !== '')
            ? window.SMNS_APP_VERSION
            : '';
        return (baseUrl ? baseUrl : '') + '/assets/img/sem.PNG' + (version ? ('?v=' + encodeURIComponent(version)) : '');
    }

    function showLoadingOverlay() {
        if (loadingOverlayTimer) {
            clearTimeout(loadingOverlayTimer);
            loadingOverlayTimer = null;
        }
        if (loadingOverlayFailsafeTimer) {
            clearTimeout(loadingOverlayFailsafeTimer);
            loadingOverlayFailsafeTimer = null;
        }
        if (document.querySelector('.loading-overlay')) {
            return;
        }

        var overlay = document.createElement('div');
        overlay.className = 'loading-overlay';
        overlay.setAttribute('aria-live', 'polite');
        overlay.setAttribute('aria-label', 'Loading');
        overlay.innerHTML =
            '<div class="loading-brand-card">' +
                '<img src="' + getLogoSrc() + '" alt="SMNS Logo" class="loading-brand-logo">' +
                '<div class="loading-brand-title">SMNS</div>' +
                '<div class="loading-brand-ring"><img src="' + getLogoSrc() + '" alt="" class="loading-brand-ring-logo"></div>' +
                '<div class="loading-brand-text">Loading...</div>' +
                '<div class="loading-brand-dots"><span></span><span></span><span></span></div>' +
            '</div>';
        document.body.appendChild(overlay);
        loadingOverlayFailsafeTimer = window.setTimeout(hideLoadingOverlay, loadingOverlayMaxVisibleMs);
    }

    function scheduleLoadingOverlay() {
        if (document.querySelector('.loading-overlay') || loadingOverlayTimer) {
            return;
        }
        loadingOverlayTimer = window.setTimeout(function () {
            loadingOverlayTimer = null;
            showLoadingOverlay();
        }, loadingOverlayDelayMs);
    }

    function hideLoadingOverlay() {
        if (loadingOverlayTimer) {
            clearTimeout(loadingOverlayTimer);
            loadingOverlayTimer = null;
        }
        if (loadingOverlayFailsafeTimer) {
            clearTimeout(loadingOverlayFailsafeTimer);
            loadingOverlayFailsafeTimer = null;
        }

        var overlay = document.querySelector('.loading-overlay');
        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
    }

    function shouldHandleLinkClick(event, link) {
        if (!link || event.defaultPrevented) {
            return false;
        }

        var href = (link.getAttribute('href') || '').trim();
        if (
            href === '' ||
            href.charAt(0) === '#' ||
            /^javascript:/i.test(href) ||
            link.hasAttribute('download') ||
            ((link.getAttribute('target') || '').toLowerCase() === '_blank') ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return false;
        }

        return true;
    }

    function bindNavigationLoading(root) {
        var scope = root && root.querySelectorAll ? root : document;

        scope.querySelectorAll('a[href]').forEach(function (link) {
            if (link.dataset.smnsLoaderBound === '1') return;
            link.dataset.smnsLoaderBound = '1';

            link.addEventListener('click', function (event) {
                if (!shouldHandleLinkClick(event, link)) {
                    return;
                }
                scheduleLoadingOverlay();
            });
        });

        scope.querySelectorAll('form').forEach(function (form) {
            if (form.dataset.smnsLoaderBound === '1') return;
            form.dataset.smnsLoaderBound = '1';

            form.addEventListener('submit', function (event) {
                if (event.defaultPrevented || form.noValidate || form.dataset.noLoading === '1') {
                    return;
                }
                if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    hideLoadingOverlay();
                    return;
                }

                var target = (form.getAttribute('target') || '').toLowerCase();
                if (target === '_blank') {
                    return;
                }

                scheduleLoadingOverlay();
            });
        });
    }

    function isAutoFilterForm(form) {
        if (!(form instanceof HTMLFormElement)) {
            return false;
        }
        if ((form.getAttribute('method') || '').toLowerCase() !== 'get') {
            return false;
        }
        if (form.dataset.noAutoFilter === '1') {
            return false;
        }

        var className = (form.className || '').toLowerCase();
        if (className.indexOf('filter') !== -1 || className.indexOf('search') !== -1) {
            return true;
        }

        var candidateFields = form.querySelectorAll('input[type="text"], input[type="search"], input[type="number"], input[type="date"], input[type="month"], select');
        if (!candidateFields.length) {
            return false;
        }

        var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        for (var i = 0; i < submitButtons.length; i++) {
            var label = '';
            if (submitButtons[i].tagName === 'INPUT') {
                label = submitButtons[i].value || '';
            } else {
                label = submitButtons[i].textContent || '';
            }
            label = label.toLowerCase();
            if (label.indexOf('filter') !== -1 || label.indexOf('search') !== -1 || label.indexOf('apply') !== -1) {
                return true;
            }
        }

        return false;
    }

    function bindAutoFilterForms(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var forms = scope.querySelectorAll('form');

        forms.forEach(function (form) {
            if (form.dataset.smnsAutoFilterBound === '1') {
                return;
            }
            if (!isAutoFilterForm(form)) {
                return;
            }

            form.dataset.smnsAutoFilterBound = '1';
            var submitTimer = null;
            var debounceMs = 350;

            function resetPaging() {
                var pageFields = form.querySelectorAll('input[name="page"], select[name="page"]');
                pageFields.forEach(function (field) {
                    if (field.tagName === 'SELECT') {
                        field.value = '1';
                    } else {
                        field.value = '1';
                    }
                });
            }

            function queueSubmit(immediate) {
                if (submitTimer) {
                    clearTimeout(submitTimer);
                    submitTimer = null;
                }

                var delay = immediate ? 0 : debounceMs;
                submitTimer = window.setTimeout(function () {
                    submitTimer = null;
                    resetPaging();
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }, delay);
            }

            var textInputs = form.querySelectorAll('input[type="text"], input[type="search"], input[type="number"], input[type="date"], input[type="month"]');
            textInputs.forEach(function (input) {
                if (input.dataset.autoFilterBound === '1' || input.type === 'hidden') {
                    return;
                }
                input.dataset.autoFilterBound = '1';
                input.addEventListener('input', function () {
                    queueSubmit(false);
                });
                input.addEventListener('change', function () {
                    queueSubmit(true);
                });
            });

            var selects = form.querySelectorAll('select');
            selects.forEach(function (select) {
                if (select.dataset.autoFilterBound === '1') {
                    return;
                }
                select.dataset.autoFilterBound = '1';
                select.addEventListener('change', function () {
                    queueSubmit(true);
                });
            });
        });
    }

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
        bindNavigationLoading(document);
        bindAutoFilterForms(document);
        if (window.MutationObserver) {
            var foldRefreshTimer = null;
            var observer = new MutationObserver(function () {
                if (foldRefreshTimer) clearTimeout(foldRefreshTimer);
                foldRefreshTimer = setTimeout(function () {
                    initAutoFoldSections(document);
                    bindNavigationLoading(document);
                    bindAutoFilterForms(document);
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

    window.addEventListener('pageshow', hideLoadingOverlay);
})();
