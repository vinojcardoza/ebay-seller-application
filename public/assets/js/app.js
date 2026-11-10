/**
 * Admin theme behaviour: mobile sidebar drawer and light/dark theme toggle.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'app-theme';
    var root = document.documentElement;

    /**
     * Read a value from localStorage, returning null when storage is unavailable.
     */
    function readStorage(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    /**
     * Write a value to localStorage, ignoring failures (private mode, blocked storage).
     */
    function writeStorage(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            // Preference just won't persist.
        }
    }

    /**
     * Apply a theme and update the toggle button's icon and label.
     */
    function applyTheme(theme) {
        root.setAttribute('data-bs-theme', theme);

        var button = document.querySelector('[data-theme-toggle]');
        if (!button) {
            return;
        }

        var icon = button.querySelector('i');
        var next = theme === 'dark' ? 'light' : 'dark';
        icon.className = theme === 'dark' ? 'ti ti-sun' : 'ti ti-moon';
        button.setAttribute('aria-label', 'Switch to ' + next + ' mode');
        button.setAttribute('title', 'Switch to ' + next + ' mode');
    }

    /**
     * Shared tooltip for chart marks with data-tip-value / data-tip-label,
     * shown on hover and keyboard focus. Text is set with textContent only.
     */
    function initChartTooltips() {
        var marks = document.querySelectorAll('[data-tip-value]');
        if (marks.length === 0) {
            return;
        }

        var tip = document.createElement('div');
        var value = document.createElement('strong');
        var label = document.createElement('span');
        tip.className = 'chart-tooltip';
        tip.setAttribute('role', 'presentation');
        tip.hidden = true;
        tip.appendChild(value);
        tip.appendChild(label);
        document.body.appendChild(tip);

        function show(mark) {
            value.textContent = mark.getAttribute('data-tip-value');
            label.textContent = mark.getAttribute('data-tip-label');
            tip.hidden = false;

            var box = mark.getBoundingClientRect();
            var left = box.left + box.width / 2 - tip.offsetWidth / 2;
            var maxLeft = document.documentElement.clientWidth - tip.offsetWidth - 8;
            tip.style.left = Math.max(8, Math.min(left, maxLeft)) + 'px';
            tip.style.top = Math.max(8, box.top - tip.offsetHeight - 8) + 'px';
        }

        function hide() {
            tip.hidden = true;
        }

        marks.forEach(function (mark) {
            mark.addEventListener('pointerenter', function () { show(mark); });
            mark.addEventListener('pointerleave', hide);
            mark.addEventListener('focus', function () { show(mark); });
            mark.addEventListener('blur', hide);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hide();
            }
        });
        window.addEventListener('scroll', hide, { passive: true });
    }

    document.addEventListener('DOMContentLoaded', function () {
        applyTheme(root.getAttribute('data-bs-theme') || 'light');

        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var theme = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                writeStorage(STORAGE_KEY, theme);
                applyTheme(theme);
            });
        });

        document.querySelectorAll('[data-sidebar-toggle]').forEach(function (el) {
            el.addEventListener('click', function () {
                var open = document.body.classList.toggle('sidebar-open');
                document.querySelectorAll('.app-sidebar-toggle').forEach(function (btn) {
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                document.body.classList.remove('sidebar-open');
            }
        });

        initChartTooltips();

        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    });
})();
