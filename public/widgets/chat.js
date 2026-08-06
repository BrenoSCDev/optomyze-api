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

  // Same set the CRM offers agents, so both ends of a conversation can express
  // the same things.
  var REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

  // Only http(s) and bare www. hosts, so a "javascript:" or "data:" string in
  // a message can never become a clickable link.
  var LINK_PATTERN = /(https?:\/\/[^\s<>"]+|www\.[^\s<>"]+)/gi;

  // Sentence punctuation that follows a URL far more often than it belongs to
  // one: "see https://x.com/docs." should not link the full stop.
  var TRAILING_PUNCTUATION = /[.,;:!?'"]+$/;

  /**
   * Append `text` to `target`, turning URLs into real links along the way.
   *
   * Everything is built as DOM nodes — the message text is written by the
   * other party, so it must never be routed through innerHTML.
   */
  function appendLinkedText(target, text) {
    text = text || '';

    var lastIndex = 0;
    var match;

    LINK_PATTERN.lastIndex = 0;

    while ((match = LINK_PATTERN.exec(text)) !== null) {
      var raw = match[0];
      var trimmed = raw.replace(TRAILING_PUNCTUATION, '');

      // A trailing ")" only belongs to the URL if it opened inside it.
      while (trimmed.charAt(trimmed.length - 1) === ')'
        && trimmed.split(')').length > trimmed.split('(').length) {
        trimmed = trimmed.slice(0, -1);
      }

      if (match.index > lastIndex) {
        target.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
      }

      if (trimmed) {
        var link = el('a', PREFIX + '-link', {
          href: /^www\./i.test(trimmed) ? 'https://' + trimmed : trimmed,
          target: '_blank',
          // noopener/noreferrer keep the opened page away from this one;
          // nofollow because the text is visitor-supplied.
          rel: 'noopener noreferrer nofollow'
        });
        link.textContent = trimmed;
        // Bubbles toggle the action strip on click; a link must just open.
        link.addEventListener('click', function (event) { event.stopPropagation(); });
        target.appendChild(link);
      }

      lastIndex = match.index + trimmed.length;
    }

    if (lastIndex < text.length) {
      target.appendChild(document.createTextNode(text.slice(lastIndex)));
    }
  }

  /**
   * One-line label for a quoted message: its text, or a stand-in for the
   * types that have none.
   */
  function quotedLabel(quoted) {
    if (!quoted) return '';
    if (quoted.text) return quoted.text;

    var labels = {
      image: '📷 Photo',
      video: '🎬 Video',
      audio: '🎤 Audio',
      document: '📄 Document',
      location: '📍 Location',
      contact: '👤 Contact',
      sticker: 'Sticker'
    };

    return labels[quoted.type] || 'Message';
  }

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
      // position:relative anchors the emoji picker; the extra bottom margin
      // leaves room for a reaction badge overhanging the bubble.
      // flex-wrap so the "Seen" marker can claim a full line under the bubble
      // instead of sitting beside it as another flex item.
      '.' + PREFIX + '-msg{display:flex;flex-wrap:wrap;margin-bottom:10px;position:relative;}',
      '.' + PREFIX + '-msg--in{justify-content:flex-start;}',
      '.' + PREFIX + '-msg--out{justify-content:flex-end;}',
      '.' + PREFIX + '-msg--reacted{margin-bottom:18px;}',
      // min-width:0 + overflow-wrap:anywhere: as a flex item the bubble is
      // sized from its min-content width, which one long unbreakable token
      // (a pasted URL) pushes past max-width. Only `anywhere` shrinks that
      // min-content measure — `break-word` alone lets the bubble overflow.
      '.' + PREFIX + '-bubble{position:relative;min-width:0;max-width:78%;padding:9px 13px;border-radius:16px;',
      'font-size:14px;line-height:1.45;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;',
      'box-shadow:0 1px 2px rgba(15,23,42,.08);}',
      '.' + PREFIX + '-msg--in .' + PREFIX + '-bubble{background:#fff;color:#111827;border-bottom-left-radius:5px;}',
      '.' + PREFIX + '-msg--out .' + PREFIX + '-bubble{background:var(--optz-accent);color:#fff;border-bottom-right-radius:5px;}',
      // The accent carries the link on the business's white bubble; on the
      // visitor's accent-filled bubble it would vanish, so there the link
      // inherits the bubble's own foreground and leans on the underline.
      '.' + PREFIX + '-link{color:var(--optz-accent);text-decoration:underline;text-underline-offset:2px;}',
      '.' + PREFIX + '-msg--out .' + PREFIX + '-link{color:inherit;}',
      '.' + PREFIX + '-link:hover{text-decoration-thickness:2px;}',

      '.' + PREFIX + '-time{display:block;margin-top:4px;font-size:10px;opacity:.65;}',
      // Sits under the visitor's own bubble, aligned to its right edge.
      '.' + PREFIX + '-seen{flex:0 0 100%;margin-top:3px;font-size:10px;color:#6b7280;text-align:right;}',
      // A reaction badge overhangs the bubble's bottom edge; give it room so
      // it doesn't land on top of the marker.
      '.' + PREFIX + '-msg--reacted .' + PREFIX + '-seen{margin-top:13px;}',

      // Quoted excerpt, rendered inside the replying bubble.
      '.' + PREFIX + '-quote{margin:-1px 0 6px;padding:4px 8px;border-left:3px solid currentColor;border-radius:6px;',
      'font-size:12px;line-height:1.35;opacity:.75;max-height:52px;overflow:hidden;overflow-wrap:anywhere;',
      'background:rgba(15,23,42,.06);}',
      '.' + PREFIX + '-msg--out .' + PREFIX + '-quote{background:rgba(255,255,255,.18);}',

      // Reaction badge, overhanging the bubble's bottom corner like WhatsApp.
      '.' + PREFIX + '-react{position:absolute;bottom:-11px;display:flex;gap:1px;padding:1px 5px;border-radius:999px;',
      'background:#fff;box-shadow:0 1px 3px rgba(15,23,42,.18);font-size:11px;line-height:1.5;}',
      '.' + PREFIX + '-msg--in .' + PREFIX + '-react{left:8px;}',
      '.' + PREFIX + '-msg--out .' + PREFIX + '-react{right:8px;}',

      // Hover (desktop) or tap (touch, via -msg--active) reveals the actions.
      // Absolutely placed in the gutter the 78% bubble leaves, so they can
      // never wrap the row or displace the "Seen" marker below it.
      '.' + PREFIX + '-acts{position:absolute;top:50%;transform:translateY(-50%);display:flex;gap:2px;',
      'opacity:0;pointer-events:none;transition:opacity .12s;}',
      '.' + PREFIX + '-msg:hover .' + PREFIX + '-acts,',
      '.' + PREFIX + '-msg--active .' + PREFIX + '-acts{opacity:1;pointer-events:auto;}',
      '.' + PREFIX + '-msg--in .' + PREFIX + '-acts{right:0;}',
      '.' + PREFIX + '-msg--out .' + PREFIX + '-acts{left:0;}',
      '.' + PREFIX + '-act{width:22px;height:22px;padding:0;border:0;border-radius:50%;cursor:pointer;',
      'display:flex;align-items:center;justify-content:center;color:#6b7280;background:#fff;',
      'box-shadow:0 1px 2px rgba(15,23,42,.14);font-size:12px;line-height:1;}',
      '.' + PREFIX + '-act:hover{color:#111827;}',
      '.' + PREFIX + '-act svg{width:12px;height:12px;}',

      '.' + PREFIX + '-emojis{position:absolute;bottom:100%;margin-bottom:4px;z-index:5;display:flex;gap:1px;',
      'padding:3px 5px;border-radius:999px;background:#fff;box-shadow:0 2px 8px rgba(15,23,42,.2);}',
      '.' + PREFIX + '-msg--in .' + PREFIX + '-emojis{left:0;}',
      '.' + PREFIX + '-msg--out .' + PREFIX + '-emojis{right:0;}',
      '.' + PREFIX + '-emoji{border:0;background:transparent;cursor:pointer;font-size:15px;line-height:1;',
      'padding:3px;border-radius:50%;}',
      '.' + PREFIX + '-emoji:hover{background:#f1f5f9;}',
      '.' + PREFIX + '-emoji--on{background:#e2e8f0;}',

      // Citation bar; sits on its own line above the input via the composer's
      // flex-wrap, so the row layout underneath is untouched.
      '.' + PREFIX + '-reply-bar{flex:0 0 100%;display:flex;align-items:center;gap:8px;margin-bottom:8px;',
      'padding:6px 10px;border-left:3px solid var(--optz-accent);border-radius:8px;background:#f1f5f9;}',
      '.' + PREFIX + '-reply-text{flex:1;min-width:0;font-size:12px;line-height:1.35;color:#475569;',
      'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.' + PREFIX + '-reply-title{display:block;font-weight:600;color:var(--optz-accent);}',
      '.' + PREFIX + '-reply-cancel{flex:0 0 auto;width:20px;height:20px;padding:0;border:0;border-radius:50%;',
      'background:transparent;color:#64748b;cursor:pointer;font-size:15px;line-height:1;}',
      '.' + PREFIX + '-reply-cancel:hover{background:#e2e8f0;color:#111827;}',

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

      '.' + PREFIX + '-composer{display:flex;flex-wrap:wrap;align-items:flex-end;gap:8px;padding:10px 12px;background:#fff;',
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
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-seen{color:#94a3b8;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-msg--in .' + PREFIX + '-quote{background:rgba(255,255,255,.09);}',
      // The accent is a brand colour and can be far too dark against the dark
      // bubble, so links there get a fixed readable blue instead.
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-msg--in .' + PREFIX + '-link{color:#93c5fd;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-react,',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-act,',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-emojis{background:#1e293b;color:#cbd5e1;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-act:hover{color:#f8fafc;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-emoji:hover{background:#334155;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-emoji--on{background:#475569;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-reply-bar{background:#1e293b;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-reply-text{color:#cbd5e1;}',
      '.' + PREFIX + '-host[data-optz-theme="auto"] .' + PREFIX + '-reply-cancel:hover{background:#334155;color:#f8fafc;}',
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
    // messageId -> { contact: emoji, agent: emoji }
    this.reactions = {};
    this.replyTo = null;
    this.emojiPicker = null;

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

    body.addEventListener('click', function () { self.clearActiveRows(); });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape' || !self.open) return;

      // Escape backs out one layer at a time: the open picker, then a pending
      // reply, then the panel itself.
      if (self.emojiPicker) {
        self.clearActiveRows();
      } else if (self.replyTo) {
        self.clearReplyTo();
      } else {
        self.setOpen(false);
      }
    });
  };

  ChatWidget.prototype.buildComposer = function () {
    var self = this;
    var composer = el('div', PREFIX + '-composer');

    // Full-width first line above the input row (the composer wraps).
    var replyBar = el('div', PREFIX + '-reply-bar');
    replyBar.style.display = 'none';

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

    composer.appendChild(replyBar);
    composer.appendChild(textarea);
    composer.appendChild(send);

    this.textarea = textarea;
    this.sendButton = send;
    this.replyBar = replyBar;

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
      self.applyReactions(data.reactions);
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

    // Needed to place the "Seen" marker. The optimistic echo has no id yet,
    // so it simply cannot be marked until the server confirms it.
    if (message.id) {
      row.setAttribute('data-optz-id', String(message.id));
    }

    if (message.reply_to) {
      var quote = el('div', PREFIX + '-quote');
      quote.textContent = quotedLabel(message.reply_to);
      bubble.appendChild(quote);
    }

    appendLinkedText(bubble, message.text);

    if (message.sent_at) {
      var time = el('span', PREFIX + '-time');
      time.textContent = formatTime(message.sent_at);
      bubble.appendChild(time);
    }

    row.appendChild(bubble);
    row.appendChild(this.buildMessageActions(row, message));
    this.bodyEl.appendChild(row);

    if (message.id && message.id > this.lastMessageId) {
      this.lastMessageId = message.id;
    }

    if (!silent && !mine && !this.open) {
      this.unread += 1;
      this.badge.textContent = String(this.unread);
      this.badge.style.display = '';
    }

    return row;
  };

  /**
   * Reply / react strip beside a bubble.
   *
   * Revealed on hover, and on tap for touch devices where there is no hover —
   * tapping the bubble toggles the strip rather than opening anything, so a
   * mistap costs nothing.
   */
  ChatWidget.prototype.buildMessageActions = function (row, message) {
    var self = this;
    var acts = el('div', PREFIX + '-acts');

    var reply = el('button', PREFIX + '-act', {
      type: 'button',
      'aria-label': this.content.reply_label || 'Reply'
    });
    reply.innerHTML = svgIcon('<polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>');
    reply.addEventListener('click', function (event) {
      event.stopPropagation();
      self.setReplyTo(message);
    });

    var react = el('button', PREFIX + '-act', {
      type: 'button',
      'aria-label': this.content.react_label || 'React'
    });
    react.innerHTML = svgIcon('<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/>'
      + '<line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>');
    react.addEventListener('click', function (event) {
      event.stopPropagation();
      self.toggleEmojiPicker(row, message);
    });

    acts.appendChild(reply);
    acts.appendChild(react);

    row.addEventListener('click', function (event) {
      // Kept off the body's clear-everything handler below.
      event.stopPropagation();
      var wasActive = row.className.indexOf(PREFIX + '-msg--active') !== -1;
      self.clearActiveRows();
      if (!wasActive) row.className += ' ' + PREFIX + '-msg--active';
    });

    return acts;
  };

  ChatWidget.prototype.clearActiveRows = function () {
    var rows = this.bodyEl.querySelectorAll('.' + PREFIX + '-msg--active');
    for (var i = 0; i < rows.length; i++) {
      rows[i].className = rows[i].className.replace(' ' + PREFIX + '-msg--active', '');
    }
    this.closeEmojiPicker();
  };

  ChatWidget.prototype.closeEmojiPicker = function () {
    if (this.emojiPicker && this.emojiPicker.parentNode) {
      this.emojiPicker.parentNode.removeChild(this.emojiPicker);
    }
    this.emojiPicker = null;
  };

  ChatWidget.prototype.toggleEmojiPicker = function (row, message) {
    var self = this;
    var wasOpenHere = this.emojiPicker && this.emojiPicker.parentNode === row;

    this.closeEmojiPicker();

    if (wasOpenHere || !message.id) return;

    var picker = el('div', PREFIX + '-emojis');
    var current = (this.reactions[message.id] || {}).contact;

    REACTIONS.forEach(function (emoji) {
      var button = el('button', PREFIX + '-emoji' + (current === emoji ? ' ' + PREFIX + '-emoji--on' : ''), {
        type: 'button'
      });
      button.textContent = emoji;
      button.addEventListener('click', function (event) {
        event.stopPropagation();
        self.closeEmojiPicker();
        // Tapping the emoji already applied removes it.
        self.react(message.id, current === emoji ? '' : emoji);
      });
      picker.appendChild(button);
    });

    row.appendChild(picker);
    this.emojiPicker = picker;
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

  /**
   * Reconcile every bubble's reaction badge against the server's map.
   *
   * The map covers the whole conversation rather than just the newly polled
   * messages, because a reaction can land on a bubble the widget rendered long
   * ago. Reconciling (rather than applying deltas) also makes removal free: a
   * message missing from the map simply loses its badge.
   */
  ChatWidget.prototype.applyReactions = function (map) {
    this.reactions = map || {};

    var rows = this.bodyEl.querySelectorAll('.' + PREFIX + '-msg[data-optz-id]');

    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      var id = row.getAttribute('data-optz-id');
      var bubble = row.querySelector('.' + PREFIX + '-bubble');
      var existing = row.querySelector('.' + PREFIX + '-react');
      var emojis = [];
      var entry = this.reactions[id];

      for (var key in entry) {
        if (Object.prototype.hasOwnProperty.call(entry, key) && entry[key]) emojis.push(entry[key]);
      }

      if (existing) existing.parentNode.removeChild(existing);
      row.className = row.className.replace(' ' + PREFIX + '-msg--reacted', '');

      if (!emojis.length || !bubble) continue;

      var badge = el('span', PREFIX + '-react');
      badge.textContent = emojis.join('');
      bubble.appendChild(badge);
      row.className += ' ' + PREFIX + '-msg--reacted';
    }
  };

  /** Quote the given message in the next thing the visitor sends. */
  ChatWidget.prototype.setReplyTo = function (message) {
    if (!message.id) return;

    this.clearActiveRows();
    this.replyTo = message;
    this.renderReplyBar();

    if (this.textarea) this.textarea.focus();
  };

  ChatWidget.prototype.clearReplyTo = function () {
    this.replyTo = null;
    this.renderReplyBar();
  };

  ChatWidget.prototype.renderReplyBar = function () {
    if (!this.replyBar) return;

    if (!this.replyTo) {
      this.replyBar.style.display = 'none';
      this.replyBar.innerHTML = '';
      return;
    }

    var self = this;
    var mine = this.replyTo.direction === 'incoming';

    this.replyBar.innerHTML = '';
    this.replyBar.style.display = '';

    var text = el('div', PREFIX + '-reply-text');
    var title = el('span', PREFIX + '-reply-title');
    title.textContent = mine
      ? (this.content.reply_to_you || 'You')
      : (this.content.header_title || 'Support');
    text.appendChild(title);
    text.appendChild(document.createTextNode(quotedLabel(this.replyTo)));

    var cancel = el('button', PREFIX + '-reply-cancel', {
      type: 'button',
      'aria-label': this.content.cancel_reply_label || 'Cancel reply'
    });
    cancel.innerHTML = '&times;';
    cancel.addEventListener('click', function () { self.clearReplyTo(); });

    this.replyBar.appendChild(text);
    this.replyBar.appendChild(cancel);
  };

  /** Apply or clear the visitor's reaction, optimistically. */
  ChatWidget.prototype.react = function (messageId, emoji) {
    var self = this;
    var previous = JSON.parse(JSON.stringify(this.reactions || {}));
    var next = JSON.parse(JSON.stringify(this.reactions || {}));

    if (!next[messageId]) next[messageId] = {};
    if (emoji === '') {
      delete next[messageId].contact;
    } else {
      next[messageId].contact = emoji;
    }

    this.applyReactions(next);

    this.api('/messages/' + messageId + '/reaction', {
      method: 'POST',
      body: { emoji: emoji }
    }).then(function (result) {
      if (!result.ok) self.applyReactions(previous);
    });
  };

  ChatWidget.prototype.sendMessage = function (text) {
    var self = this;
    var replyTo = this.replyTo;

    // Optimistic echo keeps the conversation feeling instant.
    var row = this.appendMessage({
      direction: 'incoming',
      text: text,
      reply_to: replyTo ? { type: replyTo.type, text: replyTo.text } : null,
      sent_at: new Date().toISOString()
    }, true);
    this.clearReplyTo();
    this.scrollToBottom();

    this.api('/messages', {
      method: 'POST',
      body: { text: text, reply_to_message_id: replyTo ? replyTo.id : null }
    }).then(function (result) {
      if (result.ok) {
        var data = result.body.data || {};
        if (data.id && data.id > self.lastMessageId) self.lastMessageId = data.id;
        // The poll only asks for messages *after* this one, so it will never
        // send it back — stamping the confirmed id here is what makes the
        // visitor's own bubble reactable, quotable and markable as seen.
        if (data.id && row) row.setAttribute('data-optz-id', String(data.id));
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

      var data = result.body.data || {};
      var messages = data.messages || [];

      // The visitor's own messages were already echoed optimistically.
      var incoming = messages.filter(function (message) { return message.direction === 'outgoing'; });

      messages.forEach(function (message) {
        if (message.id > self.lastMessageId) self.lastMessageId = message.id;
      });

      incoming.forEach(function (message) { self.appendMessage(message); });

      self.applyReactions(data.reactions);
      self.applySeenMarker(data.seen_until_id);

      if (incoming.length) self.scrollToBottom();
    });
  };

  /**
   * Show "Seen" under the visitor's newest message that someone has read.
   *
   * A single marker on the last read message, rather than one per bubble:
   * everything above it is read by definition, and a column of ticks would be
   * noise in a panel this small.
   */
  ChatWidget.prototype.applySeenMarker = function (seenUntilId) {
    if (!seenUntilId) return;
    if (this.seenUntilId === seenUntilId) return;

    this.seenUntilId = seenUntilId;

    if (this.seenEl && this.seenEl.parentNode) {
      this.seenEl.parentNode.removeChild(this.seenEl);
    }

    // The visitor's own bubbles carry their id once the server has confirmed
    // them; the optimistic echo has none and so is never marked.
    var rows = this.bodyEl.querySelectorAll('.' + PREFIX + '-msg--out[data-optz-id]');
    var target = null;

    for (var i = 0; i < rows.length; i++) {
      var id = parseInt(rows[i].getAttribute('data-optz-id'), 10);
      if (id && id <= seenUntilId) target = rows[i];
    }

    if (!target) return;

    this.seenEl = el('div', PREFIX + '-seen');
    this.seenEl.textContent = this.content.seen_label || 'Seen';
    target.appendChild(this.seenEl);
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
