'use strict';

// The PHP engine behind the tutorial's Run buttons: a PHP compiled to
// WebAssembly (php-wasm 0.2.0, Apache-2.0), pinned and loaded from jsDelivr
// the first time a visitor runs a snippet. It lives in this dedicated worker
// so a runaway snippet (catastrophic backtracking, a stray while (true))
// hangs only itself — the page owns the watchdog and may terminate this
// worker at any moment.

// The web build of the engine registers a WebAudio unlock listener on
// `document` while booting, even inside a worker where `document` does not
// exist, and probes `window` for features a snippet never touches. No-op
// stand-ins are enough: the runner uses no canvas, no audio, and the
// feature probes degrade to "absent". `close` is the exception — a PHP
// exit path reaching window.close() must not terminate the runner.
if (typeof document === 'undefined') {
  self.document = {
    addEventListener() {},
    removeEventListener() {},
    getElementById() { return null; },
    getElementsByTagName() { return []; },
    currentScript: null,
  };
}
if (typeof window === 'undefined') {
  self.window = self;
  self.close = function () {};
}

const RUNTIME = 'https://cdn.jsdelivr.net/npm/php-wasm@0.2.0/PhpWeb.mjs';

let PhpWeb = null;
const instances = new Map(); // PHP version -> runtime instance
const engines = new Map();   // PHP version -> { php, pcre } caption
let job = null;              // the run in flight: { id, capturing, metaOut }

function attach(php) {
  php.addEventListener('output', (event) => {
    const chunk = Array.isArray(event.detail) ? event.detail.join('') : String(event.detail || '');
    if (!job) {
      return;
    }
    if (job.capturing) {
      job.metaOut += chunk;
    } else {
      self.postMessage({ id: job.id, type: 'stdout', chunk: chunk });
    }
  });
  php.addEventListener('error', (event) => {
    const chunk = Array.isArray(event.detail) ? event.detail.join('') : String(event.detail || '');
    if (job && !job.capturing) {
      self.postMessage({ id: job.id, type: 'stderr', chunk: chunk });
    }
  });
}

async function instance(version) {
  if (!PhpWeb) {
    PhpWeb = (await import(RUNTIME)).PhpWeb;
  }
  if (!instances.has(version)) {
    const php = new PhpWeb({ version: version });
    attach(php);
    instances.set(version, php);
  }
  return instances.get(version);
}

self.onmessage = async (event) => {
  const message = event.data;
  if (!message || message.type !== 'run') {
    return;
  }

  const id = message.id;
  job = { id: id, capturing: false, metaOut: '' };
  try {
    const php = await instance(message.version);
    self.postMessage({ id: id, type: 'ready' });

    // The fences carry no opening tag; a full script keeps its own.
    const opensPhp = /^\s*<\?php/.test(message.code);
    const source = opensPhp ? message.code : '<?php\n\n' + message.code + '\n';
    const exitCode = await php.run(source);

    // One extra run per version, cached: the caption that tells the reader
    // which engine answered — the PHP build and the PCRE2 it bundles. It is
    // sent before the run's own completion, so the panel is never left on
    // "running" once the button comes back.
    if (!engines.has(message.version)) {
      job.capturing = true;
      await php.run('<?php echo PHP_VERSION, PHP_EOL, PCRE_VERSION;');
      job.capturing = false;
      const versions = job.metaOut.trim().split(/\r?\n/);
      engines.set(message.version, {
        php: versions[0] || message.version,
        pcre: (versions[1] || '').split(' ')[0],
      });
    }
    const caption = engines.get(message.version);
    self.postMessage({ id: id, type: 'meta', php: caption.php, pcre: caption.pcre });
    self.postMessage({ id: id, type: 'done', exitCode: exitCode });
  } catch (error) {
    self.postMessage({ id: id, type: 'fatal', message: String((error && error.message) || error) });
  }
};

self.addEventListener('error', (event) => {
  self.postMessage({ id: job ? job.id : 0, type: 'fatal', message: 'engine error: ' + event.message + ' @ ' + event.filename + ':' + event.lineno });
});
self.addEventListener('unhandledrejection', (event) => {
  self.postMessage({ id: job ? job.id : 0, type: 'fatal', message: 'engine rejection: ' + String(event.reason) });
});
