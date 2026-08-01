/*!
 * Optomyze CRM — Website Chat Widget loader
 *
 * Usage on any website:
 *   <script src="https://your-crm-domain.com/widgets/chat.js"></script>
 *   <div id="crm-chat-widget" data-widget-id="chat_xxxxxxxx"></div>
 *
 * Renders a floating launcher that expands into a live chat wired straight
 * into the CRM's Global Inbox. No dependencies. The visitor token lives in
 * localStorage so a refresh (or a return visit) resumes the same thread.
 */
(function () {
  'use strict';

  if (window.__optomyzeChatWidgetLoaded) return;
  window.__optomyzeChatWidgetLoaded = true;

  var scriptEl = document.currentScript || (function () {
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
      if ((scripts[i].src || '').indexOf('/widgets/chat.js') !== -1) return scripts[i];
    }
    return null;
  })();

  var API_BASE = (function () {
    try {
      return new URL(scriptEl.src, window.location.href).origin;
    } catch (e) {
      return '';
    }
  })();

  var STYLE_ID = 'optomyze-chat-widget-styles';
  var BRAND_URL = 'https://www.optomyze.io';
  var PREFIX = 'optz-cw';
  var POLL_INTERVAL_OPEN = 4000;
  var POLL_INTERVAL_IDLE = 15000;

  var ICONS = {
    'message-circle': '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
    'mail': '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
    'user': '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'phone': '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    'calendar': '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'star': '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    'rocket': '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91 0z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/>',
    'heart': '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
    'sparkles': '<path d="M12 3l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4L12 3z"/><path d="M19 15l.9 2.6L22.5 18l-2.6.9L19 21l-.9-2.1L15.5 18l2.6-.4L19 15z"/>',
    'briefcase': '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>'
  };

  function svgIcon(paths) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
  }

  function el(tag, className, attrs) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (attrs) {
      for (var key in attrs) {
        if (Object.prototype.hasOwnProperty.call(attrs, key) && attrs[key] != null) {
          node.setAttribute(key, attrs[key]);
        }
      }
    }
    return node;
  }

  // localStorage can throw (Safari private mode, cookie blocking) — the chat
  // must still work, just without persistence across reloads.
  var store = {
    get: function (key) {
      try { return window.localStorage.getItem(key); } catch (e) { return null; }
    },
    set: function (key, value) {
      try { window.localStorage.setItem(key, value); } catch (e) { /* ignore */ }
    }
  };

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;

    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = [
      '.' + PREFIX + '-host{--optz-accent:#7C3AED;position:fixed;bottom:20px;z-index:2147483000;',
      'display:flex;flex-direction:column;gap:12px;align-items:flex-end;',
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}',
      '.' + PREFIX + '-host[data-optz-position="bottom-right"]{right:20px;}',
      '.' + PREFIX + '-host[data-optz-position="bottom-left"]{left:20px;align-items:flex-start;}',
      '.' + PREFIX + '-host *{box-sizing:border-box;}',

      '.' + PREFIX + '-launcher{width:60px;height:60px;border:0;border-radius:50%;cursor:pointer;',
      'display:flex;align-items:center;justify-content:center;color:#fff;background:var(--optz-accent);',
      'box-shadow:0 10px 25px -8px rgba(15,23,42,.5);transition:transform .18s,filter .18s;order:2;}',
      '.' + PREFIX + '-launcher:hover{transform:scale(1.05);filter:brightness(1.07);}',
      '.' + PREFIX + '-launcher svg{width:26px;height:26px;}',
      '.' + PREFIX + '-badge{position:absolute;top:-2px;right:-2px;min-width:20px;height:20px;padding:0 5px;',
      'border-radius:999px;background:#ef4444;color:#fff;font-size:11px;font-weight:700;display:flex;',
      'align-items:center;justify-content:center;box-shadow:0 0 0 2px #fff;}',
      '.' + PREFIX + '-launcher-wrap{position:relative;order:2;}',

      '.' + PREFIX + '-panel{order:1;width:380px;max-width:calc(100vw - 40px);height:min(72vh,620px);',
      'display:none;flex-direction:column;overflow:hidden;background:#fff;border:1px solid #e5e7eb;',
      'border-radius:16px;box-shadow:0 24px 60px -20px rgba(15,23,42,.45);}',
      '.' + PREFIX + '-panel--open{display:flex;animation:optz-cw-in .2s ease-out;}',
      '@keyframes optz-cw-in{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}',

      '.' + PREFIX + '-head{display:flex;align-items:center;gap:10px;padding:14px 16px;color:#fff;',
      'background:var(--optz-accent);flex:0 0 auto;}',
      '.' + PREFIX + '-avatar{width:36px;height:36px;border-radius:50%;overflow:hidden;flex:0 0 auto;',
      'background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;}',
      '.' + PREFIX + '-avatar img{width:100%;height:100%;object-fit:cover;}',
      '.' + PREFIX + '-avatar svg{width:18px;height:18px;}',
      '.' + PREFIX + '-head-text{min-width:0;flex:1;}',
      '.' + PREFIX + '-title{margin:0;font-size:15px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.' + PREFIX + '-subtitle{margin:1px 0 0;font-size:12px;opacity:.85;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.' + PREFIX + '-status{display:inline-flex;align-items:center;gap:5px;}',
      '.' + PREFIX + '-dot{width:7px;height:7px;border-radius:50%;background:#4ade80;flex:0 0 auto;}',
      '.' + PREFIX + '-head-actions{display:flex;gap:2px;flex:0 0 auto;}',
      '.' + PREFIX + '-head-btn{width:30px;height:30px;border:0;background:transparent;color:#fff;opacity:.85;',
      'border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;}',
      '.' + PREFIX + '-head-btn:hover{opacity:1;background:rgba(255,255,255,.16);}',
      '.' + PREFIX + '-head-btn svg{width:16px;height:16px;}',

      '.' + PREFIX + '-body{flex:1;overflow-y:auto;padding:16px;background:#f8fafc;}',
      '.' + PREFIX + '-msg{display:flex;margin-bottom:10px;}',
      '.' + PREFIX + '-msg--in{justify-content:flex-start;}',
      '.' + PREFIX + '-msg--out{justify-content:flex-end;}',
      '.' + PREFIX + '-bubble{max-width:78%;padding:9px 13px;border-radius:16px;font-size:14px;line-height:1.45;',
      'white-space:pre-wrap;word-break:break-word;box-shadow:0 1px 2px rgba(15,23,42,.08);}',
      '.' + PREFIX + '-msg--in .' + PREFIX + '-bubble{background:#fff;color:#111827;border-bottom-left-radius:5px;}',
      '.' + PREFIX + '-msg--out .' + PREFIX + '-bubble{background:var(--optz-accent);color:#fff;border-bottom-right-radius:5px;}',
      '.' + PREFIX + '-time{display:block;margin-top:4px;font-size:10px;opacity:.65;}',

      '.' + PREFIX + '-form{padding:16px;background:#fff;flex:1;overflow-y:auto;}',
      '.' + PREFIX + '-intro{margin:0 0 12px;font-size:13px;color:#6b7280;}',
      '.' + PREFIX + '-label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;}',
      '.' + PREFIX + '-input{width:100%;padding:10px 12px;font-size:14px;font-family:inherit;color:#111827;',
      'background:#fff;border:1px solid #d1d5db;border-radius:10px;outline:none;margin-bottom:12px;}',
      '.' + PREFIX + '-input:focus{border-color:var(--optz-accent);box-shadow:0 0 0 3px rgba(124,58,237,.15);}',
      '.' + PREFIX + '-input--error{border-color:#ef4444;}',
      '.' + PREFIX + '-error{display:block;margin:-8px 0 10px;font-size:11px;color:#ef4444;}',
      '.' + PREFIX + '-btn{width:100%;padding:11px 16px;border:0;border-radius:10px;font-size:14px;font-weight:600;',
      'font-family:inherit;color:#fff;background:var(--optz-accent);cursor:pointer;transition:filter .15s;}',
      '.' + PREFIX + '-btn:hover{filter:brightness(1.07);}',
      '.' + PREFIX + '-btn:disabled{opacity:.6;cursor:not-allowed;}',

      '.' + PREFIX + '-composer{display:flex;align-items:flex-end;gap:8px;padding:10px 12px;background:#fff;',
      'border-top:1px solid #e5e7eb;flex:0 0 auto;}',
      '.' + PREFIX + '-textarea{flex:1;max-height:110px;min-height:40px;padding:10px 12px;font-size:14px;',
      'font-family:inherit;color:#111827;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;',
      'outline:none;resize:none;line-height:1.4;}',
      '.' + PREFIX + '-textarea:focus{border-color:var(--optz-accent);background:#fff;}',
      '.' + PREFIX + '-send{width:40px;height:40px;flex:0 0 auto;border:0;border-radius:50%;cursor:pointer;',
      'display:flex;align-items:center;justify-content:center;color:#fff;background:var(--optz-accent);}',
      '.' + PREFIX + '-send:disabled{opacity:.45;cursor:not-allowed;}',
      '.' + PREFIX + '-send svg{width:16px;height:16px;}',
      '.' + PREFIX + '-foot{display:flex;align-items:center;justify-content:center;gap:5px;padding:0 12px 10px;',
      'font-size:12px;line-height:1;color:#9ca3af;text-decoration:none;background:#fff;flex:0 0 auto;transition:color .15s;}',
      '.' + PREFIX + '-foot:hover{color:#6b7280;}',
      // 3.876:1 logo — width follows the 17px cap height.
      '.' + PREFIX + '-foot-logo{width:66px;height:17px;flex:0 0 auto;background:url("' + API_BASE + '/widgets/optomyze-logo.png") center/contain no-repeat;}',

      '@media (max-width:480px){',
      '.' + PREFIX + '-host{left:12px;right:12px;bottom:12px;align-items:flex-end;}',
      '.' + PREFIX + '-host[data-optz-position="bottom-left"]{align-items:flex-start;}',
      '.' + PREFIX + '-panel{width:100%;max-width:100%;height:min(80vh,560px);}}',

      '@media (prefers-color-scheme:dark){',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-panel{background:#0f172a;border-color:#1e293b;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-body{background:#0b1220;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-msg--in .' + PREFIX + '-bubble{background:#1e293b;color:#e2e8f0;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-composer,',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-form,',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-foot{background:#0f172a;border-color:#1e293b;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-textarea,',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-input{background:#1e293b;border-color:#334155;color:#e2e8f0;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-label{color:#cbd5e1;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-foot{background:#0f172a;color:#64748b;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-foot-logo{background-image:url("' + API_BASE + '/widgets/optomyze-logo-dark.png");}',
      '}'
    ].join('');

    document.head.appendChild(style);
  }

  /** "Powered by Optomyze" watermark linking back to optomyze.io. */
  function brandBar() {
    var link = el('a', PREFIX + '-foot', {
      href: BRAND_URL,
      target: '_blank',
      rel: 'noopener noreferrer'
    });

    var label = document.createElement('span');
    label.textContent = 'Powered by';

    // The logo carries the wordmark, so no separate "Optomyze" text.
    var logo = el('span', PREFIX + '-foot-logo');
    logo.setAttribute('role', 'img');
    logo.setAttribute('aria-label', 'Optomyze');

    link.appendChild(label);
    link.appendChild(logo);

    return link;
  }

  function formatTime(iso) {
    if (!iso) return '';
    try {
      return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch (e) {
      return '';
    }
  }

  /** One chat widget instance. */
  function ChatWidget(mount, config) {
    this.mount = mount;
    this.config = config;
    this.theme = config.theme || {};
    this.content = config.content || {};
    this.identification = config.identification || { mode: 'none', fields: {} };
    this.behavior = config.behavior || {};

    this.storageKey = 'optz_chat_' + config.public_id;
    this.token = store.get(this.storageKey + '_token');
    this.open = false;
    this.lastMessageId = 0;
    this.unread = 0;
    this.pollTimer = null;
    this.identified = false;
    this.hasConversation = false;

    this.build();
    this.startSession();
  }

  ChatWidget.prototype.api = function (path, options) {
    options = options || {};

    var headers = { 'Accept': 'application/json' };
    if (options.body) headers['Content-Type'] = 'application/json';
    if (this.token) headers['X-Visitor-Token'] = this.token;

    return fetch(API_BASE + '/api/public/widgets/' + encodeURIComponent(this.config.public_id) + '/chat' + path, {
      method: options.method || 'GET',
      headers: headers,
      body: options.body ? JSON.stringify(options.body) : undefined
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (body) {
        return { ok: response.ok, status: response.status, body: body };
      });
    });
  };

  ChatWidget.prototype.build = function () {
    var self = this;

    var host = el('div', PREFIX + '-host', {
      'data-optz-position': this.theme.position === 'bottom-left' ? 'bottom-left' : 'bottom-right',
      'data-optz-theme': this.mount.getAttribute('data-theme') || 'light'
    });
    host.style.setProperty('--optz-accent', this.theme.primary_color || '#7C3AED');

    // ── Panel ──────────────────────────────────────────────────────────
    var panel = el('div', PREFIX + '-panel');

    var head = el('div', PREFIX + '-head');
    var avatar = el('div', PREFIX + '-avatar');
    var branding = this.config.branding || {};

    if (branding.agent_avatar_url || branding.company_logo_url) {
      var img = el('img', null, { src: branding.agent_avatar_url || branding.company_logo_url, alt: '' });
      avatar.appendChild(img);
    } else {
      avatar.innerHTML = svgIcon(ICONS[this.theme.icon] || ICONS['message-circle']);
    }

    var headText = el('div', PREFIX + '-head-text');
    var title = el('p', PREFIX + '-title');
    title.textContent = this.content.header_title || 'Chat with us';

    var subtitle = el('p', PREFIX + '-subtitle');
    var status = el('span', PREFIX + '-status');
    status.appendChild(el('span', PREFIX + '-dot'));
    var statusText = document.createElement('span');
    statusText.textContent = this.content.online_text || 'Online';
    status.appendChild(statusText);

    if (this.content.header_subtitle) {
      subtitle.textContent = this.content.header_subtitle + ' · ';
    }
    subtitle.appendChild(status);

    headText.appendChild(title);
    headText.appendChild(subtitle);

    var actions = el('div', PREFIX + '-head-actions');
    var minimize = el('button', PREFIX + '-head-btn', { type: 'button', 'aria-label': 'Minimize chat' });
    minimize.innerHTML = svgIcon('<line x1="5" y1="12" x2="19" y2="12"/>');
    minimize.addEventListener('click', function () { self.setOpen(false); });
    actions.appendChild(minimize);

    head.appendChild(avatar);
    head.appendChild(headText);
    head.appendChild(actions);

    var body = el('div', PREFIX + '-body');
    var composer = this.buildComposer();
    var form = this.buildIdentificationForm();

    panel.appendChild(head);
    panel.appendChild(form);
    panel.appendChild(body);
    panel.appendChild(composer);
    // Sits below the composer, so it stays visible on the pre-chat form too.
    panel.appendChild(brandBar());

    // ── Launcher ───────────────────────────────────────────────────────
    var launcherWrap = el('div', PREFIX + '-launcher-wrap');
    var launcher = el('button', PREFIX + '-launcher', {
      type: 'button',
      'aria-label': this.theme.launcher_text || 'Chat with us',
      'aria-expanded': 'false'
    });
    launcher.innerHTML = svgIcon(ICONS[this.theme.icon] || ICONS['message-circle']);
    launcher.addEventListener('click', function () { self.setOpen(!self.open); });

    var badge = el('span', PREFIX + '-badge');
    badge.style.display = 'none';

    launcherWrap.appendChild(launcher);
    launcherWrap.appendChild(badge);

    host.appendChild(panel);
    host.appendChild(launcherWrap);
    document.body.appendChild(host);

    this.host = host;
    this.panel = panel;
    this.bodyEl = body;
    this.composer = composer;
    this.formEl = form;
    this.launcher = launcher;
    this.badge = badge;

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && self.open) self.setOpen(false);
    });
  };

  ChatWidget.prototype.buildComposer = function () {
    var self = this;
    var composer = el('div', PREFIX + '-composer');

    var textarea = el('textarea', PREFIX + '-textarea', {
      rows: 1,
      placeholder: this.content.input_placeholder || 'Write a message…'
    });

    var send = el('button', PREFIX + '-send', { type: 'button', 'aria-label': 'Send message' });
    send.innerHTML = svgIcon('<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>');

    var submit = function () {
      var text = textarea.value.trim();
      if (!text) return;
      textarea.value = '';
      textarea.style.height = 'auto';
      self.sendMessage(text);
    };

    send.addEventListener('click', submit);

    textarea.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submit();
      }
    });

    textarea.addEventListener('input', function () {
      textarea.style.height = 'auto';
      textarea.style.height = Math.min(textarea.scrollHeight, 110) + 'px';
    });

    composer.appendChild(textarea);
    composer.appendChild(send);

    this.textarea = textarea;
    this.sendButton = send;

    return composer;
  };

  /** Pre-chat form; only built when the widget asks visitors to identify. */
  ChatWidget.prototype.buildIdentificationForm = function () {
    var self = this;
    var form = el('form', PREFIX + '-form');
    var mode = this.identification.mode || 'none';
    var fields = this.identification.fields || {};

    if (mode === 'none') {
      form.style.display = 'none';
      return form;
    }

    if (this.identification.intro) {
      var intro = el('p', PREFIX + '-intro');
      intro.textContent = this.identification.intro;
      form.appendChild(intro);
    }

    var order = ['name', 'email', 'phone'];
    var inputs = {};

    order.forEach(function (key) {
      var state = fields[key] || 'hidden';
      if (state === 'hidden') return;

      var label = el('label', PREFIX + '-label');
      label.textContent = key.charAt(0).toUpperCase() + key.slice(1) + (state === 'required' ? ' *' : '');

      var input = el('input', PREFIX + '-input', {
        type: key === 'email' ? 'email' : (key === 'phone' ? 'tel' : 'text'),
        name: key
      });

      var error = el('span', PREFIX + '-error');
      error.style.display = 'none';

      form.appendChild(label);
      form.appendChild(input);
      form.appendChild(error);

      inputs[key] = { input: input, error: error, required: state === 'required' };
    });

    var submit = el('button', PREFIX + '-btn', { type: 'submit' });
    submit.textContent = this.identification.submit_text || 'Start chat';
    form.appendChild(submit);

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var payload = {};
      var valid = true;

      Object.keys(inputs).forEach(function (key) {
        var entry = inputs[key];
        var value = entry.input.value.trim();

        entry.error.style.display = 'none';
        entry.input.classList.remove(PREFIX + '-input--error');

        if (entry.required && !value) {
          entry.error.textContent = 'This field is required.';
          entry.error.style.display = 'block';
          entry.input.classList.add(PREFIX + '-input--error');
          valid = false;
          return;
        }

        if (value) payload[key] = value;
      });

      if (!valid) return;

      submit.disabled = true;

      self.api('/identify', { method: 'POST', body: payload }).then(function (result) {
        submit.disabled = false;

        if (!result.ok) {
          var errors = result.body.errors || {};
          Object.keys(inputs).forEach(function (key) {
            if (errors[key]) {
              inputs[key].error.textContent = errors[key][0];
              inputs[key].error.style.display = 'block';
              inputs[key].input.classList.add(PREFIX + '-input--error');
            }
          });
          return;
        }

        self.identified = true;
        self.showChat();
      });
    });

    this.identificationInputs = inputs;

    return form;
  };

  ChatWidget.prototype.startSession = function () {
    var self = this;

    this.api('/session', {
      method: 'POST',
      body: { visitor_token: this.token || null, page_url: window.location.href }
    }).then(function (result) {
      if (!result.ok) return;

      var data = result.body.data || {};

      self.token = data.visitor_token;
      store.set(self.storageKey + '_token', self.token);

      self.identified = !!data.identified;
      self.hasConversation = !!data.has_conversation;

      self.renderMessages(data.messages || [], true);
      self.updateView();

      // Reopen where the visitor left off, when the widget is set to remember.
      if (self.behavior.remember_state !== false && store.get(self.storageKey + '_open') === '1') {
        self.setOpen(true);
      } else if (self.behavior.auto_open_delay > 0) {
        setTimeout(function () { self.setOpen(true); }, self.behavior.auto_open_delay * 1000);
      }

      self.schedulePoll();
    });
  };

  /** Identification form vs. message list + composer. */
  ChatWidget.prototype.updateView = function () {
    var needsIdentification = (this.identification.mode || 'none') !== 'none'
      && !this.identified
      && !this.hasConversation;

    if (needsIdentification) {
      this.formEl.style.display = '';
      this.bodyEl.style.display = 'none';
      this.composer.style.display = 'none';
    } else {
      this.showChat();
    }
  };

  ChatWidget.prototype.showChat = function () {
    this.formEl.style.display = 'none';
    this.bodyEl.style.display = '';
    this.composer.style.display = '';

    if (!this.bodyEl.firstChild && this.content.welcome_message) {
      this.appendMessage({ direction: 'outgoing', text: this.content.welcome_message, sent_at: null }, true);
    }

    this.scrollToBottom();
  };

  ChatWidget.prototype.appendMessage = function (message, silent) {
    // Outgoing (from the CRM) shows on the left as the business; the visitor's
    // own messages sit on the right.
    var mine = message.direction === 'incoming';
    var row = el('div', PREFIX + '-msg ' + PREFIX + '-msg--' + (mine ? 'out' : 'in'));
    var bubble = el('div', PREFIX + '-bubble');

    bubble.appendChild(document.createTextNode(message.text || ''));

    if (message.sent_at) {
      var time = el('span', PREFIX + '-time');
      time.textContent = formatTime(message.sent_at);
      bubble.appendChild(time);
    }

    row.appendChild(bubble);
    this.bodyEl.appendChild(row);

    if (message.id && message.id > this.lastMessageId) {
      this.lastMessageId = message.id;
    }

    if (!silent && !mine && !this.open) {
      this.unread += 1;
      this.badge.textContent = String(this.unread);
      this.badge.style.display = '';
    }
  };

  ChatWidget.prototype.renderMessages = function (messages, initial) {
    var self = this;

    if (initial && messages.length) {
      this.bodyEl.innerHTML = '';
      this.hasConversation = true;
    }

    messages.forEach(function (message) {
      self.appendMessage(message, initial);
    });

    this.scrollToBottom();
  };

  ChatWidget.prototype.sendMessage = function (text) {
    var self = this;

    // Optimistic echo keeps the conversation feeling instant.
    this.appendMessage({ direction: 'incoming', text: text, sent_at: new Date().toISOString() }, true);
    this.scrollToBottom();

    this.api('/messages', { method: 'POST', body: { text: text } }).then(function (result) {
      if (result.ok) {
        var data = result.body.data || {};
        if (data.id && data.id > self.lastMessageId) self.lastMessageId = data.id;
        self.hasConversation = true;
        self.poll();
        return;
      }

      if (result.body.message === 'identification_required') {
        self.identified = false;
        self.hasConversation = false;
        self.updateView();
        return;
      }

      self.appendMessage({
        direction: 'outgoing',
        text: result.body.message || 'Your message could not be delivered. Please try again.',
        sent_at: new Date().toISOString()
      }, true);
      self.scrollToBottom();
    });
  };

  ChatWidget.prototype.poll = function () {
    var self = this;

    if (!this.token) return;

    this.api('/messages?after_id=' + this.lastMessageId).then(function (result) {
      if (!result.ok) return;

      var messages = (result.body.data || {}).messages || [];

      // The visitor's own messages were already echoed optimistically.
      var incoming = messages.filter(function (message) { return message.direction === 'outgoing'; });

      messages.forEach(function (message) {
        if (message.id > self.lastMessageId) self.lastMessageId = message.id;
      });

      incoming.forEach(function (message) { self.appendMessage(message); });

      if (incoming.length) self.scrollToBottom();
    });
  };

  ChatWidget.prototype.schedulePoll = function () {
    var self = this;

    if (this.pollTimer) clearInterval(this.pollTimer);

    this.pollTimer = setInterval(function () {
      if (document.hidden) return;
      self.poll();
    }, this.open ? POLL_INTERVAL_OPEN : POLL_INTERVAL_IDLE);
  };

  ChatWidget.prototype.setOpen = function (next) {
    this.open = next;
    this.panel.className = PREFIX + '-panel' + (next ? ' ' + PREFIX + '-panel--open' : '');
    this.launcher.setAttribute('aria-expanded', next ? 'true' : 'false');

    if (this.behavior.remember_state !== false) {
      store.set(this.storageKey + '_open', next ? '1' : '0');
    }

    if (next) {
      this.unread = 0;
      this.badge.style.display = 'none';
      this.scrollToBottom();
      this.poll();

      if (this.textarea && this.composer.style.display !== 'none') {
        try { this.textarea.focus(); } catch (e) { /* ignore */ }
      }
    }

    this.schedulePoll();
  };

  ChatWidget.prototype.scrollToBottom = function () {
    var body = this.bodyEl;
    if (body) setTimeout(function () { body.scrollTop = body.scrollHeight; }, 30);
  };

  function mountWidget(mount) {
    if (mount.getAttribute('data-optz-mounted') === '1') return;
    mount.setAttribute('data-optz-mounted', '1');

    var widgetId = mount.getAttribute('data-widget-id');

    if (!widgetId) return;

    fetch(API_BASE + '/api/public/widgets/' + encodeURIComponent(widgetId), {
      headers: { 'Accept': 'application/json' }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('unavailable');
        return response.json();
      })
      .then(function (body) {
        injectStyles();
        // eslint-disable-next-line no-new
        new ChatWidget(mount, body.data);
      })
      .catch(function () {
        // A chat that can't load stays invisible rather than showing a broken
        // bubble on the customer's site.
      });
  }

  function boot() {
    var mounts = document.querySelectorAll('[data-widget-id]');
    for (var i = 0; i < mounts.length; i++) mountWidget(mounts[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  window.OptomyzeChatWidget = { mount: mountWidget, refresh: boot };
})();
