(function () {
  'use strict';

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- Styled confirmation modals (replaces browser confirm()) ----------
     Any <form> or <button> can opt in with data-confirm-modal="Message text"
     (optionally data-confirm-title="..." and data-confirm-danger to style the
     confirm button as destructive, which is the default for archive/reject/
     suspend/cancel-style actions — pass data-confirm-danger="0" to opt out).
     A form submits for real once confirmed; a plain button click re-dispatches
     its click with a one-time bypass flag so this listener doesn't loop. */
  var confirmModalEl = null;
  function buildConfirmModal() {
    if (confirmModalEl) { return confirmModalEl; }
    var wrap = document.createElement('div');
    wrap.className = 'kg-confirm-overlay';
    wrap.innerHTML =
      '<div class="kg-confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="kg-confirm-title" aria-describedby="kg-confirm-message">' +
        '<p class="kg-confirm-modal__title" id="kg-confirm-title"></p>' +
        '<p class="kg-confirm-modal__message" id="kg-confirm-message"></p>' +
        '<div class="kg-confirm-modal__actions">' +
          '<button type="button" class="btn btn-secondary" data-confirm-cancel>Cancel</button>' +
          '<button type="button" class="btn btn-danger" data-confirm-ok>Confirm</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(wrap);
    confirmModalEl = wrap;
    return wrap;
  }

  function showConfirmModal(options) {
    var modalWrap = buildConfirmModal();
    var titleEl = modalWrap.querySelector('#kg-confirm-title');
    var msgEl = modalWrap.querySelector('#kg-confirm-message');
    var confirmBtn = modalWrap.querySelector('[data-confirm-ok]');
    var cancelBtn = modalWrap.querySelector('[data-confirm-cancel]');

    titleEl.textContent = options.title || 'Please confirm';
    msgEl.textContent = options.message || 'Are you sure?';
    confirmBtn.className = 'btn ' + (options.danger === false ? 'btn-primary' : 'btn-danger');
    confirmBtn.textContent = options.confirmLabel || (options.danger === false ? 'Confirm' : 'Confirm');

    modalWrap.classList.add('is-open');
    // Focus the least-destructive action by default so a stray Enter
    // keypress (e.g. from just having typed in a nearby field) cancels
    // rather than commits an irreversible action.
    cancelBtn.focus();

    function close() {
      modalWrap.classList.remove('is-open');
      confirmBtn.removeEventListener('click', onConfirm);
      cancelBtn.removeEventListener('click', onCancel);
      document.removeEventListener('keydown', onKeydown);
    }
    function onConfirm() { close(); options.onConfirm && options.onConfirm(); }
    function onCancel() { close(); options.onCancel && options.onCancel(); }
    function onKeydown(e) { if (e.key === 'Escape') { onCancel(); } }

    confirmBtn.addEventListener('click', onConfirm);
    cancelBtn.addEventListener('click', onCancel);
    document.addEventListener('keydown', onKeydown);
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) { return; }
    // A submit button's own data-confirm-modal takes priority over the
    // form's — this is what lets a form with two submit buttons (e.g.
    // Verify + Reject) only confirm on the destructive one.
    var source = (e.submitter && e.submitter.hasAttribute('data-confirm-modal')) ? e.submitter : form;
    if (!source.hasAttribute('data-confirm-modal')) { return; }
    if (form.dataset.confirmBypass === '1') { delete form.dataset.confirmBypass; return; }
    e.preventDefault();
    showConfirmModal({
      title: source.getAttribute('data-confirm-title') || 'Please confirm',
      message: source.getAttribute('data-confirm-modal'),
      danger: source.getAttribute('data-confirm-danger') !== '0',
      onConfirm: function () {
        form.dataset.confirmBypass = '1';
        // The button that triggered submission may need to be re-included
        // (name=action / value=archive|reject etc.) — re-dispatch via the
        // originating submitter when available so name/value pairs still
        // reach the server the same as a normal click would have.
        if (e.submitter && form.requestSubmit) {
          form.requestSubmit(e.submitter);
        } else {
          form.submit();
        }
      },
    });
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('button[data-confirm-modal]');
    if (!btn || btn.closest('form')) { return; } // forms are handled by the submit listener above
    if (btn.dataset.confirmBypass === '1') { delete btn.dataset.confirmBypass; return; }
    e.preventDefault();
    showConfirmModal({
      title: btn.getAttribute('data-confirm-title') || 'Please confirm',
      message: btn.getAttribute('data-confirm-modal'),
      danger: btn.getAttribute('data-confirm-danger') !== '0',
      onConfirm: function () {
        btn.dataset.confirmBypass = '1';
        btn.click();
      },
    });
  });

  /* ---------- Sidebar collapse (persisted) ---------- */
  var sidebar = document.getElementById('sidebar');
  var collapseBtn = document.getElementById('sidebar-collapse-btn');
  if (sidebar && collapseBtn) {
    try {
      if (localStorage.getItem('kg-sidebar-collapsed') === '1') {
        sidebar.classList.add('is-collapsed');
      }
    } catch (e) { /* localStorage unavailable — ignore, sidebar just stays expanded */ }

    collapseBtn.addEventListener('click', function () {
      sidebar.classList.toggle('is-collapsed');
      try {
        localStorage.setItem('kg-sidebar-collapsed', sidebar.classList.contains('is-collapsed') ? '1' : '0');
      } catch (e) { /* ignore */ }
    });
  }

  /* ---------- Mobile sidebar toggle ---------- */
  var mobileMenuBtn = document.getElementById('mobile-menu-btn');
  var sidebarScrim = document.getElementById('sidebar-scrim');
  if (mobileMenuBtn && sidebar) {
    mobileMenuBtn.addEventListener('click', function () {
      sidebar.classList.add('is-mobile-open');
      if (sidebarScrim) { sidebarScrim.classList.add('is-visible'); }
    });
  }
  if (sidebarScrim && sidebar) {
    sidebarScrim.addEventListener('click', function () {
      sidebar.classList.remove('is-mobile-open');
      sidebarScrim.classList.remove('is-visible');
    });
  }

  /* ---------- Topbar search expand ---------- */
  var searchWrap = document.querySelector('.topbar-search');
  var searchBtn = document.getElementById('topbar-search-btn');
  var searchInput = document.getElementById('topbar-search-input');
  if (searchWrap && searchBtn && searchInput) {
    searchBtn.addEventListener('click', function () {
      searchWrap.classList.toggle('is-open');
      if (searchWrap.classList.contains('is-open')) {
        searchInput.focus();
      }
    });
    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        searchWrap.classList.remove('is-open');
        searchInput.blur();
      }
    });
  }

  /* ---------- Close notif / profile dropdowns on outside click or Escape ---------- */
  var dropdownToggles = document.querySelectorAll('.notif-toggle, .profile-toggle');
  if (dropdownToggles.length) {
    document.addEventListener('click', function (e) {
      dropdownToggles.forEach(function (toggle) {
        if (!toggle.checked) { return; }
        var wrap = toggle.closest('.notif-bell, .profile-menu');
        if (wrap && !wrap.contains(e.target)) { toggle.checked = false; }
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') { return; }
      dropdownToggles.forEach(function (toggle) { toggle.checked = false; });
    });
  }

  /* ---------- KPI count-up (skips entirely under reduced motion) ---------- */
  var countEls = document.querySelectorAll('.kg-countup[data-value]');
  if (countEls.length) {
    countEls.forEach(function (el) {
      var target = parseFloat(el.getAttribute('data-value')) || 0;
      var decimals = el.getAttribute('data-decimals') ? parseInt(el.getAttribute('data-decimals'), 10) : 0;
      var prefix = el.getAttribute('data-prefix') || '';
      var suffix = el.getAttribute('data-suffix') || '';

      if (reduceMotion) {
        el.textContent = prefix + target.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;
        return;
      }

      var duration = 900;
      var start = null;
      function step(ts) {
        if (start === null) { start = ts; }
        var progress = Math.min(1, (ts - start) / duration);
        var eased = 1 - Math.pow(1 - progress, 3);
        var current = target * eased;
        el.textContent = prefix + current.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;
        if (progress < 1) {
          window.requestAnimationFrame(step);
        }
      }
      window.requestAnimationFrame(step);
    });
  }

  /* ---------- Scroll reveal for major sections ---------- */
  var revealEls = document.querySelectorAll('.kg-reveal');
  if (revealEls.length) {
    if (reduceMotion || !('IntersectionObserver' in window)) {
      revealEls.forEach(function (el) { el.classList.add('is-visible'); });
    } else {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12 });
      revealEls.forEach(function (el) { observer.observe(el); });
    }
  }

  /* ---------- Tooltip system for [data-tooltip] ---------- */
  var tooltipBubble = document.createElement('div');
  tooltipBubble.className = 'kg-tooltip-bubble';
  document.body.appendChild(tooltipBubble);
  var tooltipTimer = null;

  document.addEventListener('mouseover', function (e) {
    var target = e.target.closest ? e.target.closest('[data-tooltip]') : null;
    if (!target) { return; }
    var text = target.getAttribute('data-tooltip');
    if (!text) { return; }
    window.clearTimeout(tooltipTimer);
    tooltipTimer = window.setTimeout(function () {
      var rect = target.getBoundingClientRect();
      tooltipBubble.textContent = text;
      tooltipBubble.style.left = Math.round(rect.left + rect.width / 2) + 'px';
      tooltipBubble.style.top = Math.round(rect.bottom + 8) + 'px';
      tooltipBubble.style.transform = 'translateX(-50%)';
      tooltipBubble.classList.add('is-visible');
    }, 300);
  });
  document.addEventListener('mouseout', function (e) {
    var target = e.target.closest ? e.target.closest('[data-tooltip]') : null;
    if (!target) { return; }
    window.clearTimeout(tooltipTimer);
    tooltipBubble.classList.remove('is-visible');
  });

  /* ---------- Notification bell: shake once if there are unread items on load ---------- */
  var bellBadge = document.querySelector('.notif-bell__badge');
  if (bellBadge && !reduceMotion) {
    var bellIcon = document.querySelector('.notif-bell__button svg');
    if (bellIcon) {
      bellIcon.style.animation = 'kg-bell-shake 420ms ease-in-out 1';
    }
  }

  var styleTag = document.createElement('style');
  styleTag.textContent = '@keyframes kg-bell-shake{0%,100%{transform:rotate(0)}20%{transform:rotate(-12deg)}40%{transform:rotate(10deg)}60%{transform:rotate(-6deg)}80%{transform:rotate(4deg)}}';
  document.head.appendChild(styleTag);

  /* ---------- Dashboard chart range pills (7D/30D/6M/1Y) ---------- */
  var chartPills = document.querySelectorAll('[data-chart-range]');
  var chartPanels = document.querySelectorAll('[data-chart-panel]');
  if (chartPills.length && chartPanels.length) {
    chartPills.forEach(function (pill) {
      pill.addEventListener('click', function () {
        if (pill.disabled) { return; }
        var range = pill.getAttribute('data-chart-range');
        chartPills.forEach(function (p) { p.classList.toggle('active', p === pill); });
        chartPanels.forEach(function (panel) {
          panel.classList.toggle('is-active', panel.getAttribute('data-chart-panel') === range);
        });
      });
    });
  }

  /* ---------- AI analysis slide-in panel ---------- */
  var analysisTriggers = document.querySelectorAll('[data-open-analysis]');
  var analysisPanel = document.getElementById('kg-analysis-panel');
  var analysisOverlay = document.getElementById('kg-analysis-overlay');
  function closeAnalysis() {
    if (!analysisPanel) { return; }
    analysisPanel.classList.remove('is-open');
    if (analysisOverlay) { analysisOverlay.classList.remove('is-open'); }
  }
  if (analysisPanel && analysisTriggers.length) {
    analysisTriggers.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        analysisPanel.classList.add('is-open');
        if (analysisOverlay) { analysisOverlay.classList.add('is-open'); }
      });
    });
    var closeBtn = document.getElementById('kg-analysis-close');
    if (closeBtn) { closeBtn.addEventListener('click', closeAnalysis); }
    if (analysisOverlay) { analysisOverlay.addEventListener('click', closeAnalysis); }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { closeAnalysis(); }
    });
  }
})();

/* ---------- Row-actions modal (populated from a per-row <template>) ----------
   Used on tables where too many per-row buttons would otherwise be crammed
   inline (Inventory, etc). Each "Actions" trigger points at a <template>
   holding the real links/forms for that row (so CSRF forms keep working
   unmodified); clicking the trigger clones the template into one shared
   modal instead of building a modal per row. */
(function () {
  'use strict';

  var backdrop = document.getElementById('kg-action-modal');
  if (!backdrop) { return; }
  var titleEl = document.getElementById('kg-action-modal-title');
  var subtitleEl = document.getElementById('kg-action-modal-subtitle');
  var bodyEl = document.getElementById('kg-action-modal-body');
  var closeEls = backdrop.querySelectorAll('[data-modal-close]');

  function openModal(trigger) {
    var templateId = trigger.getAttribute('data-modal-template');
    var template = templateId ? document.getElementById(templateId) : null;
    if (!template) { return; }
    if (titleEl) { titleEl.textContent = trigger.getAttribute('data-modal-title') || 'Actions'; }
    if (subtitleEl) { subtitleEl.textContent = trigger.getAttribute('data-modal-subtitle') || ''; }
    bodyEl.innerHTML = '';
    bodyEl.appendChild(template.content.cloneNode(true));
    backdrop.classList.add('is-open');
  }

  function closeModal() {
    backdrop.classList.remove('is-open');
    bodyEl.innerHTML = '';
  }

  document.querySelectorAll('[data-modal-trigger]').forEach(function (trigger) {
    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      openModal(trigger);
    });
  });
  closeEls.forEach(function (el) { el.addEventListener('click', closeModal); });
  backdrop.addEventListener('click', function (e) {
    if (e.target === backdrop) { closeModal(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && backdrop.classList.contains('is-open')) { closeModal(); }
  });
})();

/* ---------- Bulk row selection + sticky action bar ----------
   Generic controller: any table with a `data-bulk-select` root, a
   "select all" checkbox `[data-bulk-select-all]`, row checkboxes named
   `data-bulk-row`, and a bar `[data-bulk-bar]` with a `[data-bulk-count]`
   label wires itself up automatically. */
(function () {
  'use strict';

  document.querySelectorAll('[data-bulk-select]').forEach(function (root) {
    var bar = document.querySelector(root.getAttribute('data-bulk-bar-target') || '[data-bulk-bar]');
    var selectAll = root.querySelector('[data-bulk-select-all]');
    var countEl = bar ? bar.querySelector('[data-bulk-count]') : null;

    function rowBoxes() {
      return Array.prototype.slice.call(root.querySelectorAll('[data-bulk-row]:not(:disabled)'));
    }

    function refresh() {
      var boxes = rowBoxes();
      var checked = boxes.filter(function (b) { return b.checked; });
      if (bar) { bar.classList.toggle('is-visible', checked.length > 0); }
      if (countEl) { countEl.textContent = checked.length + ' selected'; }
      if (selectAll) {
        selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
      }
      // Mirror the selection into any bulk-action forms' hidden id lists.
      document.querySelectorAll('[data-bulk-ids-for]').forEach(function (container) {
        if (container.getAttribute('data-bulk-ids-for') !== root.id) { return; }
        container.querySelectorAll('input[data-bulk-id-input]').forEach(function (i) { i.remove(); });
        checked.forEach(function (b) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'stockIds[]';
          input.value = b.value;
          input.setAttribute('data-bulk-id-input', '1');
          container.appendChild(input);
        });
      });
    }

    rowBoxes().forEach(function (box) { box.addEventListener('change', refresh); });
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        rowBoxes().forEach(function (b) { b.checked = selectAll.checked; });
        refresh();
      });
    }
    refresh();
  });
})();
