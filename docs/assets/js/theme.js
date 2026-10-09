'use strict';

// Two-state theme control: light or dark, saved on click and restored by
// the inline script in the document head before first paint. With no saved
// choice the system preference decides; this script keeps the root
// attribute, the toggle button and the theme-color meta tags in sync.

(function () {
  var button = document.getElementById('theme-toggle');
  if (!button) {
    return;
  }

  var root = document.documentElement;
  var colors = { light: '#F8F7F2', dark: '#0F1B2E' };

  function read() {
    try {
      var value = localStorage.getItem('theme');
      return value === 'light' || value === 'dark' ? value : null;
    } catch (error) {
      return null;
    }
  }

  var state = read();
  if (!state) {
    state = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function apply() {
    root.dataset.theme = state;
    button.setAttribute('aria-pressed', state === 'dark' ? 'true' : 'false');
    button.setAttribute('aria-label', state === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
    var metas = document.querySelectorAll('meta[name="theme-color"]');
    for (var i = 0; i < metas.length; i++) {
      metas[i].setAttribute('content', colors[state]);
    }
  }

  button.addEventListener('click', function () {
    state = state === 'dark' ? 'light' : 'dark';
    try {
      localStorage.setItem('theme', state);
    } catch (error) {
      // Private browsing: the choice still applies for this page view.
    }
    apply();
  });

  apply();
})();
