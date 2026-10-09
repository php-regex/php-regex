'use strict';

// Tri-state theme control: system -> light -> dark -> system. The inline
// script in the document head resolves the theme before first paint; this
// script only keeps the root attribute, the toggle button and the
// theme-color meta tags in sync afterwards.

(function () {
  var button = document.getElementById('theme-toggle');
  if (!button) {
    return;
  }

  var root = document.documentElement;
  var media = window.matchMedia('(prefers-color-scheme: dark)');
  var next = { system: 'light', light: 'dark', dark: 'system' };
  var colors = { light: '#F8F7F2', dark: '#0F1B2E' };

  function read() {
    try {
      var value = localStorage.getItem('theme');
      return value === 'light' || value === 'dark' ? value : 'system';
    } catch (error) {
      return 'system';
    }
  }

  var state = read();

  function resolved() {
    return state === 'system' ? (media.matches ? 'dark' : 'light') : state;
  }

  function syncMetas() {
    var metas = document.querySelectorAll('meta[name="theme-color"]');
    for (var i = 0; i < metas.length; i++) {
      var dark = metas[i].getAttribute('media') === '(prefers-color-scheme: dark)';
      var effective = state === 'system' ? (dark ? 'dark' : 'light') : state;
      metas[i].setAttribute('content', colors[effective]);
    }
  }

  function apply() {
    root.dataset.theme = resolved();
    button.setAttribute('aria-pressed', state === 'system' ? 'false' : 'true');
    button.setAttribute('aria-label', state === 'light'
      ? 'Switch to dark theme'
      : (state === 'dark' ? 'Switch to light theme' : 'Theme: follow system'));
    syncMetas();
  }

  button.addEventListener('click', function () {
    state = next[state];
    try {
      localStorage.setItem('theme', state);
    } catch (error) {
      // Private browsing: the choice still applies for this page view.
    }
    apply();
  });

  function onSystemChange() {
    if (state === 'system') {
      apply();
    }
  }

  if (typeof media.addEventListener === 'function') {
    media.addEventListener('change', onSystemChange);
  } else if (typeof media.addListener === 'function') {
    media.addListener(onSystemChange);
  }

  apply();
})();
