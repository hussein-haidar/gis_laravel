<button type="button" class="gis-chat-fab" id="gis-chat-fab"
        aria-label="{{ __('chat.open') }}" aria-expanded="false" aria-controls="gis-chat-panel">
    <span id="gis-chat-fab-icon">💬</span>
</button>

<div class="gis-chat-panel" id="gis-chat-panel" role="dialog" aria-modal="false"
     aria-label="{{ __('chat.title') }}">

    <div class="gis-chat-header">
        <span aria-hidden="true">🤖</span>
        <h6>{{ __('chat.title') }}</h6>
        <button type="button" class="gis-chat-btn-icon" id="gis-chat-clear"
                title="{{ __('chat.clear') }}" aria-label="{{ __('chat.clear') }}">🗑</button>
        <button type="button" class="gis-chat-btn-icon" id="gis-chat-close"
                title="{{ __('chat.close') }}" aria-label="{{ __('chat.close') }}">✕</button>
    </div>

    <div class="gis-chat-body" id="gis-chat-body" aria-live="polite"></div>

    <div class="gis-chat-footer">
        <button type="button" class="btn btn-sm btn-outline-secondary gis-chat-loc-btn"
                id="gis-chat-loc">{{ __('chat.use_location') }}</button>

        <form class="gis-chat-input-row" id="gis-chat-form">
            <textarea id="gis-chat-input" rows="1" placeholder="{{ __('chat.placeholder') }}"
                      aria-label="{{ __('chat.placeholder') }}" maxlength="1000"></textarea>
            <button type="submit" class="gis-chat-send" id="gis-chat-send"
                    title="{{ __('chat.send') }}" aria-label="{{ __('chat.send') }}">➤</button>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    'use strict';

    var ENDPOINT = @json(route('chat.ask'));
    var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var TXT = {
        thinking: @json(__('chat.thinking')),
        locationOn: @json(__('chat.location_on')),
        locationDenied: @json(__('chat.location_denied')),
        errorNetwork: @json(__('chat.error_network')),
    };

    var fab = document.getElementById('gis-chat-fab');
    var panel = document.getElementById('gis-chat-panel');
    var body = document.getElementById('gis-chat-body');
    var form = document.getElementById('gis-chat-form');
    var input = document.getElementById('gis-chat-input');
    var sendBtn = document.getElementById('gis-chat-send');
    var clearBtn = document.getElementById('gis-chat-clear');
    var closeBtn = document.getElementById('gis-chat-close');
    var locBtn = document.getElementById('gis-chat-loc');

    if (!fab || !panel || !body || !form) {
        return;
    }

    var history = [];
    var coords = null;
    var busy = false;
    var greeted = false;

    function esc(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    /** Render teks aman: escape dulu, lalu ubah baris kosong jadi paragraf. */
    function formatText(text) {
        return esc(text)
            .split(/\n{2,}/)
            .map(function (p) {
                return '<p style="margin:0 0 6px">' + p.replace(/\n/g, '<br>') + '</p>';
            })
            .join('')
            .replace(/<p style="margin:0 0 6px">$/, '');
    }

    function scrollDown() {
        body.scrollTop = body.scrollHeight;
    }

    function addMessage(text, role, sources) {
        var wrap = document.createElement('div');
        wrap.className = 'gis-chat-msg is-' + role;
        wrap.innerHTML = formatText(text);

        if (sources && sources.length) {
            var list = document.createElement('div');
            list.className = 'gis-chat-sources';

            sources.forEach(function (s) {
                var a = document.createElement('a');
                a.className = 'gis-chat-source';
                a.href = s.url;
                a.textContent = s.name;
                a.title = s.category || '';
                list.appendChild(a);
            });

            wrap.appendChild(list);
        }

        body.appendChild(wrap);
        scrollDown();
        return wrap;
    }

    function showTyping() {
        var el = document.createElement('div');
        el.className = 'gis-chat-msg is-bot';
        el.id = 'gis-chat-typing';
        el.innerHTML = '<span class="gis-chat-typing"><span></span><span></span><span></span></span>';
        body.appendChild(el);
        scrollDown();
    }

    function hideTyping() {
        var el = document.getElementById('gis-chat-typing');
        if (el) {
            el.remove();
        }
    }

    function greet() {
        if (greeted) {
            return;
        }
        greeted = true;
        addMessage(@json(__('chat.greeting')), 'bot');
    }

    function setBusy(state) {
        busy = state;
        sendBtn.disabled = state;
        input.disabled = state;
    }

    function autoGrow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 96) + 'px';
    }

    function send(text) {
        if (busy || !text.trim()) {
            return;
        }

        greet();
        addMessage(text, 'user');
        history.push({ role: 'user', content: text });
        input.value = '';
        autoGrow();
        setBusy(true);
        showTyping();

        var payload = { message: text, history: history.slice(-8) };

        if (coords) {
            payload.lat = coords.lat;
            payload.lng = coords.lng;
        }

        fetch(ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF,
            },
            body: JSON.stringify(payload),
        })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('http');
                }
                return res.json();
            })
            .then(function (data) {
                hideTyping();
                addMessage(data.reply, 'bot', data.sources);
                history.push({ role: 'assistant', content: data.reply });
            })
            .catch(function () {
                hideTyping();
                // Pesan error sengaja tidak dimasukkan ke history supaya
                // AI tidak ikut mengulanginya di percakapan berikutnya.
                addMessage(TXT.errorNetwork, 'bot');
            })
            .finally(function () {
                setBusy(false);
                input.focus();
            });
    }

    function toggle(open) {
        var willOpen = typeof open === 'boolean' ? open : !panel.classList.contains('is-open');

        panel.classList.toggle('is-open', willOpen);
        fab.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        document.getElementById('gis-chat-fab-icon').textContent = willOpen ? '✕' : '💬';

        if (willOpen) {
            greet();
            input.focus();
            scrollDown();
        }
    }

    fab.addEventListener('click', function () {
        toggle();
    });

    closeBtn.addEventListener('click', function () {
        toggle(false);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('is-open')) {
            toggle(false);
        }
    });

    clearBtn.addEventListener('click', function () {
        history = [];
        greeted = false;
        body.innerHTML = '';
        greet();
    });

    input.addEventListener('input', autoGrow);

    input.addEventListener('keydown', function (e) {
        // Enter kirim, Shift+Enter baris baru
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send(input.value);
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        send(input.value);
    });

    locBtn.addEventListener('click', function () {
        if (coords) {
            coords = null;
            locBtn.classList.remove('btn-success');
            locBtn.classList.add('btn-outline-secondary');
            locBtn.textContent = @json(__('chat.use_location'));
            return;
        }

        if (!navigator.geolocation) {
            locBtn.textContent = TXT.locationDenied;
            return;
        }

        locBtn.disabled = true;
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                coords = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                locBtn.disabled = false;
                locBtn.textContent = TXT.locationOn;
                locBtn.classList.remove('btn-outline-secondary');
                locBtn.classList.add('btn-success');
                if (!panel.classList.contains('is-open')) {
                    toggle(true);
                }
            },
            function () {
                locBtn.disabled = false;
                locBtn.textContent = TXT.locationDenied;
            },
            { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
        );
    });
})();
</script>
@endpush
