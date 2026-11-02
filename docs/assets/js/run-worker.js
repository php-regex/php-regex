'use strict';

// The PHP engine behind the tutorial's Run buttons and the home page's
// playground: a PHP compiled to WebAssembly (php-wasm 0.2.0, Apache-2.0),
// pinned and loaded from jsDelivr the first time a visitor asks for a run.
// It lives in this dedicated worker so a runaway snippet (catastrophic
// backtracking, a stray while (true)) hangs only itself — the page owns the
// watchdog and may terminate this worker at any moment.
//
// Two protocols share the engine cache:
//
//   run                a tutorial snippet, streamed to the page as it prints
//   analyze/match/
//   lint/describe      the playground: PHPRegex itself is written into the
//                      engine's filesystem, once per PHP version, and a
//                      driver answers each request with one JSON document
//
// A playground instance differs from a run instance (the mbstring extension,
// the library, a backtrack limit), so the two are cached apart: a page uses
// one or the other, never both.

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
// The real mbstring, which the library requires and the engine does not
// ship: one shared object per PHP version, pinned with the engine.
const MBSTRING = 'https://cdn.jsdelivr.net/npm/php-wasm-mbstring@0.2.0/index.mjs';
// The oldest engine the library itself runs on: a request for an older PHP
// is analysed here, judged for the older engine, and matched there.
const ANALYSIS_FLOOR = '8.1';
const ANALYSIS_FALLBACK = '8.4';
// Where the bundle lands in the engine's filesystem; the driver finds the
// library in the "src" directory beside it.
const LIBRARY_DIR = '/lib';
const INPUT_FILE = '/tmp/in.json';
const ENVELOPE = /\x1e([\s\S]*)\x1e/;

let PhpWeb = null;
let mbstring = null;
let bundle = null;             // the parsed bundle, fetched once
const instances = new Map();   // "run:8.4" | "play:8.4" -> runtime instance
const engines = new Map();     // PHP version -> { php, pcre } caption
let job = null;                // the run in flight: { id, capturing, metaOut }

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

async function runtime() {
  if (!PhpWeb) {
    PhpWeb = (await import(RUNTIME)).PhpWeb;
  }
  return PhpWeb;
}

async function instance(version) {
  const key = 'run:' + version;
  if (!instances.has(key)) {
    const Web = await runtime();
    const php = new Web({ version: version });
    attach(php);
    instances.set(key, php);
  }
  return instances.get(key);
}

// One extra run per version, cached: the caption that tells the reader
// which engine answered — the PHP build and the PCRE2 it bundles.
async function caption(version, php) {
  if (!engines.has(version)) {
    job.capturing = true;
    job.metaOut = '';
    await php.run('<?php echo PHP_VERSION, PHP_EOL, PCRE_VERSION;');
    job.capturing = false;
    const versions = job.metaOut.trim().split(/\r?\n/);
    engines.set(version, {
      php: versions[0] || version,
      pcre: (versions[1] || '').split(' ')[0],
    });
  }
  return engines.get(version);
}

async function run(message) {
  const id = message.id;
  job = { id: id, capturing: false, metaOut: '' };
  try {
    const php = await instance(message.version);
    self.postMessage({ id: id, type: 'ready' });

    // The fences carry no opening tag; a full script keeps its own.
    const opensPhp = /^\s*<\?php/.test(message.code);
    const source = opensPhp ? message.code : '<?php\n\n' + message.code + '\n';
    const exitCode = await php.run(source);

    // Sent before the run's own completion, so the panel is never left on
    // "running" once the button comes back.
    const engine = await caption(message.version, php);
    self.postMessage({ id: id, type: 'meta', php: engine.php, pcre: engine.pcre });
    self.postMessage({ id: id, type: 'done', exitCode: exitCode });
  } catch (error) {
    self.postMessage({ id: id, type: 'fatal', message: String((error && error.message) || error) });
  }
}

// ---------------------------------------------------------------------------
// The playground
// ---------------------------------------------------------------------------

async function loadBundle(url) {
  if (!bundle) {
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error('the library bundle could not be loaded (HTTP ' + response.status + ')');
    }
    bundle = await response.json();
  }
  return bundle;
}

function progress(id, stage) {
  self.postMessage({ id: id, type: 'progress', stage: stage });
}

// A playground instance: the engine with mbstring, the library and the
// driver in its filesystem, the driver included once. The engine keeps
// state between runs, so the library compiles once per instance.
async function playground(version, bundleUrl, id) {
  const key = 'play:' + version;
  if (instances.has(key)) {
    return instances.get(key);
  }

  progress(id, 'engine');
  const Web = await runtime();
  if (!mbstring) {
    mbstring = (await import(MBSTRING)).default;
  }
  const php = new Web({
    version: version,
    sharedLibs: [mbstring],
    ini: 'pcre.backtrack_limit=1000000',
  });
  attach(php);

  progress(id, 'library');
  const files = (await loadBundle(bundleUrl)).files;
  const binary = await php.binary;
  const FS = binary.FS;
  for (const path of Object.keys(files)) {
    const target = LIBRARY_DIR + '/' + path;
    FS.createPath('/', target.substring(1, target.lastIndexOf('/')));
    FS.writeFile(target, files[path]);
  }
  if (!FS.analyzePath('/tmp').exists) {
    FS.mkdir('/tmp');
  }

  progress(id, 'bootstrap');
  job.capturing = true;
  job.metaOut = '';
  await php.run('<?php require "' + LIBRARY_DIR + '/driver.php";');
  job.capturing = false;

  instances.set(key, php);
  return php;
}

// One request to the driver: the input written to a file (never into PHP
// source), the answer read back from the envelope.
async function ask(php, input) {
  const FS = (await php.binary).FS;
  FS.writeFile(INPUT_FILE, JSON.stringify(input));
  job.capturing = true;
  job.metaOut = '';
  await php.run('<?php echo phpregex_playground_run("' + INPUT_FILE + '");');
  job.capturing = false;
  const found = ENVELOPE.exec(job.metaOut);
  if (!found) {
    throw new Error('the engine gave no answer' + (job.metaOut.trim() ? ': ' + job.metaOut.trim().slice(0, 300) : ''));
  }
  return JSON.parse(found[1]);
}

function analysisVersion(version) {
  return version >= ANALYSIS_FLOOR ? version : ANALYSIS_FALLBACK;
}

// The analysis goes to an engine the library runs on, judged for the
// engine the page shows; the match goes to that engine itself.
async function analyze(message) {
  const id = message.id;
  job = { id: id, capturing: false, metaOut: '' };
  try {
    const version = message.version;
    const analysisOn = analysisVersion(version);
    const analyser = await playground(analysisOn, message.bundleUrl, id);
    const input = { op: 'analyze', regex: message.regex, subject: message.subject, match: false };
    if (analysisOn !== version) {
      const engine = await caption(version, await playground(version, message.bundleUrl, id));
      input.target = { php: engine.php, pcre: engine.pcre };
    }
    self.postMessage({ id: id, type: 'ready' });
    const payload = await ask(analyser, input);
    self.postMessage({ id: id, type: 'result', payload: payload, manifest: bundle ? bundle.manifest : null });
  } catch (error) {
    self.postMessage({ id: id, type: 'fatal', message: String((error && error.message) || error) });
  }
}

async function match(message) {
  const id = message.id;
  job = { id: id, capturing: false, metaOut: '' };
  try {
    const matcher = await playground(message.version, message.bundleUrl, id);
    self.postMessage({ id: id, type: 'ready' });
    const payload = await ask(matcher, { op: 'match', regex: message.regex, subject: message.subject });
    self.postMessage({ id: id, type: 'result', payload: payload, manifest: bundle ? bundle.manifest : null });
  } catch (error) {
    self.postMessage({ id: id, type: 'fatal', message: String((error && error.message) || error) });
  }
}

// lint and describe always go to the analysing engine, judged for the
// engine the page shows.
async function analysisRequest(message, input) {
  const id = message.id;
  job = { id: id, capturing: false, metaOut: '' };
  try {
    const version = message.version;
    const analysisOn = analysisVersion(version);
    const analyser = await playground(analysisOn, message.bundleUrl, id);
    if (analysisOn !== version) {
      const engine = await caption(version, await playground(version, message.bundleUrl, id));
      input.target = { php: engine.php, pcre: engine.pcre };
    }
    self.postMessage({ id: id, type: 'ready' });
    const payload = await ask(analyser, input);
    self.postMessage({ id: id, type: 'result', payload: payload, manifest: bundle ? bundle.manifest : null });
  } catch (error) {
    self.postMessage({ id: id, type: 'fatal', message: String((error && error.message) || error) });
  }
}

self.onmessage = async (event) => {
  const message = event.data;
  if (!message || typeof message.type !== 'string') {
    return;
  }
  switch (message.type) {
    case 'run':
      await run(message);
      return;
    case 'analyze':
      await analyze(message);
      return;
    case 'match':
      await match(message);
      return;
    case 'lint':
      await analysisRequest(message, { op: 'lint', regex: message.regex });
      return;
    case 'describe':
      await analysisRequest(message, { op: 'describe', regex: message.regex, index: message.index });
      return;
  }
};

self.addEventListener('error', (event) => {
  self.postMessage({ id: job ? job.id : 0, type: 'fatal', message: 'engine error: ' + event.message + ' @ ' + event.filename + ':' + event.lineno });
});
self.addEventListener('unhandledrejection', (event) => {
  self.postMessage({ id: job ? job.id : 0, type: 'fatal', message: 'engine rejection: ' + String(event.reason) });
});
