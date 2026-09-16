/* Shared admin panel behaviour. */
(function () {
    'use strict';

    /* ---------- photo zoom ---------- */
    // Check-in photos are shown as small thumbnails; clicking one opens it full
    // size, which is how an admin actually verifies a face.
    var viewer = null;

    function openViewer(src) {
        if (!viewer) {
            viewer = document.createElement('div');
            viewer.className = 'modal-backdrop';
            viewer.innerHTML =
                '<div class="modal" style="max-width:min(720px,92vw);padding:0">' +
                '<img alt="Attendance photo" style="display:block;width:100%;border-radius:12px">' +
                '</div>';
            viewer.addEventListener('click', function () { viewer.classList.remove('open'); });
            document.body.appendChild(viewer);
        }
        viewer.querySelector('img').src = src;
        viewer.classList.add('open');
    }

    document.addEventListener('click', function (event) {
        var zoom = event.target.closest('[data-zoom]');
        if (zoom) {
            event.preventDefault();
            openViewer(zoom.getAttribute('data-zoom'));
        }
    });

    /* ---------- modals ---------- */
    document.addEventListener('click', function (event) {
        var open = event.target.closest('[data-modal-open]');
        if (open) {
            event.preventDefault();
            var target = document.getElementById(open.getAttribute('data-modal-open'));
            if (target) {
                target.classList.add('open');
                // Prefill the form from data-* attributes on the trigger, so one
                // modal can serve every row of a table.
                Object.keys(open.dataset).forEach(function (key) {
                    if (key.indexOf('set') !== 0) return;
                    var name = key.slice(3);
                    name = name.charAt(0).toLowerCase() + name.slice(1);
                    var field = target.querySelector('[name="' + name + '"]');
                    if (field) {
                        if (field.type === 'checkbox') {
                            field.checked = open.dataset[key] === '1';
                        } else {
                            field.value = open.dataset[key];
                        }
                    }
                });
                // Announced so fields whose visibility depends on a value just filled
                // in can re-evaluate. Without it a split-shift employee's second pair
                // was populated but left hidden, because the toggle had already run.
                target.dispatchEvent(new CustomEvent('att:rules-loaded', {bubbles: true}));

                var label = target.querySelector('[data-modal-subject]');
                if (label && open.dataset.subject) label.textContent = open.dataset.subject;
                var first = target.querySelector('input:not([type=hidden]),select,textarea');
                if (first) first.focus();
            }
        }

        if (event.target.closest('[data-modal-close]') ||
            (event.target.classList && event.target.classList.contains('modal-backdrop'))) {
            var backdrop = event.target.closest('.modal-backdrop');
            if (backdrop) backdrop.classList.remove('open');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.open').forEach(function (el) {
                el.classList.remove('open');
            });
        }
    });

    /* ---------- destructive confirmations ---------- */
    // Resetting a device or deleting an account cannot be undone from the UI, so
    // the button carries the exact wording of what is about to happen.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    /* ---------- auto-submitting filters ---------- */
    document.querySelectorAll('[data-autosubmit]').forEach(function (field) {
        field.addEventListener('change', function () {
            field.form.submit();
        });
    });

    /* ---------- password generator ---------- */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-generate-password]');
        if (!button) return;
        event.preventDefault();
        var target = document.querySelector(button.getAttribute('data-generate-password'));
        if (!target) return;
        // Ambiguous characters are left out: these get read aloud and typed on a
        // phone keypad by the employee.
        var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        var bytes = new Uint32Array(10);
        window.crypto.getRandomValues(bytes);
        var out = '';
        for (var i = 0; i < bytes.length; i++) {
            out += alphabet[bytes[i] % alphabet.length];
        }
        target.value = out;
        target.type = 'text';
    });

    /* ---------- mobile nav ---------- */
    // On phones the sidebar collapses into a sticky header; the hamburger opens
    // the nav as a dropdown panel under it.
    document.addEventListener('click', function (event) {
        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        var toggle = event.target.closest('.nav-toggle');
        if (toggle) {
            var open = sidebar.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            return;
        }

        // Tapping anywhere outside the open panel closes it.
        if (sidebar.classList.contains('open') && !event.target.closest('.sidebar')) {
            sidebar.classList.remove('open');
            var button = sidebar.querySelector('.nav-toggle');
            if (button) button.setAttribute('aria-expanded', 'false');
        }
    });
})();

/* ---------- auto-refresh for pages an operator watches ---------- */
(function () {
    'use strict';

    // Opt-in per page via <body data-autorefresh> ... except the pages that watch
    // live state, where it is the point. A page reload is used rather than a partial
    // fetch because these pages are server-rendered; the cost is one small request
    // on an interval the operator chooses.
    var pages = ['index.php', 'trips.php', 'register.php'];
    var current = window.location.pathname.split('/').pop() || 'index.php';
    if (pages.indexOf(current) === -1) return;

    var bar = document.querySelector('.toolbar');
    if (!bar) return;

    var KEY = 'att_autorefresh_' + current;
    var stored = parseInt(window.localStorage.getItem(KEY) || '0', 10);

    var wrap = document.createElement('div');
    wrap.className = 'field';
    wrap.innerHTML =
        '<label for="autoRefresh">Auto refresh</label>' +
        '<select id="autoRefresh">' +
        '<option value="0">Off</option>' +
        '<option value="15">Every 15 seconds</option>' +
        '<option value="30">Every 30 seconds</option>' +
        '<option value="60">Every minute</option>' +
        '</select>';

    // Placed at the end of the toolbar so it never displaces the filters.
    var spacer = bar.querySelector('.spacer');
    if (spacer) { bar.insertBefore(wrap, spacer); } else { bar.appendChild(wrap); }

    var select = wrap.querySelector('#autoRefresh');
    select.value = String(stored || 0);

    var timer = null;
    function apply() {
        if (timer) { clearInterval(timer); timer = null; }
        var seconds = parseInt(select.value, 10);
        window.localStorage.setItem(KEY, String(seconds));
        if (seconds > 0) {
            timer = setInterval(function () {
                // Never reload out from under someone filling in a form or reading a
                // modal — losing typed input to a refresh is worse than stale data.
                if (document.querySelector('.modal-backdrop.open')) return;
                if (document.activeElement &&
                    /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) &&
                    document.activeElement.id !== 'autoRefresh') return;
                window.location.reload();
            }, seconds * 1000);
        }
    }

    select.addEventListener('change', apply);
    apply();
})();
