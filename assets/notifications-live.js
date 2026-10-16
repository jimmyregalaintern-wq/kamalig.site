/* Live notifications: polls notifications/poll/ and updates the bell badge,
   the dropdown list and shows a small toast for newly arrived items.
   Short polling (no WebSockets) so it works on shared hosting. */
(function () {
    'use strict';
    var bell = document.querySelector('.notif-bell[data-poll-url]');
    if (!bell || !window.fetch) { return; }

    var pollUrl = bell.getAttribute('data-poll-url');
    var notifUrl = bell.getAttribute('data-notif-url') || '';
    var actionUrl = bell.getAttribute('data-action-url') || notifUrl;
    var returnTo = bell.getAttribute('data-return-to') || '';
    var button = bell.querySelector('.notif-bell__button');
    var dropdown = bell.querySelector('.notif-dropdown');
    var BASE = 45000, MAX = 300000, delay = BASE, failures = 0;
    var timer = null, stopped = false, busy = false, lastRun = 0, first = true;
    var seen = {};

    function setBadge(n) {
        var badge = bell.querySelector('.notif-bell__badge');
        if (n > 0) {
            if (!badge && button) {
                badge = document.createElement('span');
                badge.className = 'notif-bell__badge';
                button.appendChild(badge);
            }
            if (badge) { badge.textContent = n > 9 ? '9+' : String(n); }
        } else if (badge) {
            badge.parentNode.removeChild(badge);
        }
    }

    function hidden(name, value) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = name; i.value = value;
        return i;
    }

    function renderDropdown(data) {
        if (!dropdown) { return; }
        var header = dropdown.querySelector('.notif-dropdown__header');
        var viewAll = dropdown.querySelector('.notif-dropdown__viewall');
        if (!header || !viewAll) { return; }

        var old = dropdown.querySelectorAll('.notif-dropdown__item, .notif-dropdown__empty');
        for (var i = 0; i < old.length; i++) { old[i].parentNode.removeChild(old[i]); }

        var markAll = header.querySelector('form');
        if (data.count > 0 && !markAll && actionUrl) {
            markAll = document.createElement('form');
            markAll.method = 'post'; markAll.action = actionUrl;
            markAll.appendChild(hidden('csrf_token', data.csrf));
            markAll.appendChild(hidden('action', 'mark_all'));
            markAll.appendChild(hidden('returnTo', returnTo));
            var b = document.createElement('button');
            b.className = 'notif-dropdown__markall'; b.type = 'submit';
            b.textContent = 'Mark all as read';
            markAll.appendChild(b);
            header.appendChild(markAll);
        } else if (data.count === 0 && markAll) {
            markAll.parentNode.removeChild(markAll);
        }

        if (!data.items.length) {
            var empty = document.createElement('p');
            empty.className = 'muted notif-dropdown__empty';
            empty.textContent = 'No unread notifications.';
            dropdown.insertBefore(empty, viewAll);
            return;
        }
        data.items.forEach(function (n) {
            var f = document.createElement('form');
            f.method = 'post'; f.action = actionUrl; f.className = 'notif-dropdown__item';
            f.appendChild(hidden('csrf_token', data.csrf));
            f.appendChild(hidden('notificationId', n.id));
            f.appendChild(hidden('returnTo', returnTo));
            var btn = document.createElement('button');
            btn.type = 'submit'; btn.className = 'notif-dropdown__item-btn';
            var t = document.createElement('span');
            t.className = 'notif-dropdown__title'; t.textContent = n.title;
            var m = document.createElement('span');
            m.className = 'notif-dropdown__msg'; m.textContent = n.message;
            btn.appendChild(t); btn.appendChild(m);
            f.appendChild(btn);
            dropdown.insertBefore(f, viewAll);
        });
    }

    function toast(n) {
        var old = document.getElementById('notif-live-toast');
        if (old) { old.parentNode.removeChild(old); }
        var el = document.createElement('a');
        el.id = 'notif-live-toast';
        el.href = notifUrl || '#';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:9999;max-width:320px;' +
            'padding:12px 14px;border-radius:10px;background:#1f2937;color:#fff;text-decoration:none;' +
            'box-shadow:0 6px 20px rgba(0,0,0,.25);font-size:14px;line-height:1.35;';
        var t = document.createElement('strong');
        t.style.display = 'block'; t.textContent = n.title;
        var m = document.createElement('span');
        m.style.cssText = 'display:block;opacity:.85;margin-top:2px;'; m.textContent = n.message;
        el.appendChild(t); el.appendChild(m);
        document.body.appendChild(el);
        setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 7000);
    }

    function schedule() {
        if (stopped) { return; }
        clearTimeout(timer);
        timer = setTimeout(poll, delay);
    }

    function poll() {
        if (stopped || busy) { return; }
        if (document.hidden) { schedule(); return; }
        busy = true;
        lastRun = Date.now();
        fetch(pollUrl, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                if (res.status === 401) { stopped = true; throw new Error('signed-out'); }
                if (!res.ok) { throw new Error('http-' + res.status); }
                return res.json();
            })
            .then(function (data) {
                if (!data || data.ok !== true) { throw new Error('bad-payload'); }
                var fresh = [];
                data.items.forEach(function (n) {
                    if (!seen[n.id]) { seen[n.id] = true; if (!first) { fresh.push(n); } }
                });
                setBadge(data.count);
                renderDropdown(data);
                if (fresh.length) { toast(fresh[0]); }
                first = false;
                failures = 0; delay = BASE;
            })
            .catch(function () {
                if (stopped) { return; }
                failures++;
                if (failures >= 6) { stopped = true; return; }
                delay = Math.min(delay * 2, MAX);
            })
            .then(function () { busy = false; schedule(); });
    }

    // Remember what is already shown so only genuinely new items trigger a toast.
    var existing = dropdown ? dropdown.querySelectorAll('input[name="notificationId"]') : [];
    for (var k = 0; k < existing.length; k++) { seen[existing[k].value] = true; }
    first = false;

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && !stopped && Date.now() - lastRun > 10000) { poll(); }
    });
    schedule();
})();
