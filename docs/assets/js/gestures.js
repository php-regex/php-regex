'use strict';

// The gestures outside the playground that say a reader is on their way to
// the project: a link to the repository of the library followed. The
// analytics hear a gesture by its name and the details the privacy page
// lists, never an address: the helper of the page says it, or nothing
// outside production.

(function () {
  function track(name, params) {
    if (typeof window.phpregexTrack === 'function') {
      window.phpregexTrack(name, params);
    }
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest('a[href^="https://github.com/php-regex/"]');
    if (!link) {
      return;
    }
    var path = link.getAttribute('href').replace('https://github.com/php-regex/', '');
    var kind = /^php-regex\/?$/.test(path) ? 'repository' : (/^php-regex\/issues/.test(path) ? 'issues' : 'other');
    track('github_clicked', { kind: kind });
  });
})();
