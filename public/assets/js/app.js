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

        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    });
})();
