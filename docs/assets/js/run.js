'use strict';

// Run buttons for the tutorial's php fences. They are opt-in, exactly like
// the copy buttons: a fence earns one when it is marked with a data-run
// attribute (kramdown IAL `{: data-run="" }`, the same convention as
// data-copy), and every marked fence is held to its promise by the test
// suite — it parses, runs to a zero exit code, and prints something.
//
// The snippet runs in the visitor's browser, in a Web Worker, on a PHP
// compiled to WebAssembly (loaded from jsDelivr on the first click, so a
// reader who never runs anything pays nothing). Every runnable example
// carries its own version select in its control band — changing the engine
// never means scrolling away from the example — and the selects move
// together: they are one choice, written beside every Run. Each output
// panel captions itself with the engine that answered, because on a regex
// site the PCRE2 build decides what the answer means.

(function () {
  var blocks = Array.prototype.slice.call(document.querySelectorAll('main [data-run]'));
  if (blocks.length === 0) {
    return;
  }

  // The worker is cache-busted with the same build stamp as this script's
  // own src — the two files change together or not at all.
  var ownTag = document.querySelector('script[src*="run.js"]');
  var assetVersion = ownTag && ownTag.src.split('?v=')[1] || '';

  var BOOT_TIMEOUT_MS = 60000; // the first click also downloads the engine
  var RUN_TIMEOUT_MS = 4000;   // a snippet still running after this is stopped

  var VERSIONS = ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
  var DEFAULT_VERSION = '8.4';
  var STORAGE_KEY = 'php-version';

  var worker = null;
  var nextId = 1;
  var busy = false;
  var selects = [];

  var stored = null;
  try {
    stored = localStorage.getItem(STORAGE_KEY);
  } catch (error) {/* private mode keeps the default */}
  var chosen = -1 !== VERSIONS.indexOf(stored) ? stored : DEFAULT_VERSION;

  var status = document.createElement('p');
  status.className = 'visually-hidden';
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  document.body.appendChild(status);

  function announce(message) {
    status.textContent = '';
    window.setTimeout(function () {
      status.textContent = message;
    }, 50);
  }

  function createWorker() {
    // A browser without module workers keeps the documentation as it was.
    try {
      return new Worker('/assets/js/run-worker.js' + (assetVersion ? '?v=' + assetVersion : ''), { type: 'module' });
    } catch (error) {
      return null;
    }
  }

  function runTimers() {
    var boot = null;
    var guard = null;
    return {
      armBoot: function (onBootTimeout) {
        boot = window.setTimeout(onBootTimeout, BOOT_TIMEOUT_MS);
      },
      armRun: function (onRunTimeout) {
        window.clearTimeout(boot);
        guard = window.setTimeout(onRunTimeout, RUN_TIMEOUT_MS);
      },
      clear: function () {
        window.clearTimeout(boot);
        window.clearTimeout(guard);
      },
    };
  }

  function killWorker() {
    if (worker) {
      worker.terminate();
      worker = null;
    }
  }

  function makeRunnable(container, index) {
    var code = container.querySelector('code');
    if (!code || container.querySelector('.run-btn')) {
      return;
    }
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'run-btn';
    button.textContent = 'Run';
    button.setAttribute('aria-label', 'Run this example');
    container.classList.add('has-run');
    container.appendChild(button);

    // The engine choice, beside the button it applies to. One select per
    // example, all showing the same choice: moving one moves them all, and
    // the choice persists across the track's pages. It applies to the next
    // Run press — an already-open panel is not silently re-run.
    var version = document.createElement('select');
    version.className = 'run-version';
    version.setAttribute('aria-label', 'PHP version for this example');
    VERSIONS.forEach(function (value) {
      var option = document.createElement('option');
      option.value = value;
      option.textContent = 'PHP ' + value;
      option.selected = value === chosen;
      version.appendChild(option);
    });
    version.addEventListener('change', function () {
      chosen = version.value;
      try {
        localStorage.setItem(STORAGE_KEY, chosen);
      } catch (error) {/* the choice simply does not persist */}
      selects.forEach(function (other) {
        other.value = chosen;
      });
    });
    selects.push(version);
    container.appendChild(version);

    var panel = document.createElement('div');
    panel.className = 'run-out';
    panel.hidden = true;
    panel.setAttribute('role', 'status');
    panel.setAttribute('aria-live', 'polite');
    panel.setAttribute('aria-label', 'Output of example ' + (index + 1));
    var meta = document.createElement('p');
    meta.className = 'run-meta';
    var out = document.createElement('pre');
    out.className = 'run-stdout';
    var err = document.createElement('pre');
    err.className = 'run-stderr';
    panel.appendChild(meta);
    panel.appendChild(out);
    panel.appendChild(err);
    container.parentNode.insertBefore(panel, container.nextSibling);

    var timers = runTimers();

    button.addEventListener('click', function () {
      if (busy) {
        announce('Another example is still running.');
        return;
      }
      busy = true;
      button.disabled = true;
      button.textContent = 'Run…';
      meta.textContent = 'loading the PHP engine (first run only)…';
      out.textContent = '';
      err.textContent = '';
      panel.hidden = false;
      panel.setAttribute('aria-busy', 'true');

      var id = nextId++;
      var stdout = '';
      var stderr = '';

      var stop = function (message, stopped) {
        timers.clear();
        if (worker) {
          worker.removeEventListener('message', onMessage);
        }
        busy = false;
        button.disabled = false;
        button.textContent = 'Run';
        button.removeAttribute('aria-busy');
        panel.removeAttribute('aria-busy');
        if (message) {
          err.textContent = stderr += message;
        }
        announce(stopped ? 'The example was stopped: it was still running.' : 'The example finished.');
      };

      var onMessage = function (event) {
        var message = event.data;
        if (!message || message.id !== id) {
          return;
        }
        if (message.type === 'ready') {
          meta.textContent = 'running on PHP ' + version.value + '…';
          timers.armRun(function () {
            killWorker();
            stop('stopped: still running after ' + RUN_TIMEOUT_MS / 1000 + ' s\n', true);
          });
          return;
        }
        if (message.type === 'stdout') {
          stdout += message.chunk;
          out.textContent = stdout;
          return;
        }
        if (message.type === 'stderr') {
          stderr += message.chunk;
          err.textContent = stderr;
          return;
        }
        if (message.type === 'meta') {
          meta.textContent = 'PHP ' + message.php + ' · PCRE2 ' + message.pcre;
          return;
        }
        if (message.type === 'fatal') {
          stop(String(message.message) + '\n', false);
          return;
        }
        if (message.type === 'done') {
          stop(message.exitCode === 0 ? null : 'exit code ' + message.exitCode + '\n', false);
        }
      };

      if (!worker) {
        worker = createWorker();
        if (!worker) {
          busy = false;
          button.disabled = false;
          button.textContent = 'Run';
          panel.hidden = true;
          announce('This browser cannot run the examples.');
          return;
        }
      }
      worker.addEventListener('message', onMessage);
      timers.armBoot(function () {
        killWorker();
        stop('stopped: the engine did not load in time\n', false);
      });

      var source = code.innerText;
      worker.postMessage({ id: id, type: 'run', code: source, version: version.value });
    });
  }

  blocks.forEach(function (container, index) {
    makeRunnable(container, index);
  });
})();
