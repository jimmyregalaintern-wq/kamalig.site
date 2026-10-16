/**
 * Voice input for chat/message textareas (Web Speech API).
 *
 * Progressive enhancement only — no backend, no API key, no dependency.
 * Any <button data-voice-input="targetTextareaId"> gets wired to speech
 * recognition; the button is left as-is (and effectively inert) in
 * browsers that don't support SpeechRecognition, since we hide it via
 * the .voice-input-btn[hidden] rule below instead of removing markup.
 *
 * Support: Chrome/Edge (desktop + Android) via webkitSpeechRecognition.
 * Safari has partial/inconsistent support; Firefox has none as of this
 * writing — those users just don't see the mic button.
 */
(function () {
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    document.querySelectorAll('[data-voice-input]').forEach(function (btn) {
        if (!SpeechRecognition) {
            btn.hidden = true;
            return;
        }

        var targetId = btn.getAttribute('data-voice-input');
        var target = document.getElementById(targetId);
        if (!target) {
            btn.hidden = true;
            return;
        }

        var recognition = new SpeechRecognition();
        recognition.lang = document.documentElement.lang || 'en-US';
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;

        var listening = false;

        function setListening(state) {
            listening = state;
            btn.classList.toggle('voice-input-btn--active', state);
            btn.setAttribute('aria-pressed', state ? 'true' : 'false');
            btn.title = state ? 'Listening… click to stop' : 'Speak your question';
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (listening) {
                recognition.stop();
                return;
            }
            try {
                recognition.start();
                setListening(true);
            } catch (err) {
                // start() throws if already running (rapid double-click) — ignore.
            }
        });

        recognition.addEventListener('result', function (event) {
            var transcript = event.results[0][0].transcript;
            var sep = target.value && !/\s$/.test(target.value) ? ' ' : '';
            target.value = target.value + sep + transcript;
            target.dispatchEvent(new Event('input', { bubbles: true }));
            target.focus();
        });

        recognition.addEventListener('end', function () {
            setListening(false);
        });

        recognition.addEventListener('error', function (event) {
            setListening(false);
            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                btn.title = 'Microphone access was blocked';
            }
        });
    });
})();
