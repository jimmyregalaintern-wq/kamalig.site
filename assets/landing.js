// KAMALIG landing + login (combined) interactions
(function () {
  'use strict';

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- Reveal-on-scroll (small fade/translate, once) ---- */
  var revealTargets = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && !reduceMotion) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -4% 0px' });
    revealTargets.forEach(function (el) { io.observe(el); });
  } else {
    revealTargets.forEach(function (el) { el.classList.add('is-visible'); });
  }

  /* ---- Nav: shadow on scroll + mobile menu ---- */
  var nav = document.querySelector('.nav');
  if (nav) {
    var onScroll = function () { nav.classList.toggle('is-scrolled', window.scrollY > 8); };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }
  var navToggle = document.getElementById('navToggle');
  var navLinks = document.getElementById('navLinks');
  if (navToggle && navLinks) {
    var setMenu = function (open) {
      navLinks.classList.toggle('is-open', open);
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      navToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    };
    navToggle.addEventListener('click', function () { setMenu(navToggle.getAttribute('aria-expanded') !== 'true'); });
    navLinks.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function () { setMenu(false); }); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && navLinks.classList.contains('is-open')) { setMenu(false); navToggle.focus(); }
    });
    window.addEventListener('resize', function () { if (window.innerWidth > 820) { setMenu(false); } });
  }

  /* ---- Floating auth modal: open/close + Sign in <-> Register switch ----
     Login and Register live in a fixed-position overlay (.auth-overlay) that
     floats above the page -- opened from the nav, hero, and CTA buttons (all
     data-open-tab="login"/"register") and closed via the x button, a backdrop
     click, or Escape. It's the same PHP page and the same forms throughout;
     nothing here ever navigates away. */
  var overlay = document.getElementById('authOverlay');
  var access = overlay ? overlay.querySelector('.access') : null;
  if (overlay && access) {
    var panels = access.querySelectorAll('.access__panel');
    var panelsWrap = access.querySelector('.access__panels');
    var lastFocused = null;

    var setWrapHeight = function (panelEl) {
      if (!panelsWrap || !panelEl) return;
      panelsWrap.style.height = panelEl.offsetHeight + 'px';
    };

    var setTab = function (name) {
      var target = null;
      panels.forEach(function (p) { if (p.dataset.panel === name) target = p; });
      if (target) { setWrapHeight(target); }
      access.setAttribute('data-tab', name);
      access.setAttribute('aria-labelledby', 'auth-title-' + name);
      panels.forEach(function (p) { p.classList.toggle('is-active', p.dataset.panel === name); });
    };

    var isOpen = function () { return overlay.classList.contains('is-open'); };

    var openModal = function (name) {
      if (!isOpen()) { lastFocused = document.activeElement; }  // keep the original opener when switching tabs
      setTab(name || access.getAttribute('data-tab') || 'login');
      overlay.classList.add('is-open');
      overlay.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');
      var activePanel = access.querySelector('.access__panel.is-active');
      var focusDelay = reduceMotion ? 0 : 260;
      window.setTimeout(function () {
        var firstField = activePanel && activePanel.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea');
        if (firstField) { firstField.focus({ preventScroll: true }); }
      }, focusDelay);
    };

    var closeModal = function () {
      overlay.classList.remove('is-open');
      overlay.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('modal-open');
      if (lastFocused && typeof lastFocused.focus === 'function') { lastFocused.focus({ preventScroll: true }); }
    };

    // Prime the container to the initially-active panel's height (no
    // transition on first paint -- avoids a visible height jump).
    var initialPanel = access.querySelector('.access__panel.is-active') || panels[0];
    if (panelsWrap && initialPanel) {
      panelsWrap.style.transition = 'none';
      setWrapHeight(initialPanel);
      requestAnimationFrame(function () { panelsWrap.style.transition = ''; });
    }
    window.addEventListener('resize', function () {
      var current = access.querySelector('.access__panel.is-active');
      if (current) { setWrapHeight(current); }
    });

    // Any button/link with data-open-tab (nav, hero, CTA band, and the
    // "Register" / "Login" links inside the forms themselves) opens the modal
    // straight to that tab.
    document.querySelectorAll('[data-open-tab]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        openModal(btn.getAttribute('data-open-tab'));
      });
    });

    overlay.querySelectorAll('[data-auth-close]').forEach(function (el) {
      el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (e) {
      if (!isOpen()) return;
      if (e.key === 'Escape') { closeModal(); return; }
      // Keep keyboard focus inside the dialog while it is open.
      if (e.key === 'Tab') {
        var focusables = Array.prototype.filter.call(
          access.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select, textarea, summary'),
          function (el) { return el.offsetParent !== null && !el.closest('.access__panel:not(.is-active)'); }
        );
        if (!focusables.length) return;
        var first = focusables[0], last = focusables[focusables.length - 1];
        if (!access.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
        else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });

    // Auto-open on load if the server has something to show (a failed
    // login/register attempt, or the "please log in" success message after
    // OTP verification) -- $modalAutoOpen in landing/index.php.
    if (overlay.getAttribute('data-autoopen') === '1') { openModal(); }

    // Deep-link support: /landing/#access-login or #access-register opens
    // the modal straight away (used by logout, require_auth, and the OTP/
    // registration flow).
    var openFromHash = function () {
      if (window.location.hash === '#access-login') { openModal('login'); }
      if (window.location.hash === '#access-register') { openModal('register'); }
    };
    openFromHash();
    window.addEventListener('hashchange', openFromHash);
  }

  /* ---- Password show/hide toggle ---- */
  document.querySelectorAll('.access-login__toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-target'));
      if (!input) return;
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.textContent = showing ? 'Show' : 'Hide';
      btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
      btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
  });

  /* ---- Disable-on-submit for the login form (avoid double POST, show state) ---- */
  var loginForm = document.querySelector('.access-login form');
  if (loginForm) {
    var resetLogin = function () {
      var b = loginForm.querySelector('button[type="submit"]');
      if (b && b.hasAttribute('disabled')) { b.removeAttribute('disabled'); b.textContent = 'Sign In'; }
    };
    loginForm.addEventListener('submit', function () {
      var submitBtn = loginForm.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.setAttribute('disabled', 'disabled');
        submitBtn.textContent = 'Signing in…';
      }
    });
    // Back/forward cache restores the page as it was left: re-enable the button.
    window.addEventListener('pageshow', function (e) { if (e.persisted) { resetLogin(); } });
  }

  /* ---- Hero background video (progressive enhancement) ----
     The poster photo is the real hero; video is layered on top only when it is
     cheap and welcome: wide screen, no reduced-motion, no data-saver / slow
     connection. It is muted, looped, paused when off-screen or the tab is
     hidden, and can be paused by the visitor. If anything fails, the poster stays. */
  var video = document.getElementById('heroVideo');
  var motionBtn = document.getElementById('heroMotion');
  if (video) {
    var conn = navigator.connection || {};
    var slow = conn.saveData === true || /(^|-)2g$/.test(conn.effectiveType || '') || conn.effectiveType === '3g';
    var wide = window.matchMedia('(min-width: 901px)').matches;
    if (!reduceMotion && wide && !slow) {
      var userPaused = false, started = false, inView = true;
      var label = motionBtn ? motionBtn.querySelector('span') : null;

      var setBtn = function () {
        if (!motionBtn) return;
        motionBtn.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
        if (label) { label.textContent = userPaused ? 'Play background video' : 'Pause background video'; }
      };
      var tryPlay = function () {
        if (userPaused || !inView || document.hidden) return;
        var p = video.play();
        if (p && p.catch) { p.catch(function () { /* autoplay blocked: poster stays */ }); }
      };
      var start = function () {
        if (started) return;
        started = true;
        [['webm', 'video/webm'], ['mp4', 'video/mp4']].forEach(function (t) {
          var url = video.getAttribute('data-' + t[0]);
          if (url && video.canPlayType(t[1])) {
            var s = document.createElement('source');
            s.src = url; s.type = t[1];
            video.appendChild(s);
          }
        });
        if (!video.querySelector('source')) return;
        video.addEventListener('playing', function () {
          video.classList.add('is-playing');
          if (motionBtn) { motionBtn.classList.add('is-available'); }
        });
        video.addEventListener('error', function () { if (motionBtn) { motionBtn.classList.remove('is-available'); } }, true);
        video.load();
        tryPlay();
      };

      if (motionBtn) {
        motionBtn.addEventListener('click', function () {
          userPaused = !userPaused;
          if (userPaused) { video.pause(); } else { tryPlay(); }
          setBtn();
        });
      }
      if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
          inView = entries[0].isIntersecting;
          if (!started) return;
          if (inView) { tryPlay(); } else { video.pause(); }
        }, { threshold: 0.05 }).observe(video.parentNode);
      }
      document.addEventListener('visibilitychange', function () {
        if (!started) return;
        if (document.hidden) { video.pause(); } else { tryPlay(); }
      });
      // Defer until the page has painted so the video never competes with the hero image / fonts.
      if (document.readyState === 'complete') { start(); } else { window.addEventListener('load', start); }
    }
  }
})();
