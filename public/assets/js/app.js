/* DynaPulse front-end: no framework, no build step. */
(function () {
    'use strict';

    const icons = {
        success: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        error: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
        warning: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 2.3 17.9A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3.1l-8-14a2 2 0 0 0-3.4 0z"/></svg>',
        info: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>'
    };

    /* ----------------------------------------------------------- theme */
    const THEME_KEY = 'dp-theme';

    function currentTheme() {
        return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
    }

    function applyTheme(theme) {
        document.documentElement.dataset.theme = theme;
        try { localStorage.setItem(THEME_KEY, theme); } catch (e) { /* private mode */ }

        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            button.title = button.getAttribute('aria-label');
        });
    }

    document.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-theme-toggle]');
        if (toggle) {
            applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
            return;
        }

        const sidebarToggle = event.target.closest('[data-sidebar-toggle]');
        if (sidebarToggle) {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                const open = sidebar.classList.toggle('is-open');
                sidebarToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            return;
        }

        const copyButton = event.target.closest('[data-copy]');
        if (copyButton) {
            const selector = copyButton.getAttribute('data-copy') || '';
            const source = selector.charAt(0) === '#' ? document.querySelector(selector) : null;
            const value = source ? (source.value || source.textContent || '') : selector;

            navigator.clipboard.writeText(value.trim()).then(function () {
                Toast.show('success', 'Copied to clipboard');
            }).catch(function () {
                Toast.show('warning', 'Copy failed — select the text manually');
            });
        }
    });

    applyTheme(currentTheme());

    /* ----------------------------------------------------------- toasts */
    const Toast = {
        show: function (type, message, title) {
            const region = document.getElementById('toasts');
            if (!region) { return; }

            const safeType = icons[type] ? type : 'info';
            const element = document.createElement('div');
            element.className = 'toast toast--' + safeType;
            element.setAttribute('role', 'status');

            const iconWrap = document.createElement('div');
            iconWrap.className = 'toast__icon';
            iconWrap.innerHTML = icons[safeType];

            const body = document.createElement('div');
            body.className = 'toast__body';

            if (title) {
                const heading = document.createElement('div');
                heading.className = 'toast__title';
                heading.textContent = title;
                body.appendChild(heading);
            }

            const text = document.createElement('div');
            text.className = 'toast__message';
            text.textContent = message;
            body.appendChild(text);

            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'toast__close';
            close.setAttribute('aria-label', 'Dismiss notification');
            close.innerHTML = icons.error;
            close.addEventListener('click', function () { dismiss(element); });

            element.appendChild(iconWrap);
            element.appendChild(body);
            element.appendChild(close);
            region.appendChild(element);

            window.setTimeout(function () { dismiss(element); }, safeType === 'error' ? 9000 : 5200);
        }
    };

    function dismiss(element) {
        if (!element.parentNode) { return; }
        element.classList.add('is-leaving');
        window.setTimeout(function () {
            if (element.parentNode) { element.parentNode.removeChild(element); }
        }, 200);
    }

    window.Toast = Toast;

    /* ----------------------------------------------------------- flashes */
    const flashSource = document.getElementById('flash-data');
    if (flashSource) {
        try {
            const flashes = JSON.parse(flashSource.textContent || '[]');
            flashes.forEach(function (flash, index) {
                window.setTimeout(function () {
                    Toast.show(flash.type || 'info', flash.message || '', flash.title || null);
                }, 120 * index);
            });
        } catch (e) { /* ignore malformed flash payload */ }
    }

    /* ----------------------------------------------------------- forms */
    document.querySelectorAll('[data-autosubmit]').forEach(function (field) {
        field.addEventListener('change', function () {
            if (field.form) { field.form.submit(); }
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const message = form.getAttribute('data-confirm') || 'Are you sure?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-confirm-link]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (!window.confirm(link.getAttribute('data-confirm-link') || 'Are you sure?')) {
                event.preventDefault();
            }
        });
    });

    /* ----------------------------------------------------------- audio */
    document.querySelectorAll('[data-audio]').forEach(function (audio) {
        const rateButtons = document.querySelectorAll('[data-rate]');
        rateButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const rate = parseFloat(button.getAttribute('data-rate'));
                audio.playbackRate = rate;
                rateButtons.forEach(function (other) { other.classList.remove('is-active'); });
                button.classList.add('is-active');
            });
        });

        audio.addEventListener('play', function () {
            document.querySelectorAll('.audio-box').forEach(function (box) { box.classList.add('is-playing'); });
        });
    });

    /* ----------------------------------------------------------- transcript search */
    const transcriptSearch = document.querySelector('[data-transcript-search]');
    if (transcriptSearch) {
        const segments = Array.prototype.slice.call(document.querySelectorAll('[data-segment]'));

        segments.forEach(function (segment) {
            segment.dataset.original = segment.querySelector('.segment__text').textContent;
        });

        transcriptSearch.addEventListener('input', function () {
            const query = transcriptSearch.value.trim().toLowerCase();

            segments.forEach(function (segment) {
                const textElement = segment.querySelector('.segment__text');
                const original = segment.dataset.original || '';

                if (!query) {
                    textElement.textContent = original;
                    segment.hidden = false;
                    return;
                }

                const match = original.toLowerCase().indexOf(query) !== -1;
                segment.hidden = !match;

                if (match) {
                    const index = original.toLowerCase().indexOf(query);
                    const before = original.slice(0, index);
                    const hit = original.slice(index, index + query.length);
                    const after = original.slice(index + query.length);
                    textElement.innerHTML = '';
                    textElement.appendChild(document.createTextNode(before));
                    const mark = document.createElement('mark');
                    mark.textContent = hit;
                    textElement.appendChild(mark);
                    textElement.appendChild(document.createTextNode(after));
                } else {
                    textElement.textContent = original;
                }
            });
        });
    }

    /* ----------------------------------------------------------- keyboard shortcuts */
    let lastKey = '';

    document.addEventListener('keydown', function (event) {
        const tag = (event.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        if (event.key === '/') {
            const search = document.querySelector('input[type="search"]');
            if (search) {
                event.preventDefault();
                search.focus();
            }
            return;
        }

        if (lastKey === 'g') {
            const labels = { d: 'Dashboard', c: 'Calls', a: 'Alerts', k: 'Keywords', r: 'Reports' };
            const label = labels[event.key.toLowerCase()];
            if (label) {
                const link = Array.prototype.slice.call(document.querySelectorAll('.nav__link'))
                    .find(function (item) { return item.textContent.trim() === label; });
                if (link) { window.location.href = link.getAttribute('href'); }
            }
            lastKey = '';
            return;
        }

        lastKey = event.key.toLowerCase();
        window.setTimeout(function () { lastKey = ''; }, 900);
    });

    /* ----------------------------------------------------------- mobile sidebar */
    document.addEventListener('click', function (event) {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar || !sidebar.classList.contains('is-open')) { return; }
        if (event.target.closest('#sidebar') || event.target.closest('[data-sidebar-toggle]')) { return; }
        sidebar.classList.remove('is-open');
    });
})();
