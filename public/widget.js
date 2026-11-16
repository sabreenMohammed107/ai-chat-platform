/*
 * AI chat widget. On the client's website:
 *   <script src="https://PLATFORM/widget.js" data-key="pk_live_..." data-lang="ar" defer></script>
 * Optional: data-color="#4f46e5", data-title="Chat with us", data-position="left".
 * Everything lives in a shadow root, so the site's CSS and the widget's never mix.
 */
(function () {
  'use strict';
  var script = document.currentScript || document.querySelector('script[src*="widget.js"][data-key]');
  if (!script || window.__aiChatWidget) return;
  window.__aiChatWidget = true;

  var key = script.getAttribute('data-key');
  var api = new URL(script.src).origin + '/api/v1/widget/chat';
  var lang = (script.getAttribute('data-lang') || document.documentElement.lang || 'en').slice(0, 2) === 'ar' ? 'ar' : 'en';
  var color = script.getAttribute('data-color') || '#4f46e5';
  var left = script.getAttribute('data-position') === 'left';
  var t = {
    ar: { title: 'تحدث معنا', placeholder: 'اكتب رسالتك...', send: 'إرسال', hello: 'أهلاً! إزاي أقدر أساعدك؟', error: 'حصلت مشكلة، حاول تاني بعد شوية.', reset: 'محادثة جديدة' },
    en: { title: 'Chat with us', placeholder: 'Type your message...', send: 'Send', hello: 'Hi! How can I help you?', error: 'Something went wrong. Please try again shortly.', reset: 'New chat' }
  }[lang];
  var title = script.getAttribute('data-title') || t.title;
  var storeKey = 'aichat_' + key;

  function load() { try { return JSON.parse(localStorage.getItem(storeKey)) || {}; } catch (e) { return {}; } }
  function save(state) { try { localStorage.setItem(storeKey, JSON.stringify(state)); } catch (e) { /* private mode */ } }
  var state = load();
  state.log = state.log || [];

  var host = document.createElement('div');
  host.style.cssText = 'position:fixed;bottom:20px;' + (left ? 'left' : 'right') + ':20px;z-index:2147483000;';
  document.body.appendChild(host);
  var root = host.attachShadow({ mode: 'open' });
  root.innerHTML =
    '<style>' +
    '*{box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}' +
    '.bubble{width:58px;height:58px;border-radius:50%;border:0;background:' + color + ';color:#fff;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center}' +
    '.bubble svg{width:28px;height:28px}' +
    '.panel{display:none;flex-direction:column;width:360px;max-width:calc(100vw - 30px);height:520px;max-height:calc(100vh - 110px);background:#fff;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.25);overflow:hidden;margin-bottom:12px}' +
    '.open .panel{display:flex}' +
    '.head{background:' + color + ';color:#fff;padding:13px 15px;display:flex;align-items:center;gap:8px;font-weight:600}' +
    '.head span{flex:1}.head button{background:transparent;border:0;color:#fff;cursor:pointer;font-size:13px;opacity:.9}' +
    '.log{flex:1;overflow-y:auto;padding:12px;background:#f7f7f9}' +
    '.m{padding:9px 12px;border-radius:12px;margin:6px 0;max-width:85%;white-space:pre-wrap;word-wrap:break-word;font-size:14px;line-height:1.5;color:#1f2937}' +
    '.m.user{background:' + color + ';color:#fff;margin-inline-start:auto}' +
    '.m.assistant{background:#fff;border:1px solid #e5e7eb}' +
    '.m a{color:inherit;text-decoration:underline}' +
    '.typing{opacity:.6}' +
    'form{display:flex;gap:6px;padding:10px;border-top:1px solid #e5e7eb}' +
    'input{flex:1;border:1px solid #d1d5db;border-radius:9px;padding:9px 11px;font-size:14px;outline:none}' +
    'input:focus{border-color:' + color + '}' +
    'form button{background:' + color + ';color:#fff;border:0;border-radius:9px;padding:0 14px;cursor:pointer;font-weight:600}' +
    '</style>' +
    '<div class="wrap" dir="' + (lang === 'ar' ? 'rtl' : 'ltr') + '">' +
    '<div class="panel"><div class="head"><span></span><button type="button" class="reset"></button><button type="button" class="close">✕</button></div>' +
    '<div class="log"></div><form><input maxlength="2000" autocomplete="off"><button type="submit"></button></form></div>' +
    '<button class="bubble" type="button" aria-label="chat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></button>' +
    '</div>';

  var wrap = root.querySelector('.wrap');
  var log = root.querySelector('.log');
  var input = root.querySelector('input');
  var sendBtn = root.querySelector('form button');
  root.querySelector('.head span').textContent = title;
  root.querySelector('.reset').textContent = t.reset;
  input.placeholder = t.placeholder;
  sendBtn.textContent = t.send;
  if (left) wrap.style.textAlign = 'left'; else wrap.style.textAlign = 'right';
  root.querySelector('.panel').style.textAlign = 'start';

  // Text only (never HTML from the answer); http(s) links become links
  function render(el, text) {
    el.textContent = '';
    var parts = String(text).split(/(https?:\/\/[^\s<>"')\]]+)/g);
    parts.forEach(function (part, i) {
      if (i % 2 === 1) {
        var a = document.createElement('a');
        a.href = part; a.textContent = part; a.target = '_blank'; a.rel = 'noopener noreferrer';
        el.appendChild(a);
      } else if (part) {
        el.appendChild(document.createTextNode(part));
      }
    });
  }
  function add(role, text, extra) {
    var div = document.createElement('div');
    div.className = 'm ' + role + (extra ? ' ' + extra : '');
    render(div, text);
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
    return div;
  }
  function draw() {
    log.textContent = '';
    add('assistant', t.hello);
    state.log.forEach(function (m) { add(m.role, m.text); });
  }
  draw();

  root.querySelector('.bubble').onclick = function () {
    wrap.classList.toggle('open');
    if (wrap.classList.contains('open')) input.focus();
  };
  root.querySelector('.close').onclick = function () { wrap.classList.remove('open'); };
  root.querySelector('.reset').onclick = function () { state = { log: [] }; save(state); draw(); };

  var busy = false;
  root.querySelector('form').onsubmit = function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || busy) return;
    busy = true; sendBtn.disabled = true;
    input.value = '';
    add('user', text);
    state.log.push({ role: 'user', text: text });
    var wait = add('assistant', '…', 'typing');
    fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ key: key, message: text, conversation_id: state.conversation_id || null })
    }).then(function (res) {
      return res.json().then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
    }).then(function (r) {
      if (r.status === 404 && state.conversation_id) {
        // The conversation is gone: start a new one next time
        state.conversation_id = null;
      }
      var reply = r.ok ? r.data.reply : t.error;
      if (r.ok) state.conversation_id = r.data.conversation_id;
      wait.classList.remove('typing');
      render(wait, reply);
      if (r.ok) state.log.push({ role: 'assistant', text: reply });
      state.log = state.log.slice(-60);
      save(state);
    }).catch(function () {
      wait.classList.remove('typing');
      render(wait, t.error);
    }).then(function () {
      busy = false; sendBtn.disabled = false;
      log.scrollTop = log.scrollHeight;
    });
  };
})();
