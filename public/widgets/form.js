/*!
 * Optomyze CRM — Website Form Widget loader
 *
 * Usage on any website:
 *   <script src="https://your-crm-domain.com/widgets/form.js"></script>
 *   <div id="crm-form-widget" data-widget-id="form_xxxxxxxx"></div>
 *
 * Every element carrying data-widget-id is rendered independently, so a page
 * may host several forms. No dependencies, no globals beyond one guard flag.
 *
 * The widget renders in one of three modes, chosen in the CRM:
 *   inline      — the form sits open in the page flow
 *   collapsible — a launcher button in the page flow opens/closes the form
 *   floating    — a launcher bubble pinned to a screen corner opens a panel
 */
(function () {
  'use strict';

  if (window.__optomyzeFormWidgetLoaded) return;
  window.__optomyzeFormWidgetLoaded = true;

  // The API lives on the same host this script was served from.
  var scriptEl = document.currentScript || (function () {
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
      if ((scripts[i].src || '').indexOf('/widgets/form.js') !== -1) return scripts[i];
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

  var STYLE_ID = 'optomyze-form-widget-styles';
  var BRAND_URL = 'https://www.optomyze.io';
  var PREFIX = 'optz-fw';

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

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;

    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = [
      '.' + PREFIX + '{--optz-accent:#7C3AED;--optz-radius:12px;box-sizing:border-box;width:100%;max-width:520px;',
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#111827;',
      'background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;',
      'box-shadow:0 10px 30px -12px rgba(15,23,42,.18);line-height:1.5;}',
      '.' + PREFIX + ' *{box-sizing:border-box;}',
      '.' + PREFIX + '__head{display:flex;align-items:center;gap:12px;margin-bottom:16px;}',
      '.' + PREFIX + '__icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;',
      'background:color-mix(in srgb,var(--optz-accent) 12%,transparent);color:var(--optz-accent);flex:0 0 auto;}',
      '.' + PREFIX + '__icon svg{width:20px;height:20px;}',
      '.' + PREFIX + '__title{margin:0;font-size:18px;font-weight:700;letter-spacing:-.01em;}',
      '.' + PREFIX + '__desc{margin:4px 0 0;font-size:13px;color:#6b7280;}',
      '.' + PREFIX + '__field{margin-bottom:14px;}',
      '.' + PREFIX + '__label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#374151;}',
      '.' + PREFIX + '__req{color:#ef4444;margin-left:2px;}',
      '.' + PREFIX + '__help{display:block;font-size:12px;color:#6b7280;margin-top:4px;}',
      '.' + PREFIX + '__input,.' + PREFIX + '__select,.' + PREFIX + '__textarea{width:100%;padding:10px 12px;font-size:14px;',
      'font-family:inherit;color:#111827;background:#fff;border:1px solid #d1d5db;border-radius:var(--optz-radius);',
      'outline:none;transition:border-color .15s,box-shadow .15s;}',
      '.' + PREFIX + '__textarea{min-height:96px;resize:vertical;}',
      '.' + PREFIX + '__input:focus,.' + PREFIX + '__select:focus,.' + PREFIX + '__textarea:focus{border-color:var(--optz-accent);',
      'box-shadow:0 0 0 3px color-mix(in srgb,var(--optz-accent) 18%,transparent);}',
      '.' + PREFIX + '__input--error,.' + PREFIX + '__select--error,.' + PREFIX + '__textarea--error{border-color:#ef4444;}',
      '.' + PREFIX + '__error{display:block;font-size:12px;color:#ef4444;margin-top:5px;}',
      '.' + PREFIX + '__options{display:flex;flex-direction:column;gap:8px;}',
      '.' + PREFIX + '__option{display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;}',
      '.' + PREFIX + '__option input{width:16px;height:16px;accent-color:var(--optz-accent);margin:0;}',
      '.' + PREFIX + '__check{display:flex;align-items:flex-start;gap:8px;font-size:14px;cursor:pointer;}',
      '.' + PREFIX + '__check input{width:16px;height:16px;accent-color:var(--optz-accent);margin:2px 0 0;}',
      '.' + PREFIX + '__submit{width:100%;margin-top:4px;padding:12px 16px;font-size:14px;font-weight:600;font-family:inherit;',
      'color:#fff;background:var(--optz-accent);border:0;border-radius:var(--optz-radius);cursor:pointer;',
      'transition:filter .15s,opacity .15s;}',
      '.' + PREFIX + '__submit:hover{filter:brightness(1.07);}',
      '.' + PREFIX + '__submit:disabled{opacity:.6;cursor:not-allowed;}',
      '.' + PREFIX + '__alert{padding:10px 12px;border-radius:var(--optz-radius);font-size:13px;margin-bottom:14px;',
      'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;}',
      '.' + PREFIX + '__success{text-align:center;padding:12px 4px;}',
      '.' + PREFIX + '__success-icon{width:48px;height:48px;margin:0 auto 12px;border-radius:50%;display:flex;',
      'align-items:center;justify-content:center;background:color-mix(in srgb,var(--optz-accent) 12%,transparent);color:var(--optz-accent);}',
      '.' + PREFIX + '__success-icon svg{width:24px;height:24px;}',
      '.' + PREFIX + '__success-text{font-size:14px;color:#374151;margin:0;}',
      '.' + PREFIX + '__hp{position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;',
      'overflow:hidden!important;opacity:0!important;}',
      // Launcher + expandable panel
      '.' + PREFIX + '-launcher{display:inline-flex;align-items:center;gap:8px;padding:12px 18px;border:0;cursor:pointer;',
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:14px;font-weight:600;',
      'color:#fff;background:var(--optz-accent);border-radius:999px;box-shadow:0 8px 20px -8px rgba(15,23,42,.45);',
      'transition:filter .15s,transform .15s;}',
      '.' + PREFIX + '-launcher:hover{filter:brightness(1.07);transform:translateY(-1px);}',
      '.' + PREFIX + '-launcher svg{width:18px;height:18px;flex:0 0 auto;}',
      '.' + PREFIX + '-launcher--bubble{width:56px;height:56px;padding:0;justify-content:center;border-radius:50%;}',
      '.' + PREFIX + '-launcher--bubble svg{width:24px;height:24px;}',
      '.' + PREFIX + '-panel{display:none;}',
      '.' + PREFIX + '-panel--open{display:block;animation:optz-fw-in .18s ease-out;}',
      '@keyframes optz-fw-in{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}',
      '.' + PREFIX + '-host--collapsible .' + PREFIX + '-panel{margin-top:12px;}',
      '.' + PREFIX + '-host--floating{position:fixed;z-index:2147483000;display:flex;flex-direction:column;align-items:flex-end;gap:12px;}',
      '.' + PREFIX + '-host--floating[data-optz-position="bottom-left"]{align-items:flex-start;left:20px;right:auto;}',
      '.' + PREFIX + '-host--floating[data-optz-position="bottom-right"]{right:20px;left:auto;}',
      '.' + PREFIX + '-host--floating{bottom:20px;}',
      '.' + PREFIX + '-host--floating .' + PREFIX + '{width:380px;max-width:calc(100vw - 40px);max-height:min(78vh,660px);overflow-y:auto;}',
      '.' + PREFIX + '-host--floating .' + PREFIX + '-panel{order:-1;}',
      '.' + PREFIX + '__close{position:absolute;top:10px;right:10px;width:28px;height:28px;display:flex;align-items:center;',
      'justify-content:center;border:0;border-radius:8px;background:transparent;color:#6b7280;cursor:pointer;transition:background .15s,color .15s;}',
      '.' + PREFIX + '__close:hover{background:rgba(107,114,128,.12);color:#111827;}',
      '.' + PREFIX + '__close svg{width:16px;height:16px;}',
      '.' + PREFIX + '--closable{position:relative;}',
      '.' + PREFIX + '--closable .' + PREFIX + '__head{padding-right:32px;}',
      '.' + PREFIX + '__brand{display:flex;align-items:center;justify-content:center;gap:5px;margin-top:14px;',
      'font-size:12px;line-height:1;color:#9ca3af;text-decoration:none;transition:color .15s;}',
      '.' + PREFIX + '__brand:hover{color:#6b7280;}',
      // 3.876:1 logo — width follows the 17px cap height.
      '.' + PREFIX + '__brand-logo{width:66px;height:17px;flex:0 0 auto;background:url("' + API_BASE + '/widgets/optomyze-logo.png") center/contain no-repeat;}',
      '@media (max-width:480px){.' + PREFIX + '{padding:18px;border-radius:14px;}',
      '.' + PREFIX + '-host--floating{left:16px;right:16px;bottom:16px;align-items:stretch;}',
      '.' + PREFIX + '-host--floating .' + PREFIX + '{width:100%;max-width:100%;}',
      '.' + PREFIX + '-host--floating .' + PREFIX + '-launcher{align-self:flex-end;}',
      '.' + PREFIX + '-host--floating[data-optz-position="bottom-left"] .' + PREFIX + '-launcher{align-self:flex-start;}}',
      '@media (prefers-color-scheme:dark){',
      '.' + PREFIX + '[data-optz-theme="auto"]{background:#0f172a;border-color:#1e293b;color:#e2e8f0;',
      'box-shadow:0 10px 30px -12px rgba(0,0,0,.6);}',
      '.' + PREFIX + '[data-optz-theme="auto"] .' + PREFIX + '__label{color:#cbd5e1;}',
      '.' + PREFIX + '[data-optz-theme="auto"] .' + PREFIX + '__desc,',
      '.' + PREFIX + '[data-optz-theme="auto"] .' + PREFIX + '__help,',
      '.' + PREFIX + '[data-optz-theme="auto"] .' + PREFIX + '__success-text{color:#94a3b8;}',
      '.' + PREFIX + '[data-optz-theme="auto"] .' + PREFIX + '__close:hover{background:rgba(226,232,240,.12);color:#e2e8f0;}',
      '.' + PREFIX + '[data-optz-theme="auto"] .' + PREFIX + '__brand{color:#64748b;}',
      '.' + PREFIX + '[data-optz-theme="auto"] .' + PREFIX + '__brand-logo{background-image:url("' + API_BASE + '/widgets/optomyze-logo-dark.png");}',
      '.' + PREFIX + '[data-optz-theme="auto"] .' + PREFIX + '__input,',
      '.' + PREFIX + '[data-optz-theme="auto"] .' + PREFIX + '__select,',
      '.' + PREFIX + '[data-optz-theme="auto"] .' + PREFIX + '__textarea{background:#1e293b;border-color:#334155;color:#e2e8f0;}',
      '}'
    ].join('');

    document.head.appendChild(style);
  }

  /** "Powered by Optomyze" watermark linking back to optomyze.io. */
  function brandBar() {
    var link = el('a', PREFIX + '__brand', {
      href: BRAND_URL,
      target: '_blank',
      rel: 'noopener noreferrer'
    });

    var label = document.createElement('span');
    label.textContent = 'Powered by';

    // The logo carries the wordmark, so no separate "Optomyze" text.
    var logo = el('span', PREFIX + '__brand-logo');
    logo.setAttribute('role', 'img');
    logo.setAttribute('aria-label', 'Optomyze');

    link.appendChild(label);
    link.appendChild(logo);

    return link;
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

  function svgIcon(paths) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
  }

  function buildField(field) {
    var wrapper = el('div', PREFIX + '__field');
    var inputId = PREFIX + '-' + Math.random().toString(36).slice(2, 9);
    var control;

    if (field.type !== 'checkbox') {
      var label = el('label', PREFIX + '__label', { 'for': inputId });
      label.textContent = field.label;
      if (field.required) {
        var star = el('span', PREFIX + '__req');
        star.textContent = '*';
        label.appendChild(star);
      }
      wrapper.appendChild(label);
    }

    switch (field.type) {
      case 'textarea':
        control = el('textarea', PREFIX + '__textarea', {
          id: inputId, name: field.key, placeholder: field.placeholder || '', rows: 4
        });
        break;

      case 'select':
        control = el('select', PREFIX + '__select', { id: inputId, name: field.key });
        var placeholderOption = el('option', null, { value: '' });
        placeholderOption.textContent = field.placeholder || '—';
        control.appendChild(placeholderOption);
        (field.options || []).forEach(function (option) {
          var opt = el('option', null, { value: option.value });
          opt.textContent = option.label;
          control.appendChild(opt);
        });
        break;

      case 'radio':
        control = el('div', PREFIX + '__options', { id: inputId, role: 'radiogroup' });
        (field.options || []).forEach(function (option) {
          var optionLabel = el('label', PREFIX + '__option');
          var radio = el('input', null, { type: 'radio', name: field.key, value: option.value });
          var text = document.createElement('span');
          text.textContent = option.label;
          optionLabel.appendChild(radio);
          optionLabel.appendChild(text);
          control.appendChild(optionLabel);
        });
        break;

      case 'checkbox':
        control = el('label', PREFIX + '__check');
        var box = el('input', null, { type: 'checkbox', name: field.key, id: inputId });
        var boxText = document.createElement('span');
        boxText.textContent = field.label + (field.required ? ' *' : '');
        control.appendChild(box);
        control.appendChild(boxText);
        break;

      case 'number':
        control = el('input', PREFIX + '__input', {
          id: inputId, name: field.key, type: 'number', placeholder: field.placeholder || '',
          min: field.validation && field.validation.min != null ? field.validation.min : null,
          max: field.validation && field.validation.max != null ? field.validation.max : null
        });
        break;

      case 'date':
        control = el('input', PREFIX + '__input', { id: inputId, name: field.key, type: 'date' });
        break;

      default:
        control = el('input', PREFIX + '__input', {
          id: inputId,
          name: field.key,
          type: field.key === 'email' ? 'email' : (field.key === 'phone' ? 'tel' : 'text'),
          placeholder: field.placeholder || ''
        });
        break;
    }

    wrapper.appendChild(control);

    if (field.help_text) {
      var help = el('span', PREFIX + '__help');
      help.textContent = field.help_text;
      wrapper.appendChild(help);
    }

    var error = el('span', PREFIX + '__error');
    error.style.display = 'none';
    wrapper.appendChild(error);

    return { wrapper: wrapper, control: control, error: error, field: field };
  }

  function readValue(entry) {
    var field = entry.field;

    if (field.type === 'checkbox') {
      return entry.control.querySelector('input').checked;
    }

    if (field.type === 'radio') {
      var checked = entry.control.querySelector('input:checked');
      return checked ? checked.value : '';
    }

    return entry.control.value;
  }

  function showError(entry, message) {
    entry.error.textContent = message;
    entry.error.style.display = 'block';

    if (entry.control.classList && entry.field.type !== 'radio' && entry.field.type !== 'checkbox') {
      entry.control.classList.add(entry.control.className.split(' ')[0] + '--error');
    }
  }

  function clearError(entry) {
    entry.error.textContent = '';
    entry.error.style.display = 'none';
    if (entry.control.classList) {
      entry.control.classList.remove(PREFIX + '__input--error', PREFIX + '__select--error', PREFIX + '__textarea--error');
    }
  }

  /**
   * Builds the form card. `options.onClose` adds the X button used by the
   * expandable modes; the card replaces itself with the success state after a
   * successful submission.
   */
  function createCard(mount, config, options) {
    options = options || {};

    var theme = config.theme || {};
    var content = config.content || {};

    var root = el('div', PREFIX + (options.onClose ? ' ' + PREFIX + '--closable' : ''), {
      'data-optz-theme': mount.getAttribute('data-theme') || 'light'
    });
    root.style.setProperty('--optz-accent', theme.primary_color || '#7C3AED');

    if (options.onClose) {
      var close = el('button', PREFIX + '__close', { type: 'button', 'aria-label': 'Close form' });
      close.innerHTML = svgIcon('<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>');
      close.addEventListener('click', options.onClose);
      root.appendChild(close);
    }

    // Header
    if (content.title || theme.icon) {
      var head = el('div', PREFIX + '__head');

      if (theme.icon && ICONS[theme.icon]) {
        var icon = el('div', PREFIX + '__icon');
        icon.innerHTML = svgIcon(ICONS[theme.icon]);
        head.appendChild(icon);
      }

      var headText = el('div');
      if (content.title) {
        var title = el('h3', PREFIX + '__title');
        title.textContent = content.title;
        headText.appendChild(title);
      }
      if (content.description) {
        var desc = el('p', PREFIX + '__desc');
        desc.textContent = content.description;
        headText.appendChild(desc);
      }
      head.appendChild(headText);
      root.appendChild(head);
    }

    var form = el('form', null, { novalidate: 'novalidate' });
    var alert = el('div', PREFIX + '__alert');
    alert.style.display = 'none';
    form.appendChild(alert);

    var entries = (config.fields || []).map(function (field) {
      var entry = buildField(field);
      form.appendChild(entry.wrapper);
      return entry;
    });

    // Honeypot: invisible to humans, irresistible to bots.
    var honeypot = null;
    if (!config.spam || config.spam.honeypot !== false) {
      honeypot = el('input', PREFIX + '__hp', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' });
      honeypot.setAttribute('aria-hidden', 'true');
      form.appendChild(honeypot);
    }

    var submit = el('button', PREFIX + '__submit', { type: 'submit' });
    submit.textContent = content.submit_text || 'Send';
    form.appendChild(submit);

    root.appendChild(form);
    root.appendChild(brandBar());

    var openedAt = Date.now();

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      alert.style.display = 'none';
      entries.forEach(clearError);

      // Client-side required check keeps the round trip out of the common case.
      var hasError = false;
      var data = {};

      entries.forEach(function (entry) {
        var value = readValue(entry);
        var empty = value === '' || value === false || value == null;

        if (entry.field.required && empty) {
          showError(entry, 'This field is required.');
          hasError = true;
          return;
        }

        if (!empty) data[entry.field.key] = value;
      });

      if (hasError) return;

      submit.disabled = true;
      var originalText = submit.textContent;
      submit.textContent = '…';

      var payload = {
        data: data,
        elapsed_seconds: Math.round((Date.now() - openedAt) / 1000)
      };
      if (honeypot && honeypot.value) payload.hp = honeypot.value;

      fetch(API_BASE + '/api/public/widgets/' + encodeURIComponent(config.public_id) + '/submit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload)
      })
        .then(function (response) {
          return response.json().then(function (body) {
            return { ok: response.ok, status: response.status, body: body };
          });
        })
        .then(function (result) {
          if (result.ok) {
            showSuccess(root, config, result.body.message || content.success_message, options.onClose);
            return;
          }

          if (result.status === 422 && result.body.errors) {
            entries.forEach(function (entry) {
              if (result.body.errors[entry.field.key]) {
                showError(entry, result.body.errors[entry.field.key]);
              }
            });
          }

          alert.textContent = result.body.message || 'Something went wrong. Please try again.';
          alert.style.display = 'block';
        })
        .catch(function () {
          alert.textContent = 'We could not reach the server. Please try again.';
          alert.style.display = 'block';
        })
        .then(function () {
          submit.disabled = false;
          submit.textContent = originalText;
        });
    });

    return root;
  }

  /** Swap a submitted card for the confirmation state, in place. */
  function showSuccess(root, config, message, onClose) {
    root.innerHTML = '';

    if (onClose) {
      var close = el('button', PREFIX + '__close', { type: 'button', 'aria-label': 'Close form' });
      close.innerHTML = svgIcon('<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>');
      close.addEventListener('click', onClose);
      root.appendChild(close);
    }

    var box = el('div', PREFIX + '__success');
    var icon = el('div', PREFIX + '__success-icon');
    icon.innerHTML = svgIcon('<polyline points="20 6 9 17 4 12"/>');

    var text = el('p', PREFIX + '__success-text');
    text.textContent = message || 'Thank you!';

    box.appendChild(icon);
    box.appendChild(text);
    root.appendChild(box);
    root.appendChild(brandBar());
  }

  function buildLauncher(config, bubble) {
    var theme = config.theme || {};

    var launcher = el('button', PREFIX + '-launcher' + (bubble ? ' ' + PREFIX + '-launcher--bubble' : ''), {
      type: 'button',
      'aria-expanded': 'false'
    });
    launcher.style.setProperty('--optz-accent', theme.primary_color || '#7C3AED');

    if (theme.icon && ICONS[theme.icon]) {
      var iconWrap = document.createElement('span');
      iconWrap.innerHTML = svgIcon(ICONS[theme.icon]);
      iconWrap.style.display = 'inline-flex';
      launcher.appendChild(iconWrap);
    }

    var label = (theme.launcher_text || 'Contact us');

    if (bubble) {
      launcher.setAttribute('aria-label', label);
    } else {
      var text = document.createElement('span');
      text.textContent = label;
      launcher.appendChild(text);
    }

    return launcher;
  }

  /**
   * Renders the widget in the mode chosen in the CRM. `collapsible` and
   * `floating` start closed behind a launcher the visitor can open and close;
   * `inline` is always open.
   */
  function render(mount, config) {
    var theme = config.theme || {};
    var display = theme.display || 'inline';

    mount.innerHTML = '';

    if (display === 'inline') {
      mount.appendChild(createCard(mount, config, {}));
      return;
    }

    var floating = display === 'floating';

    var host = el('div', PREFIX + '-host--' + (floating ? 'floating' : 'collapsible'));
    if (floating) {
      host.setAttribute('data-optz-position', theme.position === 'bottom-left' ? 'bottom-left' : 'bottom-right');
    }

    var panel = el('div', PREFIX + '-panel');
    var launcher = buildLauncher(config, floating);

    var open = false;

    var setOpen = function (next) {
      open = next;
      panel.className = PREFIX + '-panel' + (open ? ' ' + PREFIX + '-panel--open' : '');
      launcher.setAttribute('aria-expanded', open ? 'true' : 'false');

      // A bubble that stays put while the panel is open would just cover it.
      launcher.style.display = open && floating ? 'none' : '';

      if (open && !panel.firstChild) {
        panel.appendChild(createCard(mount, config, { onClose: function () { setOpen(false); } }));
      }
    };

    launcher.addEventListener('click', function () { setOpen(!open); });

    // Escape closes an open panel, as visitors expect from any overlay.
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && open) setOpen(false);
    });

    // In-flow: trigger first, form expands beneath it. Floating: the panel
    // sits above the bubble (CSS order), so append it first.
    if (floating) {
      host.appendChild(panel);
      host.appendChild(launcher);
    } else {
      host.appendChild(launcher);
      host.appendChild(panel);
    }

    // A floating launcher belongs to the viewport, not to the embed slot.
    (floating ? document.body : mount).appendChild(host);

    setOpen(false);
  }

  function renderMessage(mount, message) {
    mount.innerHTML = '';
    var root = el('div', PREFIX);
    var text = el('p', PREFIX + '__success-text');
    text.textContent = message;
    root.appendChild(text);
    mount.appendChild(root);
  }

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
        render(mount, body.data);
      })
      .catch(function () {
        // Never leave a broken shell on a customer's page.
        renderMessage(mount, 'This form is currently unavailable.');
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

  // Let single-page sites mount forms injected after load.
  window.OptomyzeFormWidget = { mount: mountWidget, refresh: boot };
})();
