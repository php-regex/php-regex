'use strict';

// Documentation search dialog, opened by the header toggle or Ctrl/Cmd+K.
// The engine script and the JSON index load lazily on the first open, never
// at page load; the status region reports progress while they arrive.

(function () {
  var toggle = document.getElementById('search-toggle');
  var dialog = document.getElementById('search-dialog');
  var input = document.getElementById('search-input');
  var results = document.getElementById('search-results');
  var status = document.getElementById('search-status');
  if (!toggle || !dialog || !input || !results || !status) {
    return;
  }

  var selfSrc = document.currentScript ? document.currentScript.src : '';
  var mark = selfSrc.indexOf('?');
  var cacheBust = mark === -1 ? '' : selfSrc.slice(mark);

  var engine = null;
  var loading = null;
  var failed = false;
  var dialogOpen = false;
  var active = -1;
  var lastFocused = null;
  var optionSeq = 0;

  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('aria-controls', 'search-results');
  input.setAttribute('aria-autocomplete', 'list');
  results.setAttribute('role', 'listbox');
  results.setAttribute('aria-label', 'Search results');

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = src;
      script.onload = function () { resolve(); };
      script.onerror = function () { reject(new Error('engine script failed')); };
      document.head.appendChild(script);
    });
  }

  function ensureEngine() {
    if (failed) {
      return Promise.resolve(null);
    }
    if (engine) {
      return Promise.resolve(engine);
    }
    if (!loading) {
      loading = loadScript('/assets/js/minisearch.min.js' + cacheBust)
        .then(function () {
          return fetch('/search-index.json');
        })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('index HTTP ' + response.status);
          }
          return response.json();
        })
        .then(function (documents) {
          var MiniSearchCtor = window.MiniSearch;
          if (!MiniSearchCtor) {
            throw new Error('engine global missing');
          }
          // Page titles arrive HTML-escaped from the index generator; decode
          // once here so results render (and are searched) as plain text.
          var decoder = document.createElement('textarea');
          documents.forEach(function (doc) {
            decoder.innerHTML = doc.title;
            doc.title = decoder.value;
            decoder.innerHTML = doc.excerpt;
            doc.excerpt = decoder.value;
          });
          var instance = new MiniSearchCtor({
            idField: 'url',
            fields: ['title', 'excerpt'],
            storeFields: ['url', 'title'],
            boost: { title: 3 }
          });
          instance.addAll(documents);
          engine = instance;
          loading = null;
          return engine;
        })
        .catch(function () {
          loading = null;
          failed = true;
          return null;
        });
    }
    return loading;
  }

  function announce(message) {
    status.textContent = message;
  }

  function render(hits, term) {
    results.textContent = '';
    active = -1;
    input.removeAttribute('aria-activedescendant');

    if (term && hits.length === 0) {
      var empty = document.createElement('li');
      empty.className = 'no-results';
      empty.textContent = 'No results for "' + term + '".';
      var browse = document.createElement('a');
      browse.href = '/docs/';
      browse.textContent = 'Browse the documentation map';
      empty.appendChild(browse);
      results.appendChild(empty);
    }

    hits.forEach(function (hit) {
      var item = document.createElement('li');
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', 'false');
      optionSeq += 1;
      item.id = 'search-option-' + optionSeq;
      var link = document.createElement('a');
      link.href = hit.url;
      var title = document.createElement('strong');
      title.textContent = hit.title;
      var path = document.createElement('small');
      path.className = 'result-url';
      // Show the site path, not the absolute URL with whatever host
      // served the index (dev serve and production differ).
      path.textContent = hit.url.replace(/^https?:\/\/[^/]+/, '');
      link.appendChild(title);
      link.appendChild(path);
      item.appendChild(link);
      results.appendChild(item);
    });

    input.setAttribute('aria-expanded', hits.length > 0 ? 'true' : 'false');
  }

  // The query lives in the URL (?q=…), so a search is a shareable address:
  // opening any page with the parameter opens the dialog pre-filled, and
  // the address bar tracks the term while it is typed. The sync is
  // debounced — replaceState is rate-limited and keystrokes are not — and
  // closing the dialog clears the parameter so a reload does not reopen it.
  var syncTimer = 0;

  function syncUrl(term) {
    window.clearTimeout(syncTimer);
    syncTimer = window.setTimeout(function () {
      var url = new URL(window.location.href);
      if (term) {
        url.searchParams.set('q', term);
      } else {
        url.searchParams.delete('q');
      }
      window.history.replaceState(window.history.state, '', url);
    }, 400);
  }

  function clearUrl() {
    window.clearTimeout(syncTimer);
    var url = new URL(window.location.href);
    if (url.searchParams.has('q')) {
      url.searchParams.delete('q');
      window.history.replaceState(window.history.state, '', url);
    }
  }

  function run() {
    var term = input.value.trim();
    syncUrl(term);
    if (!engine) {
      render([], '');
      announce(failed ? 'Search is unavailable.' : 'Loading the search index…');
      return;
    }
    if (!term) {
      render([], '');
      announce('');
      return;
    }
    var hits = engine.search(term, { limit: 12, prefix: true });
    render(hits, term);
    if (hits.length === 0) {
      announce('No pages match "' + term + '"');
    } else if (hits.length === 1) {
      announce('1 page matches "' + term + '"');
    } else {
      announce(hits.length + ' pages match "' + term + '"');
    }
  }

  function listOptions() {
    return Array.prototype.slice.call(results.querySelectorAll('li[role="option"]'));
  }

  function setActive(options, index) {
    if (options.length === 0) {
      return;
    }
    if (index >= options.length) {
      index = 0;
    }
    if (index < 0) {
      index = options.length - 1;
    }
    active = index;
    options.forEach(function (item, i) {
      var on = i === index;
      item.classList.toggle('active', on);
      item.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    input.setAttribute('aria-activedescendant', options[index].id);
    options[index].scrollIntoView({ block: 'nearest', behavior: 'auto' });
  }

  input.addEventListener('input', run);

  input.addEventListener('keydown', function (event) {
    var options = listOptions();
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive(options, active + 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive(options, active <= 0 ? options.length - 1 : active - 1);
    } else if (event.key === 'Home') {
      event.preventDefault();
      setActive(options, 0);
    } else if (event.key === 'End') {
      event.preventDefault();
      setActive(options, options.length - 1);
    } else if (event.key === 'Enter') {
      if (options.length === 0) {
        return;
      }
      event.preventDefault();
      var link = options[active === -1 ? 0 : active].querySelector('a');
      if (link) {
        window.location.assign(link.href);
      }
    }
  });

  function trap(event) {
    var focusables = dialog.querySelectorAll('a[href], input:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])');
    if (focusables.length === 0) {
      event.preventDefault();
      return;
    }
    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    var current = document.activeElement;
    if (event.shiftKey && current === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && current === last) {
      event.preventDefault();
      first.focus();
    } else if (!dialog.contains(current)) {
      event.preventDefault();
      first.focus();
    }
  }

  function openDialog() {
    if (dialogOpen) {
      return;
    }
    dialogOpen = true;
    lastFocused = document.activeElement && document.activeElement !== document.body
      ? document.activeElement
      : toggle;
    dialog.hidden = false;
    dialog.classList.add('open');
    toggle.setAttribute('aria-expanded', 'true');
    failed = false;
    ensureEngine().then(function (ready) {
      if (ready) {
        if (input.value.trim()) {
          run();
        }
      } else {
        // The engine or the index failed to load: say so now rather than
        // waiting for the first keystroke to surface it.
        status.textContent = 'Search is unavailable.';
      }
    });
    input.focus();
  }

  function closeDialog() {
    if (!dialogOpen) {
      return;
    }
    clearUrl();
    dialogOpen = false;
    dialog.hidden = true;
    dialog.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
    if (lastFocused && typeof lastFocused.focus === 'function') {
      lastFocused.focus();
    } else {
      toggle.focus();
    }
  }

  toggle.addEventListener('click', openDialog);

  // Other pages may carry their own way in (the 404 page has a search
  // button); any control that opts in opens the same dialog, and gets its
  // focus back when the dialog closes.
  document.querySelectorAll('[data-search-open]').forEach(function (el) {
    el.addEventListener('click', openDialog);
  });

  document.addEventListener('keydown', function (event) {
    if ((event.metaKey || event.ctrlKey) && (event.key === 'k' || event.key === 'K')) {
      if (dialogOpen && dialog.contains(document.activeElement)) {
        return;
      }
      event.preventDefault();
      if (dialogOpen) {
        closeDialog();
        return;
      }
      openDialog();
    }
  });

  // The other docs-standard way in: a bare "/" focuses the search. It is
  // swallowed only where the character is content — an input, a textarea,
  // anything editable — so typing it in the dialog itself is untouched.
  document.addEventListener('keydown', function (event) {
    if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) {
      return;
    }
    var el = document.activeElement;
    if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable)) {
      return;
    }
    event.preventDefault();
    if (dialogOpen) {
      input.focus();
      input.select();
      return;
    }
    openDialog();
  });

  dialog.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeDialog();
    } else if (event.key === 'Tab') {
      trap(event);
    }
  });

  dialog.addEventListener('click', function (event) {
    if (event.target === dialog) {
      closeDialog();
    }
  });

  // A shared search address (?q=…) opens the dialog with the term already
  // run; openDialog reruns it once the engine has arrived.
  var shared = new URLSearchParams(window.location.search).get('q');
  if (shared) {
    input.value = shared;
    openDialog();
  }
})();
