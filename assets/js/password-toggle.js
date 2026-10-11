/* =====================================================================
   password-toggle.js
   Adds a show/hide toggle to every input[type="password"] on the page.
   Injects its own CSS so it works with any layout.
   ===================================================================== */
(function () {
  'use strict';

  var ICON_SHOW = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  var ICON_HIDE = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a19.77 19.77 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a19.77 19.77 0 0 1-3.19 4.06M1 1l22 22"/><path d="M9.65 9.65a3 3 0 0 0 4.24 4.24"/></svg>';

  function injectStyles() {
    if (document.getElementById('password-toggle-styles')) return;
    var style = document.createElement('style');
    style.id = 'password-toggle-styles';
    style.textContent =
      '.password-toggle-wrap{position:relative;display:block;width:100%}' +
      '.password-toggle-wrap input[type="password"],' +
      '.password-toggle-wrap input[type="text"]{padding-right:44px !important}' +
      '.password-toggle-btn{position:absolute;top:50%;right:8px;transform:translateY(-50%);' +
        'background:transparent;border:0;padding:6px;margin:0;cursor:pointer;color:#6b7280;' +
        'display:inline-flex;align-items:center;justify-content:center;border-radius:6px;' +
        'transition:color .15s,background .15s;line-height:1}' +
      '.password-toggle-btn:hover{color:#0b5ed7;background:rgba(11,94,215,.08)}' +
      '.password-toggle-btn:focus{outline:none}' +
      '.password-toggle-btn svg{display:block}';
    document.head.appendChild(style);
  }

  function attachToggle(input) {
    if (input.dataset.pwdToggle === '1') return;
    // Skip inputs that explicitly opt out
    if (input.hasAttribute('data-no-toggle')) return;
    input.dataset.pwdToggle = '1';

    // Wrap the input so the button can be positioned relative to it
    var wrapper = document.createElement('div');
    wrapper.className = 'password-toggle-wrap';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'password-toggle-btn';
    btn.setAttribute('aria-label', 'Show password');
    btn.setAttribute('title', 'Show password');
    btn.tabIndex = -1;
    btn.innerHTML = ICON_SHOW;
    wrapper.appendChild(btn);

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.innerHTML = showing ? ICON_SHOW : ICON_HIDE;
      btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      btn.setAttribute('title', showing ? 'Show password' : 'Hide password');
      input.focus();
      // Move cursor to end so continued typing appends
      try {
        var len = input.value.length;
        input.setSelectionRange(len, len);
      } catch (err) { /* some input types do not support selection ranges */ }
    });
  }

  function init() {
    injectStyles();
    var inputs = document.querySelectorAll('input[type="password"]');
    for (var i = 0; i < inputs.length; i++) {
      attachToggle(inputs[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose a re-scan for dynamically inserted fields
  window.PasswordToggleRefresh = init;
})();