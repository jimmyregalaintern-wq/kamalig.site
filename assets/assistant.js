/**
 * AI Assistant widget — progressive enhancement.
 *
 * Without JS the widget is a normal form post + redirect (messages/send/).
 * With JS, the form is intercepted: the message is posted with fetch(), a
 * typing indicator shows while the agent works, and the reply is appended in
 * place. All text is inserted with textContent (never innerHTML), so model or
 * user output can't inject markup.
 */
(function () {
    var form = document.getElementById('assistant-form');
    var list = document.getElementById('assistant-body');
    if (!form || !list || !window.fetch) { return; }

    var field = form.querySelector('textarea[name="body"]');
    var sendBtn = form.querySelector('button[type="submit"]');
    var busy = false;
    var REQUEST_TIMEOUT_MS = 100000; // server allows the agent up to ~90s

    function scrollDown() { list.scrollTop = list.scrollHeight; }

    function clearEmptyState() {
        var empty = list.querySelector('.assistant-panel__empty');
        if (empty) { empty.remove(); }
    }

    function addMessage(side, label, text) {
        var wrap = document.createElement('div');
        wrap.className = 'assistant-msg assistant-msg--' + side;

        var lab = document.createElement('div');
        lab.className = 'muted assistant-msg__label';
        lab.textContent = label;

        var bubble = document.createElement('div');
        bubble.className = 'assistant-msg__bubble assistant-msg__bubble--live';
        bubble.textContent = text;

        wrap.appendChild(lab);
        wrap.appendChild(bubble);
        list.appendChild(wrap);
        scrollDown();
        return { wrap: wrap, bubble: bubble };
    }

    function addTyping() {
        var m = addMessage('left', 'Assistant', '');
        m.wrap.classList.add('assistant-msg--typing');
        m.bubble.setAttribute('aria-label', 'Assistant is typing');
        for (var i = 0; i < 3; i++) {
            var dot = document.createElement('span');
            dot.className = 'assistant-dot';
            m.bubble.appendChild(dot);
        }
        return m;
    }

    function setBusy(state) {
        busy = state;
        if (sendBtn) { sendBtn.disabled = state; }
        form.classList.toggle('is-busy', state);
    }

    function fail(typing, text, message) {
        typing.wrap.remove();
        addMessage('left', 'Assistant', message);
        // Give the text back so the user doesn't have to retype it.
        if (field && field.value === '') { field.value = text; }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (busy || !field) { return; }

        var text = field.value.trim();
        if (text === '') { return; }

        var data = new FormData(form); // includes csrf_token + body
        setBusy(true);
        clearEmptyState();
        addMessage('right', 'You', text);
        field.value = '';
        var typing = addTyping();

        var controller = window.AbortController ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, REQUEST_TIMEOUT_MS) : null;

        fetch(form.getAttribute('action'), {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
            signal: controller ? controller.signal : undefined
        })
            .then(function (res) {
                var type = res.headers.get('content-type') || '';
                if (type.indexOf('application/json') === -1) {
                    // Redirected to login / HTML error page.
                    throw new Error('Your session may have expired. Please reload the page.');
                }
                return res.json();
            })
            .then(function (json) {
                if (!json.ok) { throw new Error(json.error || 'Something went wrong.'); }
                typing.wrap.remove();
                var m = addMessage('left', json.escalated ? 'Assistant (flagged for support)' : 'Assistant', json.reply);
                if (json.escalated) { m.wrap.classList.add('assistant-msg--escalated'); }
            })
            .catch(function (err) {
                var aborted = err && err.name === 'AbortError';
                fail(typing, text, aborted
                    ? 'That took too long. Please try again.'
                    : (err && err.message ? err.message : 'Something went wrong. Please try again.'));
            })
            .then(function () {
                if (timer) { clearTimeout(timer); }
                setBusy(false);
                if (field) { field.focus(); }
            });
    });

    // Enter sends, Shift+Enter inserts a newline (skip while an IME is composing).
    if (field) {
        field.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                if (form.requestSubmit) { form.requestSubmit(); } else if (sendBtn) { sendBtn.click(); }
            }
        });
    }

    scrollDown();
})();
