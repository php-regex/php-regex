'use strict';

// The home page's playground. A pattern, a subject and a PHP version go to
// the engine worker (run-worker.js, the same worker as the tutorial's Run
// buttons), where a PHP compiled to WebAssembly runs PHPRegex on the pattern
// and preg_match_all() on the subject. What comes back is one JSON document
// per request; this script renders it, and nothing else: every string the
// visitor typed, or the engine answered, reaches the page through
// textContent.
//
// Before the first run gesture every tab shows the sample the site ships
// as data, rendered by the very same functions as a live answer. The engine
// (about five megabytes once, then cached) is downloaded on a run gesture
// only: typing, the Run button, the version select, an example, or a link
// that carries a pattern in its hash. Never on load, never on focus.
//
// Offsets: the library counts bytes of the pattern body, the browser counts
// UTF-16 units of the whole field. The engine returns the table that maps
// one to the other, and the matches already converted; this script only
// looks up. The pattern's tokens come the same way — byte spans in the
// highlighting visitor's vocabulary — and color the field's mirror.
//
// One tab, Step through, asks a second worker (trace-worker.js): PCRE2
// itself, compiled to WebAssembly, traced through its callout API with the
// options the engine's PHP passes. That worker is downloaded on the first
// opening of the tab, never before, and the engine's own answer is
// compared with the trace's outcome on every run.

(function () {
  var root = document.getElementById('playground');
  var tree = document.getElementById('pg-tool-tree'); // the first tab, the one the home's title promises
  var dataTag = document.getElementById('playground-data');
  if (!root || !tree || !dataTag) {
    return;
  }

  var ownTag = document.querySelector('script[src*="playground.js"]');
  var assetVersion = ownTag && ownTag.src.split('?v=')[1] || '';

  // The same list, default and storage key as the tutorial's selects: one
  // choice across the site.
  var VERSIONS = ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
  var DEFAULT_VERSION = '8.4';
  var STORAGE_KEY = 'php-version';

  var BOOT_TIMEOUT_MS = 60000;    // the first run also downloads the engine
  var REQUEST_TIMEOUT_MS = 4000;  // one analysis, one lint, or one description
  var DEBOUNCE_MS = 150;
  var PATTERN_CAP = 2000;         // bytes; the engine refuses more
  var SUBJECT_CAP = 10000;        // bytes; the engine cuts more
  var PROTOCOL = 3;               // the driver's contract this script renders
  var JSON_DUMPER = 1;            // the tree document this script walks
  var ANNOUNCE_IDLE_MS = 1000;
  var SAMPLE_STATE = 'Sample · press Run'; // the pill before the engine ran

  var bundleUrl = root.getAttribute('data-bundle') || '/assets/playground/bundle.json';
  var sample = null;
  try {
    sample = JSON.parse(dataTag.textContent || 'null');
  } catch (error) {
    sample = null;
  }

  var encoder = new TextEncoder();
  function byteLength(text) {
    return encoder.encode(text).length;
  }

  var el = {
    pattern: root.querySelector('#pg-pattern'),
    patternError: root.querySelector('#pg-pattern-error'),
    patternEditor: root.querySelector('.pg-editor-pattern'),
    patternMirror: root.querySelector('.pg-editor-pattern .pg-mirror'),
    patternCount: root.querySelector('#pg-pattern-count'),
    subject: root.querySelector('#pg-subject'),
    subjectMirror: root.querySelector('.pg-editor-subject .pg-mirror'),
    subjectCount: root.querySelector('#pg-subject-count'),
    version: root.querySelector('#pg-version'),
    run: root.querySelector('.pg-run'),
    examples: root.querySelectorAll('.pg-example'),
    status: root.querySelector('.pg-status'),
    explain: root.querySelector('.pg-explain'),
    diagnostics: root.querySelector('.pg-diagnostics'),
    lintNote: root.querySelector('.pg-lint-note'),
    facts: root.querySelector('.pg-facts'),
    state: root.querySelector('.pg-state'),
    caption: document.querySelector('.pg-caption'),
    live: root.querySelector('.pg-live'),
    treePattern: tree.querySelector('.tree-pattern'),
    treeNodes: tree.querySelector('.tree-nodes'),
    treeWrap: tree.querySelector('.tree-wrap'),
    treeDetail: tree.querySelector('.tree-detail'),
    treeError: tree.querySelector('.pg-error-slot'),
    treeBox: tree,
    voices: tree.querySelectorAll('.pg-voice-option'),
    results: root.querySelector('.pg-results'),
    tabStrip: root.querySelector('.pg-tabs'),
    loader: root.querySelector('.pg-loader'),
    loaderStage: root.querySelector('.pg-loader-stage'),
    toolTabs: root.querySelectorAll('.pg-tab'),
    badges: {
      diagnostics: root.querySelector('#pg-tab-diagnostics .pg-tab-badge'),
      redos: root.querySelector('#pg-tab-redos .pg-tab-badge'),
    },
  };

  // --- state ---------------------------------------------------------------

  var worker = null;
  var nextId = 1;
  var timers = {};          // request id -> timeout handle
  var inflight = null;      // { id, type, request }
  var pending = null;       // the latest analysis asked for while one ran
  var describeWanted = -1;  // the node index the tree wants described
  var failures = {};        // request key -> consecutive failures
  var booted = false;
  var current = null;       // the last payload rendered: { payload, regex, subject, version, prerendered }
  var lintFor = null;       // the regex the diagnostics panel's lint belongs to
  var lastLint = null;      // those findings, for the pattern mirror
  var notices = [];         // what the engine raised during this run (notices, warnings, deprecations), each once
  var matchFor = null;      // the request key the matches panel belongs to
  var describeCache = {};   // regex + index -> text
  var nodes = [];           // the tree's nodes in pre-order: { index, node, start, end, button }
  var currentNode = -1;
  var announceTimer = null;

  var stored = null;
  try {
    stored = localStorage.getItem(STORAGE_KEY);
  } catch (error) {/* private mode keeps the default */}
  var chosen = -1 !== VERSIONS.indexOf(stored) ? stored : DEFAULT_VERSION;

  // --- small helpers -------------------------------------------------------

  function text(tag, className, content) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (content !== undefined && content !== null) {
      node.textContent = String(content);
    }
    return node;
  }

  function clear(node) {
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  // The status line says what the page is doing; when it has nothing to
  // say, it shows what the engine raised during this run, so a notice
  // the library answered rather than printed is never hidden.
  function setStatus(message, tone) {
    if (!message && notices.length) {
      el.status.textContent = noticeLine();
      el.status.className = 'pg-status';
      return;
    }
    el.status.textContent = message || '';
    el.status.className = 'pg-status' + (tone ? ' is-' + tone : '');
  }

  function noticeLine() {
    return 'The engine raised ' + (notices.length === 1 ? 'a notice' : notices.length + ' notices') + ' on this run: ' + notices.join('; ');
  }

  // Every answer may carry notices; each distinct one is kept once per
  // run and told once to the live region.
  function noteNotices(payload) {
    if (!payload || !Array.isArray(payload.notices)) {
      return;
    }
    var added = false;
    payload.notices.forEach(function (notice) {
      if (typeof notice === 'string' && notice && notices.indexOf(notice) === -1) {
        notices.push(notice);
        added = true;
      }
    });
    if (added) {
      announce(noticeLine());
      if (!el.status.textContent || el.status.className === 'pg-status') {
        setStatus('', '');
      }
    }
  }

  // The word in the title bar: what the window is doing right now.
  function setState(word, tone) {
    el.state.textContent = word;
    el.state.className = 'pg-state' + (tone ? ' is-' + tone : '');
  }

  // The loader over the window's body: shown from the moment an engine
  // is asked for until it is ready, naming the stage the status line
  // names. It is for the eye alone; the live regions already speak.
  function showLoader(stage) {
    el.loaderStage.textContent = stage;
    el.loader.hidden = false;
  }

  function hideLoader() {
    el.loader.hidden = true;
  }

  function announce(message) {
    window.clearTimeout(announceTimer);
    announceTimer = window.setTimeout(function () {
      el.live.textContent = '';
      window.setTimeout(function () {
        el.live.textContent = message;
      }, 50);
    }, ANNOUNCE_IDLE_MS);
  }

  // Marks ranges of a text, by UTF-16 index, into a fragment: plain text
  // where no range applies, a <mark> otherwise. Ranges are sorted and must
  // not overlap; an overlap keeps the earlier one.
  function markRanges(source, ranges) {
    var fragment = document.createDocumentFragment();
    var at = 0;
    ranges.sort(function (a, b) {
      return a.start - b.start || a.end - b.end;
    });
    ranges.forEach(function (range) {
      var start = Math.max(range.start, at);
      var end = Math.min(range.end, source.length);
      if (end <= start) {
        return;
      }
      if (start > at) {
        fragment.appendChild(document.createTextNode(source.slice(at, start)));
      }
      var mark = text('mark', range.className, source.slice(start, end));
      if (range.title) {
        mark.title = range.title;
      }
      if (range.index !== undefined) {
        mark.setAttribute('data-index', String(range.index));
      }
      fragment.appendChild(mark);
      at = end;
    });
    if (at < source.length) {
      fragment.appendChild(document.createTextNode(source.slice(at)));
    }
    // A trailing newline needs a visible line in a pre to keep heights equal.
    fragment.appendChild(document.createTextNode('\n'));
    return fragment;
  }

  // The UTF-16 range of a body-relative byte span, through the engine's table.
  function unitRange(payload, start, end) {
    var table = payload.unitOf;
    var base = payload.delimited ? payload.delimited.bodyStart : 0;
    if (!table || typeof start !== 'number') {
      return null;
    }
    var from = table[Math.min(base + start, table.length - 1)];
    var to = typeof end === 'number' ? table[Math.min(base + end, table.length - 1)] : from + 1;
    return { start: from, end: Math.max(to, from + (to === from ? 1 : 0)) };
  }

  // --- the fields ----------------------------------------------------------

  function autosize(area) {
    // The text field keeps the height the stylesheet gives it on a wide
    // screen — as tall as the answers beside it — and scrolls inside for
    // a longer text; the mirror follows by its own insets.
    var filled = area === el.subject && window.matchMedia('(min-width: 1280px)').matches;
    if (filled) {
      area.style.height = '';
    } else {
      area.style.height = 'auto';
      area.style.height = area.scrollHeight + 'px';
    }
    var mirror = area.previousElementSibling;
    if (mirror) {
      mirror.style.height = filled ? '' : area.style.height;
      mirror.scrollTop = area.scrollTop;
    }
  }

  function counter(area, count, cap) {
    var bytes = byteLength(area.value);
    count.textContent = bytes + ' / ' + cap + ' bytes';
    count.classList.toggle('is-over', bytes > cap);
  }

  function refreshFields() {
    autosize(el.pattern);
    autosize(el.subject);
    counter(el.pattern, el.patternCount, PATTERN_CAP);
    counter(el.subject, el.subjectCount, SUBJECT_CAP);
    syncExamples();
  }

  // The example that sits in the fields, if any: pressed, so the row
  // says what the window is showing.
  function syncExamples() {
    Array.prototype.forEach.call(el.examples, function (chip) {
      var on = chip.getAttribute('data-regex') === el.pattern.value && chip.getAttribute('data-subject') === el.subject.value;
      chip.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  // --- the worker ----------------------------------------------------------

  function createWorker() {
    try {
      var created = new Worker('/assets/js/run-worker.js' + (assetVersion ? '?v=' + assetVersion : ''), { type: 'module' });
      created.addEventListener('message', onMessage);
      return created;
    } catch (error) {
      return null;
    }
  }

  function killWorker() {
    Object.keys(timers).forEach(function (id) {
      window.clearTimeout(timers[id]);
    });
    timers = {};
    if (worker) {
      worker.removeEventListener('message', onMessage);
      worker.terminate();
      worker = null;
    }
    inflight = null;
  }

  function requestKey(request) {
    var fields = ['mode', 'dialect', 'pythonVersion', 'width', 'compact', 'purpose'].map(function (name) {
      return request[name] === undefined ? '' : String(request[name]);
    });
    return request.type + '\u0000' + request.version + '\u0000' + request.regex + '\u0000' + (request.subject || '') + '\u0000' + (request.index === undefined ? '' : request.index) + '\u0000' + fields.join('\u0000') + '\u0000' + (request.subjects ? JSON.stringify(request.subjects) : '');
  }

  function send(request) {
    if (!worker) {
      worker = createWorker();
      if (!worker) {
        setStatus('This browser cannot run the engine: it has no module workers.', 'error');
        setState('Stopped', 'error');
        hideLoader();
        return false;
      }
    }
    var id = nextId++;
    var message = Object.assign({ id: id, bundleUrl: bundleUrl }, request);
    inflight = { id: id, type: request.type, request: request };
    el.run.disabled = true;
    if (!booted) {
      showLoader('Loading PHP ' + request.version + '…');
    }
    timers[id] = window.setTimeout(function () {
      fail(request, booted ? 'The engine did not answer within ' + REQUEST_TIMEOUT_MS / 1000 + ' s.' : 'The engine did not load within ' + BOOT_TIMEOUT_MS / 1000 + ' s.');
    }, booted ? REQUEST_TIMEOUT_MS : BOOT_TIMEOUT_MS);
    worker.postMessage(message);
    return true;
  }

  function settle(id) {
    window.clearTimeout(timers[id]);
    delete timers[id];
    if (inflight && inflight.id === id) {
      inflight = null;
    }
    el.run.disabled = false;
  }

  // A request that failed is replayed once on a fresh worker; a second
  // failure on the same input stops there, until the input changes.
  function fail(request, reason) {
    // The console keeps the reason the status line will overwrite on a replay.
    if (window.console && console.warn) {
      console.warn('playground: ' + request.type + ' failed: ' + reason);
    }
    var wasBooted = booted;
    killWorker();
    booted = false;
    var key = requestKey(request);
    failures[key] = (failures[key] || 0) + 1;
    el.run.disabled = false;
    if (request.eager && activeTool !== 'redos') {
      // The verdict no tab asked for: one failure is the last for this
      // input, its badge stays empty, and the window goes on. A Run, or
      // the ReDoS tab when it opens, asks again.
      failures[key] = 2;
      toolAnswers.redos = { key: key, payload: { error: { message: reason, position: null } }, request: request, checked: true, failed: true };
      hideLoader();
      setState('Ready');
      setStatus(reason + ' The engine could not judge the pattern for backtracking; press Run to try again.', 'warn');
      updateBadges();
      drain();
      return;
    }
    if (failures[key] < 2) {
      setStatus(reason + ' Starting the engine again…', 'warn');
      setState('Restarting…', 'busy');
      send(request);
      return;
    }
    hideLoader();
    setState('Stopped', 'error');
    if (!wasBooted) {
      // Never got an engine: the network, the CDN or the browser, not the input.
      setStatus('The PHP engine could not be loaded from cdn.jsdelivr.net (' + reason + '). The sample stays below; press Run to try again.', 'error');
      announce('The PHP engine could not be loaded.');
      return;
    }
    // The phase is given up for this input: the others go on.
    if (request.type === 'lint' && current && current.regex === request.regex) {
      lintFor = request.regex;
      lastLint = null;
      el.lintNote.textContent = 'the linter did not finish';
      setStatus(reason + ' The linter could not finish on this pattern; the rest of the answer stands.', 'warn');
    } else if (request.type === 'match' && current) {
      matchFor = key;
      setStatus(reason + ' The match could not finish on this input; the analysis stands.', 'warn');
    } else if (request.type === 'describe') {
      describeWanted = -1;
      setStatus('', '');
    } else if (isToolRequest(request)) {
      // A tab's request is given up for this input: the tab says so, and
      // Run is how the visitor asks again.
      var tab = request.purpose || request.type;
      // A tab may have closed while it was asked for: the line names it.
      // The trace's probe and self-check are asked for its tab.
      var owner = tab === 'probe' || tab === 'selfcheck' ? 'trace' : tab;
      setStatus(reason + (activeTool === owner ? ' The engine could not finish this tab; press Run to try again.' : ' The engine could not finish the ' + TAB_LABELS[owner] + ' tab; press Run to try again.'), 'warn');
      if (tab === 'probe') {
        probeGaveUp(request, reason);
      } else if (tab === 'selfcheck') {
        selfCheckGaveUp(request, reason);
      } else if (tab === 'trace') {
        failTool(tab, request, reason.replace(/\.$/, '') + '.');
      } else if (activeTool === tab) {
        renderTool(tab, false);
      }
    } else {
      setStatus(reason + ' The engine could not finish on this input; the last answer stays below. Change the pattern or the subject to try again.', 'error');
    }
    announce('The engine stopped on this input.');
    drain();
  }

  // A tab's request given up for this input: the tab holds the failure and
  // does not ask again until the input moves. The trace tab's answer is a
  // stopped one, so it shows the failure instead of "Reading the pattern…".
  function failTool(tab, request, message) {
    var answer = { key: request.chainKey || requestKey(request), payload: { error: { message: message, position: null } }, request: request, checked: true, failed: true };
    if (tab === 'trace') {
      traceStop(answer, message + ' Press Run to try again.', 'warn');
    }
    toolAnswers[tab] = answer;
    if (activeTool === tab) {
      renderTool(tab, true);
    }
  }

  function exhausted(request) {
    return (failures[requestKey(request)] || 0) >= 2;
  }

  function drain() {
    if (inflight) {
      return;
    }
    if (pending) {
      var next = pending;
      pending = null;
      pendingTool = null;
      send(next);
      return;
    }
    // The trace's next witness, if it has one to ask the engine: queued
    // as the tool's second step, sent right below.
    advanceTrace();
    if (pendingTool && current && !current.prerendered) {
      var step = pendingTool;
      pendingTool = null;
      if (!exhausted(step)) {
        send(step);
        return;
      }
      // The second step of a tool failed twice: the tab says so and the
      // first step is not asked again until the input moves.
      var who = step.purpose || step.type;
      if (who === 'probe') {
        probeGaveUp(step, 'The engine did not answer.');
      } else if (who === 'selfcheck') {
        selfCheckGaveUp(step, 'The engine did not answer.');
      } else {
        // Keyed by the request that failed (the chain's first step for a second
        // step), never by the tab's current settings, which may have moved.
        failTool(who, step, 'The engine did not answer.');
      }
    }
    if (current && !current.prerendered) {
      var matchRequest = { type: 'match', regex: current.regex, subject: current.subject, version: current.version };
      // The engine is asked whatever the library said: its compile message
      // is one of the two voices, and a pattern the library refuses may
      // still compile.
      if (matchFor !== requestKey(matchRequest) && !exhausted(matchRequest)) {
        send(matchRequest);
        return;
      }
      var lintRequest = { type: 'lint', regex: current.regex, version: current.version };
      if (lintFor !== current.regex && !exhausted(lintRequest) && current.payload.ast) {
        send(lintRequest);
        return;
      }
      var verdict = verdictRequest();
      if (verdict) {
        send(verdict);
        return;
      }
      var toolRequestNext = nextToolRequest();
      if (toolRequestNext) {
        send(toolRequestNext);
        return;
      }
    }
    if (describeWanted >= 0 && current && !current.prerendered) {
      var wanted = describeWanted;
      describeWanted = -1;
      var describeRequest = { type: 'describe', regex: current.regex, index: wanted, version: current.version };
      if (describeCache[describeKey(current, wanted)] === undefined && !exhausted(describeRequest)) {
        send(describeRequest);
      }
    }
    if (!inflight && !pending && booted) {
      setState('Ready');
    }
  }

  function describeKey(state, index) {
    return state.version + '\u0000' + state.regex + '\u0000' + index;
  }

  var STAGES = {
    engine: 'Downloading PHP {v} (about 4 MB, once)…',
    library: 'Loading PHPRegex into the engine…',
    bootstrap: 'Starting PHPRegex…',
  };

  function onMessage(event) {
    var message = event.data;
    if (!message || !inflight || message.id !== inflight.id) {
      return;
    }
    var request = inflight.request;
    if (message.type === 'progress') {
      var stage = (STAGES[message.stage] || 'Loading…').replace('{v}', request.version);
      setStatus(stage, 'busy');
      setState('Loading…', 'busy');
      showLoader(stage);
      return;
    }
    if (message.type === 'ready') {
      booted = true;
      hideLoader();
      setState('Running…', 'busy');
      window.clearTimeout(timers[message.id]);
      timers[message.id] = window.setTimeout(function () {
        fail(request, 'The engine did not answer within ' + REQUEST_TIMEOUT_MS / 1000 + ' s.');
      }, REQUEST_TIMEOUT_MS);
      setStatus(request.type === 'analyze' ? 'Running on PHP ' + request.version + '…' : (request.type === 'match' ? 'Matching on PHP ' + request.version + '…' : (TOOL_STATUS[request.type] || '')), 'busy');
      return;
    }
    if (message.type === 'fatal') {
      fail(request, 'The engine failed: ' + message.message + '.');
      return;
    }
    if (message.type !== 'result') {
      return;
    }
    var payload = message.payload || {};
    if (message.manifest) {
      manifest = message.manifest;
    }
    if (payload.protocol !== PROTOCOL) {
      settle(message.id);
      pending = null;
      setStatus('The engine answered in a form this page does not know: reload the page.', 'error');
      return;
    }
    settle(message.id);
    if (request.type === 'analyze') {
      // A new run: the notices of the previous one are gone with it.
      notices = [];
    }
    noteNotices(payload);
    if (typeof payload.fatal === 'string') {
      // The driver caught something: the last answer stays, the status says.
      setStatus('The engine failed on this request: ' + payload.fatal, 'error');
      if (request.type === 'match' && current) {
        matchFor = requestKey(request);
      } else if (request.type === 'lint' && current && current.regex === request.regex) {
        lintFor = request.regex;
      } else if (isToolRequest(request)) {
        // A tab that failed holds the failure, and does not ask again
        // until the input moves.
        var failed = request.purpose || request.type;
        if (failed === 'probe') {
          probeGaveUp(request, payload.fatal);
        } else if (failed === 'selfcheck') {
          selfCheckGaveUp(request, payload.fatal);
        } else {
          failTool(failed, request, payload.fatal);
        }
      }
      drain();
      return;
    }
    if (request.type === 'analyze') {
      render(payload, request, false);
      setStatus('', '');
      setState('Ready');
    } else if (request.type === 'match') {
      if (current && current.regex === request.regex && current.subject === request.subject) {
        matchFor = requestKey(request);
        current.payload.analysisEngine = current.payload.analysisEngine || current.payload.engine;
        current.payload.engine = payload.engine || current.payload.engine;
        current.payload.engineError = payload.engineError;
        current.payload.matches = payload.matches;
        current.payload.input = payload.input || current.payload.input;
        renderCaption(current.payload, false);
        renderSubjectMarks(current.payload, request.subject);
        renderErrors(current.payload);
        renderTreeError(current.payload);
        if (lastLint) {
          renderLint(lastLint);
        }
        // The match has answered; its status line has said its word. When
        // a lint follows, its own messages take the line from here.
        setStatus('', '');
      }
    } else if (request.type === 'lint') {
      if (current && current.regex === request.regex) {
        lintFor = request.regex;
        renderLint(payload.lint);
      }
    } else if (request.type === 'describe') {
      describeCache[request.version + '\u0000' + request.regex + '\u0000' + request.index] = payload.text === null ? '' : payload.text;
      if (currentNode === request.index && current && current.regex === request.regex) {
        showDetail(request.index);
      }
    } else if (isToolRequest(request)) {
      onToolResult(request, payload);
      // The tool has answered: its busy line goes, and the engine's
      // notices, if any, take the line.
      setStatus('', '');
    }
    drain();
  }

  // --- requests ------------------------------------------------------------

  var debounce = null;

  function schedule() {
    window.clearTimeout(debounce);
    debounce = window.setTimeout(function () {
      analyzeNow(true);
    }, DEBOUNCE_MS);
  }

  // typed: the analysis follows a keystroke (the typeahead path), as
  // opposed to a Run gesture — the Run button, Ctrl/⌘ Enter, the version
  // select, an example, a chip, a link. The step debugger runs on a Run
  // gesture alone, never on the typeahead path.
  function analyzeNow(typed) {
    window.clearTimeout(debounce);
    stopStroll();
    // The mirror holds the last answer's pattern: when the field says
    // another one — an example, a link, a keystroke — the field reads in
    // its own ink until the next answer. A run on a new subject alone
    // keeps the frame, the tokens being the pattern's.
    if (!current || current.regex !== el.pattern.value) {
      el.patternEditor.classList.remove('is-live');
    }
    var request = { type: 'analyze', regex: el.pattern.value, subject: el.subject.value, version: chosen, typed: typed === true };
    writeHash(request);
    if (inflight) {
      pending = request;
      return;
    }
    send(request);
  }

  // --- rendering -----------------------------------------------------------

  function render(payload, request, prerendered) {
    // The lint findings belong to a pattern judged for an engine: another
    // pattern, or another engine, asks for them again.
    if (!current || current.regex !== request.regex || current.version !== request.version) {
      lastLint = null;
      lintFor = null;
      lastHotspots = null;
      // A replay is asked for one pattern: another pattern starts from the verdict.
      toolSettings.redos.mode = 'theoretical';
    }
    current = { payload: payload, regex: request.regex, subject: request.subject, version: request.version, prerendered: prerendered };
    pendingTool = null;
    if (!prerendered) {
      // A fresh analysis asks for a fresh match, and gives a phase that was
      // given up another chance: pressing Run is how a visitor retries.
      matchFor = null;
      delete failures[requestKey({ type: 'match', regex: request.regex, subject: request.subject, version: request.version })];
      // A tab that gave up on this input asks again on the next Run: its
      // failure count goes, and so does the answer that held the failure.
      TOOLS.forEach(function (tool) {
        // The sample's answers were judged ahead of time: a live run asks
        // the engine in the visitor's browser.
        if (toolAnswers[tool] && toolAnswers[tool].seeded) {
          delete toolAnswers[tool];
        }
        var stale = toolRequest(tool);
        if (stale) {
          delete failures[requestKey(stale)];
        }
        if (toolAnswers[tool] && toolAnswers[tool].selfCheckRequest) {
          // The trace's self-check gave up on the engine: a Run asks again.
          delete failures[requestKey(toolAnswers[tool].selfCheckRequest)];
          delete toolAnswers[tool].selfCheckRequest;
        }
        if (toolAnswers[tool] && toolAnswers[tool].failed) {
          // The step that failed may be the chain's second one, with a
          // count of its own.
          delete failures[requestKey(toolAnswers[tool].request)];
          delete toolAnswers[tool];
        }
      });
      if (lastLint === null) {
        lintFor = null;
        delete failures[requestKey({ type: 'lint', regex: request.regex, version: request.version })];
      }
      // The trace runs on a Run gesture, or when its tab is opened; a
      // typed analysis leaves the tab waiting for Run. A trace that
      // stopped on the previous input may start again on this one.
      traceWanted = !request.typed;
      tracer.failures = {};
    }
    traceMark = null;
    if (!prerendered && payload.matches === null && payload.engineError === null) {
      payload.matches = { pending: true };
    }
    renderCaption(payload, prerendered);
    renderPatternMirror(payload, request.regex, null);
    renderSubjectMarks(payload, request.subject);
    renderExplain(payload.explain);
    renderErrors(payload);
    if (prerendered) {
      lintFor = request.regex;
    } else if (lintFor !== request.regex) {
      el.lintNote.textContent = payload.ast ? 'linting…' : '';
    } else if (lastLint) {
      renderLint(lastLint);
    }
    renderTree(payload, request.regex);
    if (prerendered) {
      seedTools();
      setState(SAMPLE_STATE);
    }
    updateBadges();
    renderTool(activeTool, false);
  }

  function chip(label, value, className) {
    var item = text('li', 'pg-chip' + (className ? ' ' + className : ''));
    if (label) {
      item.appendChild(text('span', 'pg-chip-label', label));
    }
    item.appendChild(text('span', 'pg-chip-value', value));
    return item;
  }

  // The facts, one chip each: which PHP and which PCRE2 answered, the
  // limits that shaped the answer, the library snapshot.
  function renderCaption(payload, prerendered) {
    clear(el.facts);
    clear(el.caption);
    if (prerendered) {
      // The pill in the title bar says the sample: nothing ran yet.
      el.caption.hidden = true;
      return;
    }
    var engine = payload.engine;
    if (engine) {
      el.facts.appendChild(chip('PHP', engine.php, 'is-engine'));
      el.facts.appendChild(chip('PCRE2', engine.pcre, 'is-engine'));
      if (engine.jit === false) {
        el.facts.appendChild(chip('JIT', 'off'));
      }
      if (engine.backtrackLimit) {
        el.facts.appendChild(chip('backtrack limit', String(engine.backtrackLimit).replace(/\B(?=(\d{3})+$)/g, '\u202f')));
      }
    }
    if (payload.analysisEngine && engine && payload.analysisEngine.php !== engine.php) {
      el.facts.appendChild(chip('analysed on', 'PHP ' + payload.analysisEngine.php));
    } else if (payload.target && engine && payload.target.php !== engine.php) {
      el.facts.appendChild(chip('judged for', 'PHP ' + payload.target.php + ' · PCRE2 ' + payload.target.pcre));
    }
    if (payload.library) {
      var lib = payload.library.version;
      if (manifest) {
        lib += ' · ' + manifest.commit;
      }
      el.facts.appendChild(chip('PHPRegex', lib));
    }
    if (engine && engine.jit === false) {
      el.caption.appendChild(text('span', 'pg-caption-jit', 'JIT is off in this browser build. A server with pcre.jit=1, PHP\u2019s default, matches the same strings, but a pattern that exhausts the backtrack limit here may match there, or fail with a JIT stack error instead.'));
    }
    if (!el.caption.firstChild) {
      el.caption.hidden = true;
    } else {
      el.caption.hidden = false;
    }
  }

  // The bundle's manifest, as the worker read it: the snapshot the caption
  // names. It travels with the first result.
  var manifest = null;

  // The pattern field's mirror: the engine's tokens color the text, the
  // error position underlined, lint spans dotted, the tree's current node
  // highlighted. The two channels compose — one paints the letters, the
  // others paint behind and under them.
  function renderPatternMirror(payload, regex, lint) {
    var ranges = [];
    (payload.errors || []).forEach(function (error) {
      var range = unitRange(payload, error.position, null);
      if (range) {
        ranges.push({ start: range.start, end: range.end, className: 'pg-mark-error', priority: 1, title: error.message });
      }
    });
    (lint || []).forEach(function (finding) {
      var range = unitRange(payload, finding.offset, finding.end);
      if (range) {
        ranges.push({ start: range.start, end: range.end, className: 'pg-mark-lint', priority: 2, title: finding.message });
      }
    });
    // The verdict's hotspots, tinted behind the letters: the panel's text
    // carries the same facts, the tint only points at them.
    (lastHotspots || []).forEach(function (hotspot) {
      var hot = unitRange(payload, hotspot.start, hotspot.end);
      if (hot) {
        ranges.push({ start: hot.start, end: hot.end, className: 'pg-mark-hot is-' + hotspot.severity, priority: 3, title: 'ReDoS hotspot (' + hotspot.severity + '): ' + hotspot.pattern });
      }
    });
    if (currentNode >= 0 && nodes[currentNode]) {
      var span = unitRange(payload, nodes[currentNode].start, nodes[currentNode].end);
      if (span) {
        ranges.unshift({ start: span.start, end: span.end, className: 'pg-mark-node', priority: 0 });
      }
    }
    // The item PCRE2 is about to try, from the Step through tab: lit over
    // the node that holds it, which the tree's pattern lights on its own.
    if (traceMark && activeTool === 'trace') {
      var item = unitRange(payload, traceMark.start, traceMark.end);
      if (item) {
        ranges.unshift({ start: item.start, end: item.end, className: 'pg-mark-trace', priority: -1 });
      }
    }
    clear(el.patternMirror);
    el.patternMirror.appendChild(tilePattern(payload, regex, dedupe(ranges)));
    // The colored glyphs live in the mirror: they stand in for the field's
    // own only while the two say the same thing. The frame in between —
    // typing, an engine on its way — keeps the field's plain ink, so what
    // the visitor types is always readable.
    el.patternEditor.classList.toggle('is-live', regex === el.pattern.value);
  }

  // The engine's token spans in UTF-16 units, with the delimiters and the
  // flags around them: the field keeps its frame even where the pattern
  // does not parse, for the delimiters still stand.
  function patternTokens(payload) {
    var tokens = [];
    var delimited = payload.delimited;
    if (!payload.unitOf || !delimited) {
      return tokens;
    }
    var bodyStart = payload.unitOf[Math.min(delimited.bodyStart, payload.unitOf.length - 1)];
    var bodyEnd = payload.unitOf[Math.min(delimited.bodyEnd, payload.unitOf.length - 1)];
    if (bodyStart > 0) {
      tokens.push({ start: 0, end: bodyStart, type: 'meta' });
    }
    // bodyEnd sits on the closing delimiter; the flags follow it.
    tokens.push({ start: bodyEnd, end: bodyEnd + 1, type: 'meta' });
    if (delimited.flags) {
      tokens.push({ start: bodyEnd + 1, end: bodyEnd + 1 + delimited.flags.length, type: 'flag' });
    }
    (payload.tokens || []).forEach(function (token) {
      var range = unitRange(payload, token[0], token[1]);
      if (range) {
        tokens.push({ start: range.start, end: range.end, type: token[2] });
      }
    });
    return tokens;
  }

  // The pattern as colored pieces: the token spans cut by the overlay
  // spans, so a piece carries its color and whatever note sits on it. The
  // overlays were deduped: at most one covers any point.
  function tilePattern(payload, regex, overlays) {
    var tokens = patternTokens(payload);
    var cuts = [0, regex.length];
    tokens.forEach(function (token) {
      cuts.push(token.start, token.end);
    });
    overlays.forEach(function (range) {
      cuts.push(range.start, range.end);
    });
    cuts.sort(function (a, b) {
      return a - b;
    });
    var fragment = document.createDocumentFragment();
    for (var i = 0; i < cuts.length - 1; i += 1) {
      var start = cuts[i];
      var end = cuts[i + 1];
      if (end <= start) {
        continue;
      }
      var token = null;
      var overlay = null;
      tokens.forEach(function (candidate) {
        if (!token && candidate.start <= start && start < candidate.end) {
          token = candidate;
        }
      });
      overlays.forEach(function (candidate) {
        if (!overlay && candidate.start <= start && start < candidate.end) {
          overlay = candidate;
        }
      });
      var classes = overlay ? [overlay.className] : [];
      if (token) {
        classes.push('pg-tok-' + token.type);
      }
      var mark = text('mark', classes.join(' '), regex.slice(start, end));
      if (overlay && overlay.title) {
        mark.title = overlay.title;
      }
      fragment.appendChild(mark);
    }
    // The caret that says the field is editable, where typing would go
    // on; the stylesheet shows it while the field is untouched.
    fragment.appendChild(text('span', 'pg-caret'));
    // A trailing newline needs a visible line in a pre to keep heights equal.
    fragment.appendChild(document.createTextNode('\n'));
    return fragment;
  }

  // Overlapping ranges would hide each other; the node highlight wins, then
  // the error, then lint, then a hotspot.
  function dedupe(ranges) {
    ranges.sort(function (a, b) {
      return a.priority - b.priority;
    });
    var kept = [];
    ranges.forEach(function (range) {
      var overlaps = kept.some(function (other) {
        return range.start < other.end && other.start < range.end;
      });
      if (!overlaps) {
        kept.push(range);
      }
    });
    return kept;
  }

  // The matches are read in the text field: each one underlined where it
  // lands, one mark per set, alternating tints so neighbours tell apart.
  // The count is spoken by the live line; the field is the list.
  function renderSubjectMarks(payload, subject) {
    var matches = payload.matches;
    var ranges = [];
    if (matches && !matches.pending && matches.sets) {
      matches.sets.forEach(function (set, i) {
        var whole = set['0'];
        if (whole) {
          ranges.push({ start: whole[2], end: whole[2] + whole[0].length, className: 'pg-mark-match' + (i % 2 ? ' is-alt' : ''), index: i });
        }
      });
    }
    clear(el.subjectMirror);
    el.subjectMirror.appendChild(markRanges(subject, ranges));
    updateBadges();
    if (payload.errors && payload.errors.length) {
      announce((payload.errors[0].source === 'validate' ? 'Invalid for this engine: ' : 'Syntax error: ') + payload.errors[0].message);
    } else if (matches && !matches.pending && announceEngineError && matches.error && matches.error !== 'No error') {
      // After "Try it": the engine's own words, once.
      announceEngineError = false;
      announce('The engine gave up: ' + matches.error + '.');
    } else if (matches && !matches.pending) {
      announceEngineError = false;
      announce(matches.count === 1 ? '1 match' : matches.count + ' matches');
    } else if (!matches && payload.engineError) {
      announce('The engine refused the pattern: ' + payload.engineError);
    }
  }

  // The explanation, one line per construct, indented two spaces per level,
  // rebuilt as nested lists. Two voices, as the tree's rows have them: the
  // construct's kind in the quiet mono, what it means in the page's ink —
  // the eye finds the structure before it reads the prose.
  function renderExplain(explain) {
    clear(el.explain);
    if (!explain) {
      // The error block above both voices says why.
      return;
    }
    var lines = explain.split('\n');
    var stack = [{ depth: -1, list: text('ul', 'pg-explain-list') }];
    var rootDone = false;
    lines.forEach(function (line) {
      var depth = line.match(/^ */)[0].length / 2;
      var content = line.trim();
      // The closing lines ("End group", "End quantified group") close a
      // level the nesting already shows.
      if (!content || /^End /.test(content)) {
        return;
      }
      while (stack.length > 1 && stack[stack.length - 1].depth >= depth) {
        stack.pop();
      }
      var item = text('li', 'pg-explain-item');
      var head = text('span', 'pg-explain-head');
      if (!rootDone && depth === 0) {
        // The root line says what the pattern does; the flags it says as
        // a chip, as the status bar names the engine.
        rootDone = true;
        var flags = content.match(/\(with flags: (.+)\)$/);
        head.appendChild(text('span', 'pg-explain-root', flags ? content.slice(0, content.length - flags[0].length).trim() : content));
        if (flags) {
          head.appendChild(text('span', 'pg-explain-flags', 'flags ' + flags[1]));
        }
      } else {
        explainVoices(content, head);
      }
      item.appendChild(head);
      var sublist = text('ul', 'pg-explain-list');
      item.appendChild(sublist);
      stack[stack.length - 1].list.appendChild(item);
      stack.push({ depth: depth, list: sublist });
    });
    el.explain.appendChild(stack[0].list);
    Array.prototype.forEach.call(el.explain.querySelectorAll('.pg-explain-item'), function (item) {
      var sublist = item.querySelector(':scope > .pg-explain-list');
      if (!sublist) {
        return;
      }
      if (sublist.children.length) {
        // What holds lines under it is structure: words that came as
        // plain prose take the quiet voice, like a labelled kind.
        item.classList.add('is-block');
        var spans = item.querySelectorAll(':scope > .pg-explain-head > span');
        if (spans.length === 1 && spans[0].className === 'pg-explain-text') {
          spans[0].className = 'pg-explain-kind';
        }
      } else {
        sublist.parentNode.removeChild(sublist);
      }
    });
  }

  // The library's sentence, reflowed for the eye: "A digit: [0-9]
  // (exactly 4 times)" reads "A digit [0-9], exactly 4 times". The colon
  // between a word and a bracketed or escaped token gives way to a space;
  // a repetition at the end of the line, in brackets, becomes a clause.
  // Anything else stays as the library wrote it, and so do the lines that
  // carry the visitor's own words: a comment, a verb's name, a callout's
  // argument.
  var EXPLAIN_REFLOW = [
    [/([A-Za-z]): (?=[[\\])/g, '$1 '],
    [/ \(((?:zero or more|one or more) times|once or not at all|exactly \d+ times|at least \d+(?: but not more than \d+)? times|with quantifier \S+)((?: \((?:as few as possible|and do not backtrack)\))?)\)$/, ', $1$2'],
  ];

  var REFLOW_KEEP = ['Comment', 'PCRE Verb', 'Callout'];

  function reflow(sentence) {
    return EXPLAIN_REFLOW.reduce(function (line, rule) {
      return line.replace(rule[0], rule[1]);
    }, sentence);
  }

  // One line, split into its voices: a branch keyword, a labelled kind
  // ("Anchor: …", a quiet tag that names the tree's word for it), a bare
  // quoted literal, or plain prose.
  function explainVoices(content, head) {
    var keyword = content.match(/^(IF|THEN|ELSE|EITHER|OR)(?:\s+(.*))?$/);
    var labeled = keyword ? null : content.match(/^([A-Z][A-Za-z]*(?: [A-Za-z]+)*): (.+)$/);
    if (keyword) {
      head.appendChild(text('span', 'pg-explain-keyword', keyword[1]));
      if (keyword[2]) {
        head.appendChild(document.createTextNode(' '));
        head.appendChild(text('span', 'pg-explain-text', reflow(keyword[2])));
      }
    } else if (labeled) {
      head.appendChild(text('span', 'pg-explain-kind', labeled[1]));
      head.appendChild(document.createTextNode(' '));
      head.appendChild(text('span', 'pg-explain-text', REFLOW_KEEP.indexOf(labeled[1]) === -1 ? reflow(labeled[2]) : labeled[2]));
    } else if (/^['"].*['"]$/.test(content)) {
      head.appendChild(text('span', 'pg-explain-literal', content));
    } else {
      head.appendChild(text('span', 'pg-explain-text', reflow(content)));
    }
  }

  var SEVERITY_LABELS = { critical: 'critical', error: 'error', warning: 'warning', style: 'style', perf: 'performance', info: 'info' };

  // The first thing that went wrong, as a block the open tab shows: the
  // library's word on the pattern, else the engine's. Null when nothing did.
  function errorBlock(payload) {
    var error = payload.errors && payload.errors[0];
    if (!error && !payload.engineError) {
      return null;
    }
    var block = text('div', 'pg-error-block');
    var head = text('p', 'pg-diag-head');
    if (error) {
      head.appendChild(text('span', 'pg-diag-badge', error.source === 'validate' ? 'invalid for this engine' : 'syntax error'));
      head.appendChild(text('span', 'pg-diag-message', error.message));
      block.appendChild(head);
      if (typeof error.position === 'number') {
        block.appendChild(text('p', 'pg-diag-facts', 'byte ' + error.position + ' of the pattern body'));
      }
      if (error.hint) {
        block.appendChild(text('p', 'pg-diag-hint', error.hint));
      }
    } else {
      head.appendChild(text('span', 'pg-diag-badge', 'PHP says'));
      head.appendChild(text('code', 'pg-diag-message', payload.engineError));
      block.appendChild(head);
    }
    return block;
  }

  function renderErrors(payload) {
    // The field the visitor typed into says it first: the pattern is wrong,
    // to the eye by its tint, to a screen reader by its state and the
    // error it is described by.
    var invalid = !!((payload.errors && payload.errors.length) || payload.engineError);
    el.patternEditor.classList.toggle('is-error', invalid);
    if (invalid) {
      el.pattern.setAttribute('aria-invalid', 'true');
    } else {
      el.pattern.removeAttribute('aria-invalid');
    }
    el.patternError.textContent = invalid ? (payload.errors && payload.errors.length ? payload.errors[0].message : 'PHP says: ' + payload.engineError) : '';
    el.pattern.setAttribute('aria-describedby', invalid ? 'pg-pattern-count pg-pattern-error' : 'pg-pattern-count');
    clear(el.diagnostics);
    (payload.errors || []).forEach(function (error) {
      var item = text('li', 'pg-diag is-error');
      var head = text('p', 'pg-diag-head');
      head.appendChild(text('span', 'pg-diag-badge', error.source === 'validate' ? 'invalid for this engine' : 'syntax error'));
      head.appendChild(text('span', 'pg-diag-message', error.message));
      item.appendChild(head);
      var facts = [];
      if (typeof error.position === 'number') {
        facts.push('byte ' + error.position + ' of the pattern body');
      }
      if (error.code) {
        facts.push(error.code);
      }
      if (facts.length) {
        item.appendChild(text('p', 'pg-diag-facts', facts.join(' · ')));
      }
      if (error.hint) {
        item.appendChild(text('p', 'pg-diag-hint', error.hint));
      }
      el.diagnostics.appendChild(item);
    });
    if (payload.engineError) {
      var engine = text('li', 'pg-diag is-engine');
      var head2 = text('p', 'pg-diag-head');
      head2.appendChild(text('span', 'pg-diag-badge', 'PHP says'));
      head2.appendChild(text('code', 'pg-diag-message', payload.engineError));
      engine.appendChild(head2);
      el.diagnostics.appendChild(engine);
    }
    if (payload.matches && payload.matches.error && payload.matches.error !== 'No error' && !payload.matches.pending) {
      var limit = text('li', 'pg-diag is-engine');
      var head3 = text('p', 'pg-diag-head');
      head3.appendChild(text('span', 'pg-diag-badge', 'PHP says'));
      head3.appendChild(text('span', 'pg-diag-message', 'preg_match_all() returned false: ' + payload.matches.error + '.'));
      limit.appendChild(head3);
      el.diagnostics.appendChild(limit);
    }
    if (payload.input && payload.input.truncated) {
      el.diagnostics.appendChild(text('li', 'pg-diag is-note', 'The subject was cut at ' + SUBJECT_CAP + ' bytes.'));
    }
    updateDiagnosticsEmpty();
    updateBadges();
  }

  function renderLint(findings) {
    Array.prototype.forEach.call(el.diagnostics.querySelectorAll('.is-lint'), function (old) {
      old.parentNode.removeChild(old);
    });
    el.lintNote.textContent = '';
    lastLint = findings || null;
    if (findings === null || findings === undefined) {
      updateDiagnosticsEmpty();
      return;
    }
    el.lintNote.textContent = findings.length ? findings.length + (findings.length === 1 ? ' finding' : ' findings') : 'no lint finding';
    findings.forEach(function (finding) {
      var item = text('li', 'pg-diag is-lint is-' + finding.severity);
      var head = text('p', 'pg-diag-head');
      head.appendChild(text('span', 'pg-diag-badge', SEVERITY_LABELS[finding.severity] || finding.severity));
      head.appendChild(text('span', 'pg-diag-message', finding.message));
      item.appendChild(head);
      var facts = [];
      if (typeof finding.offset === 'number') {
        facts.push('bytes ' + finding.offset + (typeof finding.end === 'number' ? '–' + finding.end : ''));
      }
      var link = text('a', 'pg-diag-rule', finding.id);
      link.href = '/reference/rules/';
      var factsLine = text('p', 'pg-diag-facts', facts.length ? facts.join(' · ') + ' · ' : '');
      factsLine.appendChild(link);
      item.appendChild(factsLine);
      if (finding.hint) {
        item.appendChild(text('p', 'pg-diag-hint', finding.hint));
      }
      el.diagnostics.appendChild(item);
    });
    if (current) {
      renderPatternMirror(current.payload, current.regex, findings);
    }
    updateDiagnosticsEmpty();
    updateBadges();
  }

  function updateDiagnosticsEmpty() {
    var empty = el.diagnostics.querySelector('.is-empty');
    if (empty) {
      empty.parentNode.removeChild(empty);
    }
    if (!el.diagnostics.children.length) {
      el.diagnostics.appendChild(text('li', 'pg-diag is-empty', 'No syntax error, nothing the linter objects to.'));
    }
  }

  // --- the syntax tree -----------------------------------------------------

  var CHILD_KEYS = ['pattern', 'children', 'alternatives', 'child', 'members', 'from', 'to', 'expression', 'left', 'right', 'content', 'condition', 'yes', 'no'];

  function childNodes(node) {
    var children = [];
    Object.keys(node).forEach(function (key) {
      var value = node[key];
      if (value && typeof value === 'object' && !Array.isArray(value) && typeof value.type === 'string') {
        children.push(value);
      } else if (Array.isArray(value)) {
        value.forEach(function (item) {
          if (item && typeof item === 'object' && typeof item.type === 'string') {
            children.push(item);
          }
        });
      }
    });
    return children;
  }

  // What a row says about its node: the kind, then what tells this node
  // from its siblings (a name, a number, a value, a bound).
  function describeRow(node) {
    var kind = String(node.type).replace(/_/g, ' ');
    var detail = '';
    switch (node.type) {
      case 'regex': detail = node.flags ? 'flags ' + node.flags : ''; kind = 'pattern'; break;
      case 'group': detail = node.kind + (node.name ? ' \u201c' + node.name + '\u201d' : '') + (typeof node.number === 'number' ? ' #' + node.number : ''); break;
      case 'quantifier': detail = '{' + node.min + ',' + (node.max === null ? '' : node.max) + '} ' + node.mode; break;
      case 'literal': detail = JSON.stringify(node.value === undefined ? node.bytes : node.value); break;
      case 'char_type': detail = '\\' + node.value; break;
      case 'anchor': detail = String(node.value); break;
      case 'char_class': detail = node.negated ? 'negated' : ''; break;
      case 'unicode_property': detail = '\\' + (node.negated ? 'P' : 'p') + '{' + node.name + '}'; break;
      case 'backref': detail = String(node.reference); break;
      case 'dot': detail = 'any character'; break;
      default:
        Object.keys(node).forEach(function (key) {
          var value = node[key];
          if (!detail && key !== 'type' && key !== 'start' && key !== 'end' && CHILD_KEYS.indexOf(key) === -1 && (typeof value === 'string' || typeof value === 'number')) {
            detail = String(value);
          }
        });
    }
    return { kind: kind, detail: detail };
  }

  function renderTree(payload, regex) {
    nodes = [];
    currentNode = -1;
    clear(el.treeNodes);
    clear(el.treePattern);
    renderTreeError(payload);
    if (!payload.ast) {
      clear(el.treeDetail);
      if (!el.treeError.firstChild) {
        el.treeDetail.textContent = 'No tree.';
      }
      return;
    }
    if (payload.ast.version !== JSON_DUMPER) {
      el.treeDetail.textContent = 'The engine dumped a tree this page does not know how to draw: reload the page.';
      return;
    }

    // Each item is the tree item itself (the pattern the role asks for):
    // focusable, one tab stop for the whole tree, the arrow keys move
    // between items. Every node is expanded; the attribute says so.
    (function walk(node, parentList, level) {
      var index = nodes.length;
      var entry = { index: index, node: node, start: node.start, end: node.end, button: null };
      nodes.push(entry);
      var item = text('li', 'tree-item');
      item.setAttribute('role', 'treeitem');
      item.setAttribute('aria-level', String(level));
      item.setAttribute('aria-selected', 'false');
      item.setAttribute('tabindex', index === 0 ? '0' : '-1');
      item.setAttribute('data-index', String(index));
      var row = describeRow(node);
      var label = text('span', 'tree-row');
      label.appendChild(text('span', 'tree-kind', row.kind));
      if (row.detail) {
        label.appendChild(text('span', 'tree-value', row.detail));
      }
      label.appendChild(text('span', 'tree-span', node.start + '\u2013' + node.end));
      entry.button = item;
      item.appendChild(label);
      parentList.appendChild(item);
      var children = childNodes(node);
      if (children.length) {
        item.setAttribute('aria-expanded', 'true');
        var list = text('ul', 'tree-children');
        list.setAttribute('role', 'group');
        item.appendChild(list);
        children.forEach(function (child) {
          walk(child, list, level + 1);
        });
      }
    })(payload.ast, el.treeNodes, 1);

    // The pattern, piece by piece: one span per node, nested the way the
    // tree is, so pointing at a character names the deepest node around
    // it and lighting a node lights its whole span. The glue between the
    // children carries the engine's token colors, as the field's mirror
    // does — the tree reads what the parser read.
    var base = payload.delimited ? payload.unitOf[payload.delimited.bodyStart] : 0;
    var bodyUnits = payload.delimited ? regex.slice(base, payload.unitOf[payload.delimited.bodyEnd]) : (payload.ast.source || '');
    el.treePattern.appendChild(pieceOf(payload, 0, bodyUnits, base, treeTiles(payload, base, bodyUnits.length)));
    el.treePattern.appendChild(document.createTextNode('\n'));
    // After the walk, so the empty state says its line: nodes is still
    // empty before it, and the hint would never show.
    showDetail(-1);
  }

  // Above both voices of the tree, the reason there is none, or the
  // engine's refusal of a pattern the library still read.
  function renderTreeError(payload) {
    clear(el.treeError);
    var block = errorBlock(payload);
    if (block) {
      el.treeError.appendChild(block);
    }
  }

  // The engine's tokens over the tree's body: the same spans the field's
  // mirror colors, shifted to where the pieces are cut from.
  function treeTiles(payload, base, length) {
    var tiles = [];
    patternTokens(payload).forEach(function (token) {
      var start = Math.max(token.start - base, 0);
      var end = Math.min(token.end - base, length);
      if (end > start) {
        tiles.push({ start: start, end: end, type: token.type });
      }
    });
    return tiles;
  }

  // The glue between a piece's children, colored the way the engine reads
  // it: one span per token that crosses it, plain text where none does.
  function tileText(body, tiles, from, to) {
    var fragment = document.createDocumentFragment();
    var at = from;
    tiles.forEach(function (tile) {
      if (tile.end <= at || tile.start >= to) {
        return;
      }
      if (tile.start > at) {
        fragment.appendChild(document.createTextNode(body.slice(at, tile.start)));
      }
      fragment.appendChild(text('span', 'pg-tok-' + tile.type, body.slice(Math.max(at, tile.start), Math.min(to, tile.end))));
      at = Math.min(to, tile.end);
    });
    if (to > at) {
      fragment.appendChild(document.createTextNode(body.slice(at, to)));
    }
    return fragment;
  }

  function pieceOf(payload, index, body, base, tiles) {
    var entry = nodes[index];
    var range = unitRange(payload, entry.start, entry.end);
    var from = range.start - base;
    var to = Math.max(from, (entry.end > entry.start ? range.end : range.start) - base);
    var span = text('mark', 'tree-piece');
    span.setAttribute('data-index', String(index));
    var at = from;
    var children = childNodes(entry.node);
    var childIndex = index + 1;
    children.forEach(function () {
      var childEntry = nodes[childIndex];
      var childRange = unitRange(payload, childEntry.start, childEntry.end);
      var childFrom = Math.max(at, childRange.start - base);
      if (childFrom > at) {
        span.appendChild(tileText(body, tiles, at, childFrom));
      }
      span.appendChild(pieceOf(payload, childIndex, body, base, tiles));
      at = Math.max(at, (childEntry.end > childEntry.start ? childRange.end : childRange.start) - base);
      childIndex += subtreeSize(childIndex);
    });
    if (to > at) {
      span.appendChild(tileText(body, tiles, at, to));
    }
    return span;
  }

  function subtreeSize(index) {
    var size = 1;
    childNodes(nodes[index].node).forEach(function () {
      size += subtreeSize(index + size);
    });
    return size;
  }

  function setCurrentNode(index) {
    if (currentNode === index) {
      return;
    }
    if (currentNode >= 0 && nodes[currentNode]) {
      nodes[currentNode].button.classList.remove('is-current');
      nodes[currentNode].button.setAttribute('aria-selected', 'false');
      nodes[currentNode].button.setAttribute('tabindex', '-1');
    }
    currentNode = index;
    Array.prototype.forEach.call(el.treePattern.querySelectorAll('mark'), function (mark) {
      mark.classList.toggle('is-current', Number(mark.getAttribute('data-index')) === index);
    });
    if (index >= 0 && nodes[index]) {
      nodes[index].button.classList.add('is-current');
      nodes[index].button.setAttribute('aria-selected', 'true');
      nodes[index].button.setAttribute('tabindex', '0');
    } else if (nodes[0]) {
      nodes[0].button.setAttribute('tabindex', '0');
    }
    showDetail(index);
    if (current) {
      renderPatternMirror(current.payload, current.regex, lintFor === current.regex ? lastLint : null);
    }
  }

  // One still line under the tree: the node's bytes and what it reads as.
  // Its height never moves, whatever node is under the pointer.
  function showDetail(index) {
    clear(el.treeDetail);
    var entry = nodes[index];
    if (!entry) {
      el.treeDetail.appendChild(text('span', 'tree-detail-empty', nodes.length ? 'Point at a node to read it.' : ''));
      return;
    }
    var row = describeRow(entry.node);
    el.treeDetail.appendChild(text('span', 'tree-detail-span', 'bytes ' + entry.start + '\u2013' + entry.end));
    var cached = current ? describeCache[describeKey(current, index)] : undefined;
    if (cached) {
      el.treeDetail.appendChild(text('span', 'tree-detail-text', cached));
    } else if (cached === undefined && current && !current.prerendered) {
      el.treeDetail.appendChild(text('span', 'tree-detail-text is-pending', row.kind + (row.detail ? ' ' + row.detail : '')));
      describeWanted = index;
      drain();
    } else {
      el.treeDetail.appendChild(text('span', 'tree-detail-text', row.kind + (row.detail ? ' ' + row.detail : '')));
    }
  }

  // --- the tabs and the tools ----------------------------------------------

  // One strip of tabs over the answers. The first two are views of the
  // analysis (the tree, the diagnostics), drawn whenever an
  // answer lands; the others are what the console does with a pattern,
  // one tool each: the ReDoS verdict, the translation, sample subjects,
  // the layout, the match stepped through on PCRE2 itself. The matches
  // are no tab: the text field underlines them where they land.
  // A tool asks the engine when its tab is opened, or when the input moved
  // under it, never on every keystroke (the verdict alone is asked on
  // every run, for its badge); the answer of each belongs to the input it
  // was asked for, like the lint belongs to its pattern.
  var TABS = ['tree', 'diagnostics', 'redos', 'transpile', 'samples', 'format', 'trace'];
  var TOOLS = ['redos', 'transpile', 'samples', 'format', 'trace'];
  var TAB_LABELS = { tree: 'Tree', diagnostics: 'Diagnostics', redos: 'ReDoS', transpile: 'Translate', samples: 'Samples', format: 'Format', trace: 'Step through' };
  var TOOL_STORAGE_KEY = 'playground-tool';
  var TOOL_STATUS = {
    redos: 'Judging the pattern for backtracking…',
    transpile: 'Translating…',
    samples: 'Drawing subjects…',
    check: 'Asking the engine…',
    format: 'Laying the pattern out…',
    'trace-input': 'Reading the pattern for the trace…',
  };

  // A request one of the tabs made: its own type, an engine-only check it
  // chained, or the trace's first step through the driver.
  function isToolRequest(request) {
    return TOOLS.indexOf(request.type) !== -1 || request.type === 'check' || request.type === 'trace-input';
  }
  var SEVERITY_WORDS = { safe: 'safe', low: 'low risk', medium: 'medium risk', high: 'high risk', critical: 'critical', unknown: 'no verdict' };
  var TARGET_LABELS = { js: 'JavaScript', html: 'HTML pattern', python: 'Python' };
  var PYTHON_VERSIONS = ['3.8', '3.9', '3.10', '3.11', '3.12', '3.13'];

  // A first visit opens on the tree; a tab the visitor chose is opened
  // again, and anything else the key holds falls back to the tree.
  var activeTool = 'tree';
  try {
    var storedTool = localStorage.getItem(TOOL_STORAGE_KEY);
    if (storedTool && TABS.indexOf(storedTool) !== -1) {
      activeTool = storedTool;
    }
  } catch (error) {/* the choice simply does not persist */}
  var toolAnswers = {};    // tool -> { key, payload, request }: the last answer each tab holds
  var toolSettings = {
    redos: { mode: 'theoretical' },
    transpile: { target: 'js', pythonVersion: '3.11' },
    format: { width: 80, fit: false, compact: false }, // the driver's own width, unless the field's is asked for
  };
  var lastHotspots = null; // the verdict's hotspots lit in the pattern field, for the current regex

  function toolPanel(tool) {
    return root.querySelector('#pg-tool-' + tool);
  }

  function toolRequest(tool) {
    if (!current) {
      return null;
    }
    // The subject is the trace's business alone (through its subject
    // cut): a subject-only edit leaves the other tabs' answers current.
    var request = { type: tool, regex: current.regex, version: current.version };
    switch (tool) {
      case 'redos':
        request.mode = toolSettings.redos.mode;
        break;
      case 'transpile':
        // "target" is the engine's word in a request: the dialect has its own.
        request.dialect = toolSettings.transpile.target;
        request.pythonVersion = toolSettings.transpile.target === 'python' ? toolSettings.transpile.pythonVersion : '';
        break;
      case 'format':
        request.width = formatWidth();
        request.compact = toolSettings.format.compact;
        break;
      case 'samples':
        break;
      case 'trace':
        // The driver reads the delimiters first; the trace itself goes to
        // the trace worker once the engine's row and module are known.
        request.type = 'trace-input';
        request.purpose = 'trace';
        request.subject = current.subject;
        break;
      default:
        return null;
    }
    return request;
  }

  // The width the layout is asked for: the one set, 80 unless the visitor
  // moved it; or, on "Fit the field", the characters the pattern field
  // shows on one line: what "fits" means to the visitor's eye.
  function formatWidth() {
    if (!toolSettings.format.fit) {
      return toolSettings.format.width;
    }
    var style = window.getComputedStyle(el.pattern);
    var canvas = document.createElement('canvas');
    var context = canvas.getContext && canvas.getContext('2d');
    var charWidth = 0;
    if (context) {
      context.font = style.font || (style.fontSize + ' ' + style.fontFamily);
      charWidth = context.measureText('M').width;
    }
    var inner = el.pattern.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);
    var width = charWidth > 0 ? Math.floor(inner / charWidth) : 80;
    return Math.max(24, Math.min(120, width || 80));
  }

  // The sample's answers, one per tool, each keyed by the request stored
  // beside it: the tab holds it while its own request is that one. The
  // controls start from what the stored request asked for.
  function seedTools() {
    var stored = sample && sample.default && sample.default.tools;
    if (!stored || !current) {
      return;
    }
    if (stored.redos) {
      toolSettings.redos.mode = stored.redos.mode;
    }
    if (stored.transpile) {
      toolSettings.transpile.target = stored.transpile.dialect;
    }
    if (stored.format) {
      toolSettings.format = { width: stored.format.width, fit: false, compact: stored.format.compact };
    }
    ['redos', 'transpile', 'samples', 'format'].forEach(function (tool) {
      if (!stored[tool] || !sample[tool]) {
        return;
      }
      var request = Object.assign({}, stored[tool], { version: current.version });
      toolAnswers[tool] = { key: requestKey(request), payload: sample[tool], request: request, checked: true, seeded: true };
    });
    lastHotspots = sample.redos && sample.redos.redos && sample.redos.redos.hotspots ? sample.redos.redos.hotspots : null;
    if (lastHotspots) {
      // The mirror was painted before the verdict was read: the hotspots
      // are tinted now, not on the next hover.
      renderPatternMirror(current.payload, current.regex, lintFor === current.regex ? lastLint : null);
    }
  }

  // The verdict, asked on every run whatever tab is open: its badge says
  // it on the closed tab, and its hotspots tint the pattern field. The
  // theoretical verdict only; the replay waits for its button.
  function verdictRequest() {
    if (!current || current.prerendered || !current.payload.ast) {
      return null;
    }
    var held = toolAnswers.redos;
    if (held && held.request && held.request.regex === current.regex && held.request.version === current.version) {
      return null;
    }
    var request = { type: 'redos', regex: current.regex, version: current.version, mode: 'theoretical', eager: true };
    return exhausted(request) ? null : request;
  }

  // The badges on the closed tabs: the state of the answer, read without
  // a click. A color says it, and a count or a word says it again.
  function setBadge(badge, tone, count, words) {
    clear(badge);
    badge.className = 'pg-tab-badge' + (tone ? ' is-' + tone : '');
    if (!tone && count === null) {
      return;
    }
    badge.appendChild(text('span', 'visually-hidden', ', '));
    if (tone && tone !== 'count' && tone !== 'lint') {
      badge.appendChild(text('span', 'pg-tab-dot'));
    }
    if (count !== null) {
      badge.appendChild(text('span', 'pg-tab-count', String(count)));
    }
    badge.appendChild(text('span', 'visually-hidden', ' ' + words));
  }

  var BADGE_TONES = { safe: 'ok', low: 'warn', medium: 'warn', high: 'danger', critical: 'danger' };

  function updateBadges() {
    if (!current) {
      return;
    }
    var payload = current.payload;
    var matches = payload.matches;
    var engineGaveUp = !!(matches && !matches.pending && matches.error && matches.error !== 'No error');
    var errors = (payload.errors || []).length + (payload.engineError ? 1 : 0) + (engineGaveUp ? 1 : 0);
    var findings = lintFor === current.regex && lastLint ? lastLint.length : 0;
    if (errors) {
      setBadge(el.badges.diagnostics, 'danger', errors, errors === 1 ? 'error' : 'errors');
    } else if (findings) {
      setBadge(el.badges.diagnostics, 'lint', findings, findings === 1 ? 'lint finding' : 'lint findings');
    } else {
      setBadge(el.badges.diagnostics, '', null, '');
    }
    var held = toolAnswers.redos;
    var verdict = held && held.request && held.request.regex === current.regex && held.request.version === current.version && held.payload && !held.payload.error && held.payload.redos && !held.payload.redos.error ? held.payload.redos : null;
    var tone = verdict ? BADGE_TONES[verdict.severity] : '';
    setBadge(el.badges.redos, tone || '', null, tone ? SEVERITY_WORDS[verdict.severity] : '');
  }

  // Whether the active tab still holds the answer for the current input.
  function toolIsCurrent(tool) {
    var answer = toolAnswers[tool];
    var request = toolRequest(tool);
    return !!(answer && request && answer.key === requestKey(request));
  }

  // The request the active tab wants next, or null.
  function nextToolRequest() {
    if (!current || current.prerendered) {
      return null;
    }
    var tool = activeTool;
    if (toolIsCurrent(tool)) {
      return null;
    }
    var request = toolRequest(tool);
    if (!request || exhausted(request)) {
      return null;
    }
    // The trace is offered on a pattern the tree can show, on a Run
    // gesture or a tab opening — never on the typeahead path.
    if (tool === 'trace' && (!current.payload.ast || !traceWanted)) {
      return null;
    }
    return request;
  }

  function onToolResult(request, payload) {
    if (request.type === 'check' && request.purpose === 'probe') {
      // A witness run on the engine: the probe belongs to the engine, not
      // to the input, so it is kept whatever the fields say now.
      onProbeCheck(request, payload);
      return;
    }
    if (request.type === 'check' && request.purpose === 'selfcheck') {
      // The engine's own preg_match() on the traced subject: the trace it
      // answers is named by the chain key, whatever the fields say now.
      onSelfCheck(request, payload);
      return;
    }
    if (!current || current.regex !== request.regex || current.version !== request.version) {
      return;
    }
    if (request.type === 'trace-input') {
      toolAnswers.trace = { key: requestKey(request), payload: payload, request: request, checked: false, stage: 'input', step: 0, view: null };
      prepareTrace(toolAnswers.trace);
      // drain() follows every result and moves the trace on from here.
      if (activeTool === 'trace') {
        renderTool('trace', true);
      }
      return;
    }
    if (request.type === 'check' && request.purpose === 'samples') {
      // The engine the page shows had the last word on each sample.
      var drawn = toolAnswers.samples;
      if (drawn && drawn.payload.samples && drawn.request.regex === request.regex) {
        var checks = payload.checks || [];
        var kept = { matching: [], nonMatching: [] };
        var dropped = drawn.payload.samples.dropped;
        request.subjects.forEach(function (subject, i) {
          var wanted = i < drawn.payload.samples.matching.length ? 1 : 0;
          if (checks[i] && checks[i].matched === wanted) {
            kept[wanted ? 'matching' : 'nonMatching'].push(subject);
          } else {
            dropped += 1;
          }
        });
        drawn.payload.samples = { matching: kept.matching, nonMatching: kept.nonMatching, seed: drawn.payload.samples.seed, dropped: dropped };
        drawn.checked = true;
        drawn.key = requestKey(drawn.request);
        if (activeTool === 'samples') {
          renderTool('samples', true);
        }
      }
      return;
    }
    var tool = request.type;
    var key = requestKey(request);
    toolAnswers[tool] = { key: key, payload: payload, request: request, checked: false };
    if (tool === 'samples' && current.version < ANALYSIS_FLOOR_VERSION && payload.samples) {
      // Drawn by the analysing engine, judged for this one: the engine the
      // page shows checks every sample before a chip is made of it.
      toolAnswers.samples.key = '';
      pendingTool = { type: 'check', purpose: 'samples', regex: request.regex, subject: request.subject, version: request.version, subjects: payload.samples.matching.concat(payload.samples.nonMatching), chainKey: requestKey(request) };
    }
    if (tool === 'redos') {
      lastHotspots = payload.redos && payload.redos.hotspots ? payload.redos.hotspots : null;
      if (current && current.regex === request.regex) {
        renderPatternMirror(current.payload, current.regex, lastLint);
      }
      updateBadges();
    }
    if (activeTool === tool) {
      renderTool(tool, true);
    }
  }

  // The engines below the analysis floor have no library: a sample drawn
  // by the analysing engine is checked by the one the page shows.
  var ANALYSIS_FLOOR_VERSION = '8.1';
  var pendingTool = null;  // the second step of a two-step tool request

  function activateTool(tool, focus) {
    if (TABS.indexOf(tool) === -1) {
      return;
    }
    var left = activeTool;
    if (tool !== left) {
      // Another tab opens at its top, not where the last one was scrolled.
      el.results.scrollTop = 0;
    }
    activeTool = tool;
    if (tool === 'redos' && toolAnswers.redos && toolAnswers.redos.failed && toolAnswers.redos.request.eager) {
      // The verdict failed while the tab was closed: opened, it asks again.
      delete failures[toolAnswers.redos.key];
      delete toolAnswers.redos;
    }
    try {
      localStorage.setItem(TOOL_STORAGE_KEY, tool);
    } catch (error) {/* the choice simply does not persist */}
    if (tool === 'trace') {
      // Opening the tab is a gesture: the trace runs for the input shown;
      // drain(), below, moves a trace already under way.
      traceWanted = true;
    } else if (left === 'trace' && traceMark && current) {
      // The item lit in the pattern field belongs to the tab that left.
      renderPatternMirror(current.payload, current.regex, lintFor === current.regex ? lastLint : null);
    }
    Array.prototype.forEach.call(el.toolTabs, function (tab) {
      var on = tab.getAttribute('data-tool') === tool;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      if (on && focus) {
        tab.focus();
      }
    });
    TABS.forEach(function (name) {
      toolPanel(name).hidden = name !== tool;
    });
    if (TOOLS.indexOf(tool) !== -1) {
      renderTool(tool, false);
    }
    drain();
  }

  // A setting of a tool moved: the engine is asked for it. On the sample,
  // that is a run gesture: the engine boots and answers the new setting.
  function settingMoved(tool) {
    // The stored answer was for the old setting: the panel says so at once.
    renderTool(tool, true);
    if (current && current.prerendered) {
      analyzeNow();
      return;
    }
    drain();
  }

  // --- rendering the tabs ---------------------------------------------------

  // announceResult: true when the answer just arrived or a setting of the
  // tab moved, false on a rerender (a new analysis, a tab switch), so the
  // match count said by the run is not spoken over.
  //
  // A rebuild drops every control of the panel: the one that had the
  // keyboard's focus is found again at its place among the new ones, or the
  // panel itself takes the focus when the new ones are fewer (a hint alone
  // while the engine answers), so a change made from a select or a button
  // never sends the focus to the body.
  function renderTool(tool, announceResult) {
    if (TOOLS.indexOf(tool) === -1) {
      // A view: drawn with the analysis, never asked for.
      return;
    }
    var panel = toolPanel(tool);
    var focused = document.activeElement;
    var place = panel && focused && panel.contains(focused) ? focusables(panel).indexOf(focused) : -1;
    renderToolPanel(tool, announceResult);
    if (place !== -1) {
      var again = focusables(panel)[place];
      (again || panel).focus();
    }
  }

  function focusables(panel) {
    return Array.prototype.slice.call(panel.querySelectorAll('button, select, input, textarea, a[href], [tabindex]'));
  }

  function renderToolPanel(tool, announceResult) {
    var panel = toolPanel(tool);
    clear(panel);
    panel.classList.remove('is-hot');
    if (!current) {
      panel.appendChild(text('p', 'pg-tool-hint', 'Run a pattern first.'));
      return;
    }
    var answer = toolAnswers[tool];
    var fresh = toolIsCurrent(tool) && answer;
    if (tool === 'trace' && !fresh) {
      clearTraceMark();
    }
    if (tool === 'format') {
      renderFormatControls(panel);
    } else if (tool === 'transpile') {
      renderTranspileControls(panel);
    }
    if (!fresh) {
      if (current.prerendered) {
        panel.appendChild(text('p', 'pg-tool-hint', tool === 'trace' ? 'Press Run to step through this pattern on PCRE2.' : 'The engine answers this setting once it runs.'));
      } else if (tool === 'trace' && !current.payload.ast) {
        panel.appendChild(text('p', 'pg-tool-hint', 'A pattern the parser does not read has no trace: the syntax tree could not show its steps.'));
      } else if (tool === 'trace' && !traceWanted) {
        panel.appendChild(text('p', 'pg-tool-hint', 'Press Run to step through this pattern on PCRE2.'));
      } else {
        var request = toolRequest(tool);
        panel.appendChild(text('p', 'pg-tool-hint', request && exhausted(request) ? 'The engine did not answer this tab: press Run to try again.' : 'Asking the engine…'));
      }
      return;
    }
    switch (tool) {
      case 'redos': renderRedos(panel, answer.payload); break;
      case 'transpile': renderTranspile(panel, answer.payload, announceResult); break;
      case 'samples': renderSamples(panel, answer, announceResult); break;
      case 'format': renderFormat(panel, answer.payload, announceResult); break;
      case 'trace': renderTrace(panel, answer, announceResult); break;
      default: break;
    }
  }

  function faultLine(panel, fault, prefix) {
    panel.appendChild(text('p', 'pg-tool-line is-warn', (prefix || '') + fault.message + (typeof fault.position === 'number' ? ' (byte ' + fault.position + ')' : '')));
  }

  function toolButton(label, onClick, pressed) {
    var button = text('button', 'pg-tool-button', label);
    button.type = 'button';
    if (pressed !== undefined) {
      button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
    }
    button.addEventListener('click', onClick);
    return button;
  }

  // Copies a text, or shows it in a field a hand can select when the
  // clipboard is out of reach (an insecure page, a refusing browser).
  function copyText(value, what, panel) {
    var done = function () {
      announce('Copied: ' + what + '.');
      setStatus('Copied ' + what + '.', '');
    };
    var fallback = function () {
      var old = panel.querySelector('.pg-tool-textarea');
      if (old) {
        old.parentNode.removeChild(old.previousSibling);
        old.parentNode.removeChild(old);
      }
      var id = 'pg-copy-' + Date.now();
      var label = text('label', 'visually-hidden', 'Select and copy: ' + what);
      label.htmlFor = id;
      var area = text('textarea', 'pg-tool-textarea', value);
      area.id = id;
      area.readOnly = true;
      panel.appendChild(label);
      panel.appendChild(area);
      area.focus();
      area.select();
      announce('The clipboard is out of reach: select the text in the field that opened.');
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(done, fallback);
    } else {
      fallback();
    }
  }

  // --- ReDoS -----------------------------------------------------------------

  function renderRedos(panel, payload) {
    if (payload.error) {
      faultLine(panel, payload.error, 'No verdict: ');
      return;
    }
    var redos = payload.redos;
    if (!redos) {
      panel.appendChild(text('p', 'pg-tool-hint', 'No verdict.'));
      return;
    }
    if (redos.error) {
      panel.appendChild(text('p', 'pg-tool-line is-warn', 'No verdict: ' + redos.error));
      return;
    }
    // The verdict first and large; how sure it is, on a line of its own
    // under it. A high or critical verdict warms the whole panel.
    panel.classList.toggle('is-hot', redos.severity === 'high' || redos.severity === 'critical');
    var verdict = text('div', 'pg-verdict is-' + redos.severity);
    var line = text('p', 'pg-verdict-line');
    line.appendChild(text('span', 'pg-verdict-badge', SEVERITY_WORDS[redos.severity] || redos.severity));
    var head = redos.complexity === 'linear' ? 'Linear' : (redos.complexity === 'exponential' ? 'Exponential backtracking' : (redos.complexity === 'polynomial' ? 'Polynomial backtracking' + (redos.degree ? ', degree ' + redos.degree : '') : 'Backtracking not classified'));
    line.appendChild(text('span', 'pg-verdict-head', head));
    verdict.appendChild(line);
    verdict.appendChild(text('p', 'pg-verdict-meta', (redos.proof === 'proven' ? 'proven · ' : '') + redos.confidence + ' confidence · ' + (redos.confirmed ? 'confirmed by the engine' : redos.mode) + (redos.linear ? ' · linear for a test' : '')));
    panel.appendChild(verdict);
    if (redos.vulnerable_part) {
      panel.appendChild(text('p', 'pg-tool-line', 'Vulnerable part: ' + redos.vulnerable_part + (redos.trigger ? ' (' + redos.trigger + ')' : '')));
    }
    if (redos.hotspots && redos.hotspots.length) {
      var list = text('ul', 'pg-tool-list');
      redos.hotspots.forEach(function (hotspot) {
        var item = text('li', 'pg-hotspot is-' + hotspot.severity);
        item.appendChild(text('code', null, hotspot.pattern));
        item.appendChild(text('span', 'pg-hotspot-severity', hotspot.severity));
        item.appendChild(text('span', 'pg-hotspot-facts', 'bytes ' + hotspot.start + '–' + hotspot.end + (hotspot.trigger ? ', ' + hotspot.trigger : '')));
        list.appendChild(item);
      });
      panel.appendChild(list);
    }
    var witness = redos.witness;
    if (witness) {
      var witnessLine = text('p', 'pg-tool-line');
      witnessLine.appendChild(text('span', null, 'Witness: '));
      if (witness.prefix) {
        witnessLine.appendChild(text('code', null, '"' + witness.prefix + '"'));
        witnessLine.appendChild(text('span', null, ' + '));
      }
      witnessLine.appendChild(text('code', null, '"' + witness.pump + '"'));
      witnessLine.appendChild(text('span', null, ' × n'));
      if (witness.suffix) {
        witnessLine.appendChild(text('span', null, ' + '));
        witnessLine.appendChild(text('code', null, '"' + witness.suffix + '"'));
      }
      panel.appendChild(witnessLine);
      var demonstrable = redos.complexity === 'exponential' || (redos.confirmation && redos.confirmation.evidence === 'backtrack_limit');
      var tryRow = text('div', 'pg-tool-row');
      if (demonstrable && typeof redos.attack === 'string') {
        tryRow.appendChild(toolButton('Try it', function () {
          el.subject.value = redos.attack;
          refreshFields();
          announceEngineError = true;
          analyzeNow();
        }));
        tryRow.appendChild(text('span', 'pg-tool-line', 'puts the ' + byteLength(redos.attack) + '-byte attack string in the subject field; the engine answers with its backtrack limit.'));
      } else {
        tryRow.appendChild(text('span', 'pg-tool-line', 'Polynomial: not demonstrable under the ' + SUBJECT_CAP + '-byte subject cap.'));
      }
    }
    var replay = text('div', 'pg-tool-row');
    if (typeof redos.replay_error === 'string') {
      replay.appendChild(text('span', 'pg-tool-line is-warn', 'The replay could not run on this engine: ' + redos.replay_error));
    } else if (redos.confirmed && redos.confirmation) {
      var c = redos.confirmation;
      replay.appendChild(text('span', 'pg-tool-line', 'Replayed: ' + (c.evidence || 'no evidence') + ', ' + (c.samples ? c.samples.length : 0) + ' run' + (c.samples && c.samples.length === 1 ? '' : 's') + ', backtrack limit ' + c.backtrack_limit + ', JIT off.'));
    } else if (redos.severity !== 'safe') {
      // The action of a dangerous verdict: run the attack on the engine.
      var replayButton = toolButton('Replay the attack', function () {
        toolSettings.redos.mode = 'confirmed';
        settingMoved('redos');
      });
      replayButton.classList.add('is-primary');
      replay.appendChild(replayButton);
      replay.appendChild(text('span', 'pg-tool-line', 'runs the witness through the engine, three lengths, under the library’s caps (backtrack limit 100 000, 50 ms a run).'));
    }
    if (replay.childNodes.length) {
      panel.appendChild(replay);
    }
    if (witness) {
      panel.appendChild(tryRow);
    }
    if (redos.recommendations && redos.recommendations.length) {
      var hints = text('ul', 'pg-tool-list');
      redos.recommendations.slice(0, 3).forEach(function (hint) {
        hints.appendChild(text('li', null, hint));
      });
      panel.appendChild(hints);
    }
  }

  // --- translate ------------------------------------------------------------

  function renderTranspileControls(panel) {
    var row = text('div', 'pg-tool-row');
    var label = text('label', null, 'Target');
    label.htmlFor = 'pg-transpile-target';
    var select = document.createElement('select');
    select.id = 'pg-transpile-target';
    Object.keys(TARGET_LABELS).forEach(function (value) {
      var option = document.createElement('option');
      option.value = value;
      option.textContent = TARGET_LABELS[value];
      option.selected = value === toolSettings.transpile.target;
      select.appendChild(option);
    });
    select.addEventListener('change', function () {
      toolSettings.transpile.target = select.value;
      settingMoved('transpile');
    });
    row.appendChild(label);
    row.appendChild(select);
    if (toolSettings.transpile.target === 'python') {
      var versionLabel = text('label', null, 'Python');
      versionLabel.htmlFor = 'pg-transpile-python';
      var version = document.createElement('select');
      version.id = 'pg-transpile-python';
      PYTHON_VERSIONS.forEach(function (value) {
        var option = document.createElement('option');
        option.value = value;
        option.textContent = value;
        option.selected = value === toolSettings.transpile.pythonVersion;
        version.appendChild(option);
      });
      version.addEventListener('change', function () {
        toolSettings.transpile.pythonVersion = version.value;
        settingMoved('transpile');
      });
      row.appendChild(versionLabel);
      row.appendChild(version);
    }
    panel.appendChild(row);
  }

  function renderTranspile(panel, payload, announceResult) {
    if (payload.error) {
      faultLine(panel, payload.error, 'Nothing to translate: ');
      return;
    }
    if (payload.refusal) {
      panel.appendChild(text('p', 'pg-tool-line is-warn', 'No translation: ' + payload.refusal.message));
      if (announceResult) announce('No translation: ' + payload.refusal.message);
      return;
    }
    var result = payload.transpile;
    if (!result) {
      return;
    }
    var code = text('pre', 'pg-tool-code is-wrapped', result.literal);
    panel.appendChild(code);
    var row = text('div', 'pg-tool-row');
    row.appendChild(toolButton('Copy', function () {
      copyText(result.literal, 'the ' + TARGET_LABELS[toolSettings.transpile.target] + ' pattern', panel);
    }));
    if (result.constructor && result.constructor !== result.literal) {
      var constructorLine = text('span', 'pg-tool-line');
      constructorLine.appendChild(text('code', null, result.constructor));
      row.appendChild(constructorLine);
    }
    panel.appendChild(row);
    var notes = (result.warnings || []).map(function (warning) {
      return { tone: 'is-warn', text: warning };
    }).concat((result.notes || []).map(function (note) {
      return { tone: '', text: note };
    }));
    if (notes.length) {
      var list = text('ul', 'pg-tool-list');
      notes.forEach(function (note) {
        list.appendChild(text('li', note.tone || null, note.text));
      });
      panel.appendChild(list);
    }
    if (announceResult) announce('Translated to ' + TARGET_LABELS[toolSettings.transpile.target] + ', ' + (result.warnings || []).length + ' warning' + ((result.warnings || []).length === 1 ? '' : 's') + ', ' + (result.notes || []).length + ' note' + ((result.notes || []).length === 1 ? '' : 's') + '.');
  }

  // --- samples --------------------------------------------------------------

  function renderSamples(panel, answer, announceResult) {
    var payload = answer.payload;
    if (payload.error) {
      faultLine(panel, payload.error, 'No sample: ');
      return;
    }
    var samples = payload.samples;
    if (!samples) {
      return;
    }
    if (current.version < ANALYSIS_FLOOR_VERSION && !answer.checked) {
      panel.appendChild(text('p', 'pg-tool-hint', 'Checking each sample on PHP ' + current.version + '…'));
      return;
    }
    var chips = function (title, list, matches) {
      panel.appendChild(text('p', 'pg-tool-line', title));
      var ul = text('ul', 'pg-tool-chips');
      ul.setAttribute('aria-label', title);
      if (!list.length) {
        ul.appendChild(text('li', 'pg-tool-hint', 'none found'));
      }
      list.forEach(function (sample) {
        var li = document.createElement('li');
        var chip = text('button', 'pg-tool-chip' + (sample === '' ? ' is-empty' : ''), sample === '' ? '(empty)' : sample);
        chip.type = 'button';
        chip.title = (matches ? 'Matches: ' : 'Does not match: ') + (sample === '' ? 'the empty string' : sample) + ' — put it in the subject field';
        chip.addEventListener('click', function () {
          el.subject.value = sample;
          refreshFields();
          analyzeNow();
        });
        li.appendChild(chip);
        ul.appendChild(li);
      });
      panel.appendChild(ul);
    };
    chips('Matches, drawn at random and checked by the engine', samples.matching, true);
    chips('Does not match', samples.nonMatching, false);
    var row = text('div', 'pg-tool-row');
    var all = samples.matching.concat(samples.nonMatching);
    if (all.length) {
      var append = toolButton('+ all as lines', function () {
        var lines = el.subject.value ? el.subject.value.replace(/\n$/, '').split('\n') : [];
        all.forEach(function (sample) {
          if (lines.indexOf(sample) === -1) {
            lines.push(sample);
          }
        });
        el.subject.value = lines.join('\n');
        refreshFields();
        analyzeNow();
      });
      // The visible words stay in the name, for a voice that says them.
      append.setAttribute('aria-label', 'Add all as lines: every sample appended to the subject, one per line');
      row.appendChild(append);
    }
    if (samples.dropped) {
      row.appendChild(text('span', 'pg-tool-line', samples.dropped + ' sample' + (samples.dropped === 1 ? '' : 's') + ' discarded by the engine.'));
    }
    panel.appendChild(row);
    if (announceResult) announce(samples.matching.length + ' matching sample' + (samples.matching.length === 1 ? '' : 's') + ', ' + samples.nonMatching.length + ' that do' + (samples.nonMatching.length === 1 ? 'es' : '') + ' not match.');
  }

  // --- format ---------------------------------------------------------------

  function renderFormatControls(panel) {
    var row = text('div', 'pg-tool-row');
    var label = text('label', null, 'Width');
    label.htmlFor = 'pg-format-width';
    var width = document.createElement('input');
    width.type = 'number';
    width.id = 'pg-format-width';
    width.min = '1';
    width.max = '200';
    width.value = String(formatWidth());
    width.disabled = toolSettings.format.compact || toolSettings.format.fit;
    width.addEventListener('change', function () {
      var value = parseInt(width.value, 10);
      toolSettings.format.width = value >= 1 && value <= 200 ? value : 80;
      width.value = String(formatWidth());
      settingMoved('format');
    });
    row.appendChild(label);
    row.appendChild(width);
    row.appendChild(toolButton('Fit the field', function () {
      toolSettings.format.fit = !toolSettings.format.fit;
      settingMoved('format');
    }, toolSettings.format.fit));
    row.appendChild(toolButton('Compact', function () {
      toolSettings.format.compact = !toolSettings.format.compact;
      settingMoved('format');
    }, toolSettings.format.compact));
    panel.appendChild(row);
  }

  function renderFormat(panel, payload, announceResult) {
    if (payload.error) {
      faultLine(panel, payload.error, 'Nothing to lay out: ');
      return;
    }
    var result = payload.format;
    if (!result) {
      return;
    }
    if (result.refusal) {
      panel.appendChild(text('p', 'pg-tool-line is-warn', 'Left as written: ' + result.refusal.message));
      if (announceResult) announce('Left as written: ' + result.refusal.message);
      return;
    }
    var code = text('pre', 'pg-tool-code', result.pattern);
    code.tabIndex = 0;
    code.setAttribute('aria-label', toolSettings.format.compact ? 'The pattern on one line' : 'The pattern laid out over lines');
    panel.appendChild(code);
    var row = text('div', 'pg-tool-row');
    row.appendChild(toolButton('Copy', function () {
      copyText(result.pattern, toolSettings.format.compact ? 'the compacted pattern' : 'the laid-out pattern', panel);
    }));
    var lines = result.pattern.split('\n').length;
    row.appendChild(text('span', 'pg-tool-line', result.changed ? (toolSettings.format.compact ? 'Written on one line; it matches what the pattern above matches.' : 'Laid out over ' + lines + ' line' + (lines === 1 ? '' : 's') + ' under x; it matches what the pattern above matches.') : 'The pattern fits the width: unchanged.'));
    panel.appendChild(row);
    if (announceResult) announce(result.changed ? (toolSettings.format.compact ? 'Pattern written on one line.' : 'Pattern laid out over ' + lines + ' lines.') : 'The pattern fits the width: unchanged.');
  }

  // --- step through ---------------------------------------------------------

  // The match as PCRE2 ran it: the real PCRE2 of the engine's release,
  // compiled to WebAssembly, traced through its callout API in a worker of
  // its own (trace-worker.js). The driver reads the delimiters (the
  // trace-input request above); this script resolves what the engine's
  // exact PHP version passes to pcre2_compile() and pcre2_match() from the
  // table php-pcre-options.js holds (loaded after this script, read only when a trace is asked for), and the
  // bundle's manifest names the module of the engine's PCRE2 release. Four
  // witness runs, once per engine, check that the module and the engine
  // agree on the build facts no table can read — the newline convention,
  // \R, \K in a lookbehind, the parens nest limit — before the first trace
  // on that engine; after every trace, the outcome is compared with the
  // engine's own preg_match() on the traced subject (a check request: one
  // match, no JIT — the Matches panel runs preg_match_all(), whose later
  // matches can fail where the first one did not), and a disagreement is
  // printed, never hidden.
  var TRACE_PROTOCOL = 1;        // the trace worker's document
  var TRACE_TIMEOUT_MS = 10000;  // one trace, the module's load included
  var TRACE_STEP_CAP = 20000;    // recorded steps; the match finishes past it
  var TRACE_SUBJECT_CAP = 1000;  // bytes the driver keeps for the trace
  var TRACE_CALLOUT_AUTO = 255;  // the callout number of an automatic callout
  // PCRE2_ERROR_NOMEMORY: the module's own heap ran out, which is a limit
  // of the trace and not an answer PHP gives; the trace has no verdict.
  var PCRE2_ERROR_NOMEMORY = -48;
  // php_pcre.c, when a matches array is passed: a whole match whose end is
  // before its start (\K in a lookahead moved the start past the end) is
  // answered with this warning and false, in every version the page runs.
  var PHP_REVERSED_MATCH = 'Get subpatterns list failed';
  // preg_last_error_msg() by PREG_* code, as php_pcre.c spells them: the
  // check's answer carries the text, the engine's row carries the codes.
  var PHP_ERROR_CODES = {
    'No error': 0,
    'Internal error': 1,
    'Backtrack limit exhausted': 2,
    'Recursion limit exhausted': 3,
    'Malformed UTF-8 characters, possibly incorrectly encoded': 4,
    'The offset did not correspond to the beginning of a valid UTF-8 code point': 5,
    'JIT stack limit exhausted': 6,
  };
  // The witnesses, each run through the engine and through the module:
  // what the compile-time configuration of php-src's bundled PCRE2 decides
  // (NEWLINE_DEFAULT, BSR, PARENS_NEST_LIMIT) and what the PHP row says
  // (EXTRA_ALLOW_LOOKAROUND_BSK), compared outcome by outcome.
  var PROBE_WITNESSES = [
    { name: 'the newline convention (/a$/ on "a\\r\\n")', body: 'a$', modifiers: '', subject: 'a\r\n' },
    { name: '\\R on U+0085 under u', body: '\\R', modifiers: 'u', subject: '\u0085' },
    { name: '\\K in a lookbehind', body: '(?<=a\\K)bc', modifiers: '', subject: 'abc' },
    { name: '251 nested groups', body: '('.repeat(251) + 'a' + ')'.repeat(251), modifiers: '', subject: 'a' },
  ];

  var decoder = new TextDecoder();
  var probes = {};          // exact PHP version -> { engine: [], module: [], status, witness, running }
  var traceWanted = false;  // a Run gesture or a tab opening asked for the trace
  var traceMark = null;     // the current item's byte span in the body, lit in the pattern field

  // --- the trace worker: one request at a time, a watchdog, one retry ------

  var tracer = { worker: null, inflight: null, queue: [], nextId: 1, timer: null, failures: {} };

  function traceWorkerCreate() {
    try {
      var created = new Worker('/assets/js/trace-worker.js' + (assetVersion ? '?v=' + assetVersion : ''), { type: 'module' });
      created.addEventListener('message', onTraceMessage);
      created.addEventListener('error', function (event) {
        if (tracer.inflight) {
          traceFail(tracer.inflight, 'The trace worker failed: ' + (event.message || 'it could not be loaded') + '.');
        }
      });
      return created;
    } catch (error) {
      return null;
    }
  }

  function traceKill() {
    window.clearTimeout(tracer.timer);
    tracer.timer = null;
    if (tracer.worker) {
      tracer.worker.removeEventListener('message', onTraceMessage);
      tracer.worker.terminate();
      tracer.worker = null;
    }
  }

  // One request to the trace worker, answered by a promise. Requests are
  // queued: the worker runs one match at a time and the page waits for it.
  // stale() says the answer the request was made for is no longer the
  // tab's: such a request is dropped when it reaches the front of the
  // queue, and never replayed after a failure.
  function traceAsk(request, key, stale) {
    return new Promise(function (resolve, reject) {
      tracer.queue.push({ request: request, key: key, stale: stale, resolve: resolve, reject: reject, id: 0 });
      traceNext();
    });
  }

  function traceStale(job) {
    return typeof job.stale === 'function' && job.stale();
  }

  function traceNext() {
    while (!tracer.inflight && tracer.queue.length) {
      var job = tracer.queue.shift();
      if (traceStale(job)) {
        job.reject(new Error('The input changed before the trace ran.'));
        continue;
      }
      tracer.inflight = job;
      traceSend(job);
    }
  }

  function traceSend(job) {
    if (!tracer.worker) {
      tracer.worker = traceWorkerCreate();
      if (!tracer.worker) {
        tracer.inflight = null;
        job.reject(new Error('This browser cannot run the trace: it has no module workers.'));
        traceNext();
        return;
      }
    }
    job.id = tracer.nextId++;
    tracer.timer = window.setTimeout(function () {
      traceFail(job, 'The trace did not answer within ' + TRACE_TIMEOUT_MS / 1000 + ' s.');
    }, TRACE_TIMEOUT_MS);
    tracer.worker.postMessage(Object.assign({ id: job.id, bundleUrl: bundleUrl }, job.request));
  }

  // The page's rule, applied to the trace worker: a request that failed is
  // replayed once on a fresh worker (the module loads again); a second
  // failure on the same input stops there, until the input changes.
  function traceFail(job, reason) {
    if (window.console && console.warn) {
      console.warn('playground: trace failed: ' + reason);
    }
    traceKill();
    tracer.failures[job.key] = (tracer.failures[job.key] || 0) + 1;
    if (tracer.failures[job.key] < 2 && !traceStale(job)) {
      setStatus(reason + ' Starting the trace again…', 'warn');
      traceSend(job);
      return;
    }
    tracer.inflight = null;
    job.reject(new Error(reason));
    traceNext();
  }

  function onTraceMessage(event) {
    var message = event.data;
    var job = tracer.inflight;
    if (!message || !job || message.id !== job.id) {
      return;
    }
    if (message.type === 'fatal') {
      traceFail(job, 'The trace failed: ' + message.message + '.');
      return;
    }
    if (message.type !== 'result') {
      return;
    }
    window.clearTimeout(tracer.timer);
    tracer.timer = null;
    tracer.inflight = null;
    if (!message.payload || message.payload.protocol !== TRACE_PROTOCOL) {
      job.reject(new Error('The trace worker answered in a form this page does not know: reload the page.'));
    } else {
      job.resolve(message.payload);
    }
    traceNext();
  }

  // --- what PHP passes: the row, the module, the options -------------------

  // The engine the page shows, by exact version: the analysis names it as
  // its target (the engine itself when the two are one).
  function traceEngine() {
    var payload = current ? current.payload : null;
    if (!payload) {
      return null;
    }
    var engine = payload.target || payload.engine;
    return engine && typeof engine.php === 'string' && typeof engine.pcre === 'string' ? engine : null;
  }

  // The compile and extra options of a modifier string under a row, as
  // PHP's wrapper ORs them; a letter the row does not hold is unknown to
  // that PHP, and the engine's own verdict on it is the one to show.
  function traceOptions(row, modifiers) {
    var has = function (table, letter) {
      return !!table && Object.prototype.hasOwnProperty.call(table, letter);
    };
    var compile = (row.base || 0) >>> 0;
    var extra = (row.extra && row.extra.always ? row.extra.always : 0) >>> 0;
    for (var i = 0; i < modifiers.length; i += 1) {
      var letter = modifiers.charAt(i);
      if (has(row.modifiers, letter)) {
        compile = (compile | row.modifiers[letter]) >>> 0;
      } else if (has(row.extra, letter)) {
        extra = (extra | row.extra[letter]) >>> 0;
      } else {
        return { unknown: letter };
      }
    }
    return { compile: compile, extra: extra, matchLimit: row.matchLimit, depthLimit: row.depthLimit };
  }

  function traceMessage(answer, body, subject, options) {
    return { type: 'trace', release: answer.release, body: body, subject: subject, compileOptions: options.compile, extraOptions: options.extra, matchLimit: options.matchLimit, depthLimit: options.depthLimit, stepCap: TRACE_STEP_CAP };
  }

  // The trace stops here, with a sentence; warn is the tone of a refusal
  // or a failure, the plain tone that of a fact (no row, no module). A
  // bare sentence is shown as it is, without the "No trace:" prefix.
  function traceStop(answer, fault, tone, bare) {
    answer.stage = 'done';
    answer.fault = typeof fault === 'string' ? { message: fault, position: null } : fault;
    answer.tone = tone || '';
    answer.bare = bare === true;
  }

  // The driver's answer in hand: the row for the engine's PHP, the module
  // for its PCRE2, the driver's refusal, the options — each a stop with
  // its sentence when missing; otherwise the probe comes first.
  function prepareTrace(answer) {
    var payload = answer.payload;
    if (payload.error) {
      traceStop(answer, { message: payload.error.message, position: payload.error.position }, 'warn');
      return;
    }
    var engine = traceEngine();
    if (!engine) {
      traceStop(answer, 'The engine has not said which PHP it is: press Run to try again.', 'warn');
      return;
    }
    answer.engine = engine;
    var table = window.PHPRegexPcreOptions;
    var row = table && Object.prototype.hasOwnProperty.call(table, engine.php) ? table[engine.php] : null;
    if (!row) {
      traceStop(answer, 'No trace for PHP ' + engine.php + ' yet.');
      return;
    }
    var module = manifest && manifest.modules ? manifest.modules['pcre2-' + engine.pcre] : null;
    if (!module) {
      traceStop(answer, 'No trace module for PCRE2 ' + engine.pcre + ' yet.');
      return;
    }
    if (typeof payload.refusal === 'string') {
      traceStop(answer, payload.refusal, 'warn');
      return;
    }
    if (!payload.input) {
      traceStop(answer, 'The engine gave no input for the trace: press Run to try again.', 'warn');
      return;
    }
    var options = traceOptions(row, payload.input.modifiers || '');
    if (options.unknown !== undefined) {
      // The engine's own words on the modifier, from the Matches panel.
      traceStop(answer, current.payload.engineError ? 'PHP says ' + current.payload.engineError : 'PHP ' + engine.php + ' does not know the modifier “' + options.unknown + '”.', 'warn');
      return;
    }
    answer.row = row;
    answer.release = module.release || engine.pcre;
    answer.options = options;
    answer.stage = 'probe';
  }

  // --- the probe: four witnesses, once per engine ---------------------------

  function probeFor(answer) {
    var php = answer.engine.php;
    if (!probes[php]) {
      probes[php] = { engine: [], module: [], status: 'pending', witness: '', running: false };
    }
    return probes[php];
  }

  function nextWitness(answers) {
    for (var i = 0; i < PROBE_WITNESSES.length; i += 1) {
      if (answers[i] === undefined) {
        return i;
      }
    }
    return -1;
  }

  function probeCheckRequest(answer, index) {
    var witness = PROBE_WITNESSES[index];
    return { type: 'check', purpose: 'probe', probe: index, php: answer.engine.php, regex: '/' + witness.body + '/' + witness.modifiers, subjects: [witness.subject], version: current.version };
  }

  // One word for an outcome, on either side: the engine's check answer
  // (matched 1, 0, or null with its message) and the module's document.
  function engineOutcome(check) {
    if (!check) {
      return 'no answer';
    }
    if (check.matched === 1) {
      return 'match';
    }
    if (check.matched === 0) {
      return 'no match';
    }
    return typeof check.error === 'string' && /^Compilation failed/.test(check.error) ? 'compile error' : 'error';
  }

  function moduleOutcome(payload) {
    if (payload.compile && payload.compile.error) {
      return 'compile error';
    }
    if (!payload.outcome || payload.outcome.error) {
      return 'error';
    }
    return payload.outcome.rc > 0 ? 'match' : 'no match';
  }

  function onProbeCheck(request, payload) {
    var probe = probes[request.php];
    if (!probe) {
      return;
    }
    if (payload.engine && payload.engine.php !== request.php) {
      // Another PHP answered: the witness gets that as its outcome, which
      // the module never gives, so the probe ends in a named disagreement
      // instead of asking the same witness again.
      probe.engine[request.probe] = 'PHP ' + payload.engine.php + ' answered';
      return;
    }
    probe.engine[request.probe] = engineOutcome(payload.checks && payload.checks[0]);
    // drain() follows every result and asks the next witness, or judges.
  }

  // The engine did not answer a witness: the trace of this input stops,
  // the probe stays unanswered, and Run asks again.
  function probeGaveUp(request, reason) {
    var answer = toolAnswers.trace;
    if (!answer || answer.stage !== 'probe') {
      return;
    }
    var witness = PROBE_WITNESSES[request.probe];
    traceStop(answer, 'The engine did not answer the trace’s check on ' + (witness ? witness.name : 'a witness') + ' (' + reason + ') Press Run to try again.', 'warn');
    answer.failed = true;
    answer.request = request;
    if (activeTool === 'trace') {
      renderTool('trace', true);
    }
  }

  function probeModule(answer, probe) {
    var index = nextWitness(probe.module);
    var witness = PROBE_WITNESSES[index];
    probe.running = true;
    traceAsk(traceMessage(answer, witness.body, witness.subject, traceOptions(answer.row, witness.modifiers)), 'probe\u0000' + answer.engine.php + '\u0000' + index, function () {
      return toolAnswers.trace !== answer;
    }).then(function (payload) {
      probe.running = false;
      probe.module[index] = moduleOutcome(payload);
      // The next witness, or the judgement and the trace, through drain().
      drain();
    }, function (error) {
      probe.running = false;
      // A newer answer is not stopped by an older one's failure, and may be
      // waiting on this probe: drain() lets it ask its own witness.
      if (toolAnswers.trace === answer) {
        traceGaveUp(error.message);
      } else {
        drain();
      }
    });
  }

  function judgeProbe(probe) {
    for (var i = 0; i < PROBE_WITNESSES.length; i += 1) {
      if (probe.engine[i] !== probe.module[i]) {
        probe.status = 'failed';
        probe.witness = PROBE_WITNESSES[i].name + ' (the module says ' + probe.module[i] + ', PHP says ' + probe.engine[i] + ')';
        return;
      }
    }
    probe.status = 'ok';
  }

  // --- the flow: input, probe, trace, self-check ----------------------------

  // Moves the current trace one step on, from wherever it stands: a probe
  // witness to ask the engine (queued for drain()), one to run on the
  // module, the judgement, then the trace itself. Idempotent: drain() and
  // every trace event call it, and it does nothing for an answer the input
  // has left behind.
  function advanceTrace() {
    var answer = toolAnswers.trace;
    if (!answer || !current || current.prerendered || !toolIsCurrent('trace')) {
      return;
    }
    if (answer.stage === 'probe') {
      var probe = probeFor(answer);
      if (probe.status === 'pending') {
        var ask = nextWitness(probe.engine);
        if (ask !== -1) {
          // Queued for drain(), which sends it when the engine is free;
          // never sent from here, so one request is in flight at a time.
          if (!pendingTool && !(inflight && inflight.request.purpose === 'probe')) {
            pendingTool = probeCheckRequest(answer, ask);
          }
          return;
        }
        if (nextWitness(probe.module) !== -1) {
          if (!probe.running) {
            probeModule(answer, probe);
          }
          return;
        }
        judgeProbe(probe);
      }
      if (probe.status === 'failed') {
        traceStop(answer, 'The trace module disagrees with this PHP on ' + probe.witness + '; no trace on PHP ' + answer.engine.php + '.', 'warn', true);
        if (activeTool === 'trace') {
          renderTool('trace', true);
        }
        return;
      }
      answer.stage = 'trace';
      if (activeTool === 'trace') {
        renderTool('trace', false);
      }
    }
    if (answer.stage === 'trace' && !answer.asked) {
      answer.asked = true;
      runTrace(answer);
      return;
    }
    if (answer.stage === 'done' && answer.selfCheck && answer.selfCheck.pending) {
      // The self-check's engine run: queued for drain() like a witness,
      // one request in flight at a time, never sent from here.
      if (!pendingTool && !(inflight && inflight.request.purpose === 'selfcheck')) {
        pendingTool = selfCheckRequest(answer);
      }
    }
  }

  // The engine's own preg_match() on the traced subject, with the trace's
  // key as the chain's, so the answer finds its trace.
  function selfCheckRequest(answer) {
    var input = answer.payload.input;
    return { type: 'check', purpose: 'selfcheck', php: answer.engine.php, regex: current.regex, subject: current.subject, subjects: [input.subject], version: current.version, chainKey: answer.key };
  }

  function onSelfCheck(request, payload) {
    var answer = toolAnswers.trace;
    if (!answer || answer.key !== request.chainKey || answer.stage !== 'done' || !answer.selfCheck || !answer.selfCheck.pending) {
      return;
    }
    var check = payload.checks && payload.checks[0] ? payload.checks[0] : null;
    if (payload.engine && payload.engine.php !== answer.engine.php) {
      answer.selfCheck = { agrees: null, note: 'PHP ' + payload.engine.php + ' answered the check, not the PHP ' + answer.engine.php + ' the trace was run for' };
    } else if (!check) {
      answer.selfCheck = { agrees: null, note: 'the engine gave no answer to compare' };
    } else {
      answer.selfCheck = selfCheck(answer, check);
    }
    showSelfCheck(answer);
  }

  // The engine did not answer the self-check (twice, or with a fault):
  // the trace stands, the label says it was not compared.
  function selfCheckGaveUp(request, reason) {
    var answer = toolAnswers.trace;
    if (!answer || answer.key !== request.chainKey || !answer.selfCheck || !answer.selfCheck.pending) {
      return;
    }
    answer.selfCheck = { agrees: null, note: 'the engine did not answer the check (' + reason.replace(/\.$/, '') + ')' };
    // Kept so the next Run clears its failure count: the same input asks
    // the same check again, and must be allowed to.
    answer.selfCheckRequest = request;
    showSelfCheck(answer);
  }

  // The verdict reaches the tab: the label under the list is rewritten in
  // place when it is on screen (the visitor's step stays), else the tab
  // is drawn again when it opens.
  function showSelfCheck(answer) {
    var box = answer.labelBox;
    if (box && box.isConnected && activeTool === 'trace') {
      fillTraceLabel(box, answer);
    } else if (activeTool === 'trace') {
      renderTool('trace', false);
    }
    if (activeTool === 'trace') {
      var check = answer.selfCheck;
      announce(check.agrees === false ? 'The engine’s answer disagrees with the trace.' : (check.agrees ? 'The engine’s answer agrees with the trace.' : 'The trace was not compared: ' + check.note + '.'));
    }
  }

  function runTrace(answer) {
    var input = answer.payload.input;
    traceAsk(traceMessage(answer, input.body, input.subject, answer.options), answer.key, function () {
      return toolAnswers.trace !== answer || !toolIsCurrent('trace');
    }).then(function (payload) {
      if (toolAnswers.trace !== answer) {
        return;
      }
      if (!toolIsCurrent('trace')) {
        // The input moved while PCRE2 ran: the answer is nobody's, and
        // the same input, asked again, starts from the driver.
        delete toolAnswers.trace;
        return;
      }
      answer.trace = payload;
      answer.stage = 'done';
      answer.step = 0;
      answer.bodyBytes = encoder.encode(input.body);
      answer.subjectBytes = encoder.encode(input.subject);
      answer.subjectUnits = unitTable(input.subject);
      answer.captures = carryCaptures(payload.steps || []);
      answer.selfCheck = selfCheckBeforeEngine(answer);
      if (activeTool === 'trace') {
        renderTool('trace', true);
      }
      // The engine's own answer, when the self-check needs one: queued
      // through drain(), which sends it when the engine is free.
      drain();
    }, function (error) {
      // A trace dropped because the input moved stops nothing: the current
      // input has its own answer coming.
      if (toolAnswers.trace === answer && toolIsCurrent('trace')) {
        traceGaveUp(error.message);
      }
    });
  }

  // The trace worker gave up twice on this input: the tab says so, the
  // title bar says Stopped, and the next Run asks again.
  function traceGaveUp(reason) {
    var answer = toolAnswers.trace;
    if (!answer || answer.stage === 'done') {
      return;
    }
    traceStop(answer, reason + ' Press Run to try again.', 'warn');
    answer.failed = true;
    setStatus(reason + ' The trace stopped on this input; press Run to try again.', 'error');
    setState('Stopped', 'error');
    announce('The trace stopped on this input.');
    if (activeTool === 'trace') {
      renderTool('trace', true);
    }
  }

  // The captures of every step: a step carries its ovector only when it
  // changed, so each one reads the last that was sent.
  function carryCaptures(steps) {
    var captures = [];
    var last = [];
    steps.forEach(function (step) {
      if (Array.isArray(step.cap)) {
        last = step.cap;
      }
      captures.push(last);
    });
    return captures;
  }

  // Byte offset -> UTF-16 index of a string, as the engine's unitOf table
  // does for the pattern; an inner byte of a character maps to its start.
  function unitTable(string) {
    var table = [];
    var unit = 0;
    while (unit < string.length) {
      var point = string.codePointAt(unit);
      var width = point < 0x80 ? 1 : (point < 0x800 ? 2 : (point < 0x10000 ? 3 : 4));
      for (var b = 0; b < width; b += 1) {
        table.push(unit);
      }
      unit += point >= 0x10000 ? 2 : 1;
    }
    table.push(string.length);
    return table;
  }

  function unitAt(table, byteOffset) {
    return table[Math.max(0, Math.min(byteOffset, table.length - 1))];
  }

  // PCRE2's error code as PHP's pcre_handle_exec_error() maps it, from the
  // engine's row: a PREG_* code the Matches panel's text also names.
  function phpErrorCode(row, code) {
    var errors = row.errors || {};
    if (code === -47) {
      return errors.matchLimit;
    }
    if (code === -53) {
      return errors.depthLimit;
    }
    if (code === -36) {
      return errors.badUtfOffset;
    }
    if (code <= -3 && code >= -23) {
      return errors.badUtf;
    }
    return errors.internal;
  }

  function quoted(value) {
    return value === null || value === undefined ? 'unset' : '“' + value + '”';
  }

  function bytesHex(bytes) {
    var out = '';
    for (var i = 0; i < bytes.length; i += 1) {
      out += (bytes[i] < 16 ? '0' : '') + bytes[i].toString(16);
    }
    return out;
  }

  // The whole-match pair of a trace that matched, when its end is before
  // its start: \K in a lookahead moved the start past the end. PHP answers
  // such a match with a warning and false when a matches array is passed.
  function reversedMatch(outcome) {
    var pair = outcome && outcome.rc > 0 && Array.isArray(outcome.ovector) ? outcome.ovector[0] : null;
    return Array.isArray(pair) && pair[1] < pair[0] ? pair : null;
  }

  // What the self-check can say before the engine answers: nothing to
  // compare (the trace read a cut subject; the module ran out of memory,
  // which is a limit of the trace and no verdict), or the wait for the
  // engine's own run — selfCheck() below, once the check has answered.
  function selfCheckBeforeEngine(answer) {
    var input = answer.payload.input;
    var outcome = answer.trace.outcome;
    if (input.subjectTruncated) {
      return { agrees: null, note: 'the trace reads the first ' + TRACE_SUBJECT_CAP.toLocaleString('en-US').replace(',', ' ') + ' bytes; the engine’s answer is for the whole subject' };
    }
    if (outcome && outcome.error && outcome.error.code === PCRE2_ERROR_NOMEMORY) {
      return { agrees: null, note: 'the trace module ran out of memory' };
    }
    return { agrees: null, pending: true, note: 'waiting for the engine’s own answer' };
  }

  // One sentence for the engine's check answer: what preg_match() said.
  function engineSentence(check) {
    if (check.matched === 1) {
      return 'PHP found a match.';
    }
    if (check.matched === 0) {
      return 'PHP found no match.';
    }
    return 'PHP says: ' + check.error + '.';
  }

  // The trace's outcome against the engine's own preg_match() on the same
  // pattern, the same subject, the same engine (the check's answer: one
  // match, no JIT, offsets, unset groups as null). Captures are read as
  // PHP writes them (groups below rc, an unset one as null) and compared
  // by text and byte offset; a whole match whose end is before its start
  // is what PHP refuses with “Get subpatterns list failed”; an error is
  // compared by PHP's code. The result is a verdict and its sentences.
  function selfCheck(answer, check) {
    var trace = answer.trace;
    var release = answer.release;
    var compileError = trace.compile && trace.compile.error ? trace.compile.error : null;
    var engineRefused = check.matched === null && typeof check.error === 'string' && /^Compilation failed/.test(check.error);
    if (compileError && engineRefused) {
      return { agrees: true, note: 'both refuse the pattern' };
    }
    if (compileError) {
      return { agrees: false, details: ['PCRE2 ' + release + ' in the trace refused the pattern: ' + compileError.message + ' at offset ' + compileError.offset + '.', 'PHP compiled it. ' + engineSentence(check)] };
    }
    if (engineRefused) {
      return { agrees: false, details: ['PHP refused the pattern: ' + check.error, 'PCRE2 ' + release + ' in the trace compiled it.'] };
    }
    var outcome = trace.outcome || {};
    var reversed = reversedMatch(outcome);
    if (reversed) {
      // PHP's rule comes first: offsets[1] < offsets[0] is a warning and
      // false, never a match written to the array.
      var reversedSays = 'The trace’s match ends before it starts (byte ' + reversed[0] + ' to byte ' + reversed[1] + '), which PHP refuses with “' + PHP_REVERSED_MATCH + '”.';
      if (check.matched === null && check.error === PHP_REVERSED_MATCH) {
        return { agrees: true, note: 'the match ends before it starts, and PHP refuses it with “' + PHP_REVERSED_MATCH + '”' };
      }
      return { agrees: false, details: [reversedSays, engineSentence(check)] };
    }
    var traceCode = outcome.error ? phpErrorCode(answer.row, outcome.error.code) : 0;
    var engineText = check.matched === null ? String(check.error) : 'No error';
    var engineCode = check.matched === null ? (PHP_ERROR_CODES[engineText] !== undefined ? PHP_ERROR_CODES[engineText] : -1) : 0;
    var traceSays = outcome.error ? 'The trace stopped with PCRE2 error ' + outcome.error.code + ' (' + outcome.error.message + '), which PHP reports as error ' + traceCode + '.' : 'The trace ended with no error.';
    var engineSays = 'PHP says: ' + engineText + (engineCode > 0 ? ' (error ' + engineCode + ').' : '.');
    if (traceCode !== engineCode) {
      return { agrees: false, details: [traceSays, engineSays] };
    }
    if (traceCode !== 0) {
      return { agrees: true, note: 'both stop with ' + engineText };
    }
    var traceMatched = outcome.rc > 0;
    var engineMatched = check.matched === 1;
    if (traceMatched !== engineMatched) {
      return { agrees: false, details: [traceMatched ? 'The trace found a match.' : 'The trace found no match.', engineSentence(check)] };
    }
    if (!traceMatched) {
      return { agrees: true, note: 'no match on either side' };
    }
    var groups = check.groups && typeof check.groups === 'object' ? check.groups : {};
    var ovector = Array.isArray(outcome.ovector) ? outcome.ovector : [];
    var highest = outcome.rc;
    Object.keys(groups).forEach(function (key) {
      if (/^\d+$/.test(key)) {
        highest = Math.max(highest, Number(key) + 1);
      }
    });
    var details = [];
    for (var group = 0; group < highest; group += 1) {
      // PHP's rule: groups from rc on are unset; an unset one is null in
      // the check's answer (PREG_UNMATCHED_AS_NULL), and so here.
      var pair = group < outcome.rc && Array.isArray(ovector[group]) ? ovector[group] : null;
      var traceBytes = pair ? answer.subjectBytes.slice(pair[0], pair[1]) : null;
      var traceText = traceBytes ? decoder.decode(traceBytes) : null;
      var engineGroup = Array.isArray(groups[String(group)]) ? groups[String(group)] : null;
      var engineText2 = engineGroup ? engineGroup[0] : null;
      // A group that cut a character travels as hex beside its text: the
      // bytes are compared, not the replacement characters.
      var sameText = engineGroup && engineGroup.length === 3 ? traceBytes !== null && bytesHex(traceBytes) === engineGroup[2] : traceText === engineText2;
      if (!sameText) {
        details.push('Group ' + group + ': the trace holds ' + quoted(traceText) + ', PHP wrote ' + quoted(engineText2) + '.');
      } else if (pair && engineGroup && pair[0] !== engineGroup[1]) {
        details.push('Group ' + group + ' starts at byte ' + pair[0] + ' in the trace, at byte ' + engineGroup[1] + ' for PHP.');
      }
    }
    if (details.length) {
      return { agrees: false, details: details };
    }
    return { agrees: true, note: 'the same match, the same groups' };
  }

  // --- rendering the tab -----------------------------------------------------

  function clearTraceMark() {
    if (traceMark && current) {
      traceMark = null;
      renderPatternMirror(current.payload, current.regex, lintFor === current.regex ? lastLint : null);
    }
    traceMark = null;
  }

  function traceLabel(answer) {
    return 'PCRE2 ' + answer.release + ', compiled with auto-callouts, options as PHP ' + answer.engine.php + ' passes them';
  }

  // The honest line under the list: which PCRE2 traced, whose options were
  // applied, and whether the engine's own answer agrees. A disagreement
  // stands in red with both answers, never hidden.
  function renderTraceLabel(panel, answer) {
    var box = text('div', 'pg-trace-check');
    fillTraceLabel(box, answer);
    answer.labelBox = box;
    panel.appendChild(box);
  }

  // The label's four states: waiting for the engine's own answer, not
  // compared (with the reason), agrees, disagrees (red, both answers).
  function fillTraceLabel(box, answer) {
    while (box.firstChild) {
      box.removeChild(box.firstChild);
    }
    var check = answer.selfCheck;
    if (check && check.pending) {
      box.appendChild(text('p', 'pg-tool-line pg-trace-label', traceLabel(answer) + ' — ' + check.note + '…'));
      return;
    }
    if (!check || check.agrees === null) {
      box.appendChild(text('p', 'pg-tool-line pg-trace-label', traceLabel(answer) + (check ? ' — not compared: ' + check.note + '.' : '.')));
      return;
    }
    if (check.agrees) {
      box.appendChild(text('p', 'pg-tool-line pg-trace-label', traceLabel(answer) + ' — the engine’s answer agrees' + (check.note ? ' (' + check.note + ')' : '') + '.'));
      return;
    }
    box.appendChild(text('p', 'pg-tool-line is-error pg-trace-label', traceLabel(answer) + ' — the engine’s answer disagrees:'));
    var list = text('ul', 'pg-tool-list is-error');
    check.details.forEach(function (detail) {
      list.appendChild(text('li', null, detail));
    });
    box.appendChild(list);
  }

  function traceItemText(answer, step) {
    if (!step.len) {
      return 'End of pattern';
    }
    return decoder.decode(answer.bodyBytes.slice(step.p, step.p + step.len));
  }

  function traceOutcomeLine(answer) {
    var trace = answer.trace;
    var steps = trace.steps || [];
    var count = typeof trace.steps_total === 'number' ? trace.steps_total : steps.length;
    var stepsWord = count + (count === 1 ? ' step' : ' steps');
    var outcome = trace.outcome || {};
    if (outcome.error && outcome.error.code === PCRE2_ERROR_NOMEMORY) {
      // The module's heap, not a verdict: PHP's own library has the
      // host's memory and no PREG_* code names this.
      return 'The trace module ran out of memory after ' + stepsWord + '; no verdict.';
    }
    if (outcome.error) {
      var code = phpErrorCode(answer.row, outcome.error.code);
      var name = '';
      Object.keys(PHP_ERROR_CODES).forEach(function (label) {
        if (PHP_ERROR_CODES[label] === code) {
          name = label;
        }
      });
      return 'Stopped after ' + stepsWord + ': ' + outcome.error.message + (name ? ' (PHP: ' + name + ').' : '.');
    }
    var reversed = reversedMatch(outcome);
    if (reversed) {
      return 'The match ends before it starts (byte ' + reversed[0] + ' to byte ' + reversed[1] + ': \\K moved the start past the end); PHP refuses such a match with “' + PHP_REVERSED_MATCH + '”.';
    }
    if (outcome.rc > 0 && Array.isArray(outcome.ovector) && outcome.ovector[0]) {
      var pair = outcome.ovector[0];
      return 'Matched ' + quoted(decoder.decode(answer.subjectBytes.slice(pair[0], pair[1]))) + ' at byte ' + pair[0] + ' after ' + stepsWord + '.';
    }
    return 'No match after ' + stepsWord + '.';
  }

  function renderTrace(panel, answer, announceResult) {
    if (answer.stage !== 'done') {
      clearTraceMark();
      if (answer.stage === 'probe') {
        var probe = probeFor(answer);
        var done = probe.engine.filter(function (value) { return value !== undefined; }).length + probe.module.filter(function (value) { return value !== undefined; }).length;
        panel.appendChild(text('p', 'pg-tool-hint', 'Checking the trace module against PHP ' + answer.engine.php + ' (' + Math.min(done, 2 * PROBE_WITNESSES.length) + ' of ' + 2 * PROBE_WITNESSES.length + ' witness runs)…'));
      } else {
        panel.appendChild(text('p', 'pg-tool-hint', answer.stage === 'trace' ? 'Tracing on PCRE2 ' + answer.release + '…' : 'Reading the pattern…'));
      }
      return;
    }
    if (answer.fault) {
      clearTraceMark();
      if (answer.tone === 'warn') {
        faultLine(panel, answer.fault, answer.bare ? '' : 'No trace: ');
      } else {
        panel.appendChild(text('p', 'pg-tool-hint', answer.fault.message));
      }
      if (announceResult) announce((answer.bare ? '' : 'No trace: ') + answer.fault.message);
      return;
    }
    var trace = answer.trace;
    var input = answer.payload.input;
    if (trace.compile && trace.compile.error) {
      clearTraceMark();
      panel.appendChild(text('p', 'pg-tool-line is-warn', 'PCRE2 ' + answer.release + ' refused the pattern: ' + trace.compile.error.message + ' (offset ' + trace.compile.error.offset + ').'));
      renderTraceLabel(panel, answer);
      if (announceResult) announce('PCRE2 refused the pattern: ' + trace.compile.error.message);
      return;
    }
    var steps = trace.steps || [];
    var view = { items: [], current: null };
    answer.view = view;

    panel.appendChild(text('p', 'pg-tool-line pg-trace-outcome', traceOutcomeLine(answer)));
    if (trace.truncated) {
      // The record stops at the step cap, or earlier when the recorded steps
      // outgrow their byte cap (hundreds of groups rewritten at every step).
      panel.appendChild(text('p', 'pg-tool-line is-warn', 'Recording stopped after ' + steps.length.toLocaleString('en-US').replace(/,/g, ' ') + ' steps' + (steps.length < TRACE_STEP_CAP ? ' (the record reached its size cap)' : '') + '; the outcome is the engine’s.'));
    }
    if (input.subjectTruncated) {
      panel.appendChild(text('p', 'pg-tool-line is-warn', 'The trace reads the first ' + TRACE_SUBJECT_CAP.toLocaleString('en-US').replace(',', ' ') + ' bytes of the subject.'));
    }
    if (!steps.length) {
      panel.appendChild(text('p', 'pg-tool-hint', 'PCRE2 made no step: its start-of-match optimizations found no position worth trying.'));
      renderTraceLabel(panel, answer);
      if (announceResult) announce('Traced: no step. ' + traceOutcomeLine(answer));
      return;
    }

    // The slider and the two buttons: one hand for the mouse, one for the
    // keys (the slider's own arrows, Home and End move it).
    var nav = text('div', 'pg-tool-row pg-trace-nav');
    var label = text('label', null, 'Step');
    label.htmlFor = 'pg-trace-step';
    var slider = document.createElement('input');
    slider.type = 'range';
    slider.id = 'pg-trace-step';
    slider.min = '0';
    slider.max = String(steps.length - 1);
    slider.value = String(answer.step);
    slider.addEventListener('input', function () {
      setTraceStep(answer, parseInt(slider.value, 10) || 0);
    });
    var count = text('span', 'pg-trace-count');
    nav.appendChild(label);
    nav.appendChild(slider);
    nav.appendChild(count);
    nav.appendChild(toolButton('← Previous', function () {
      setTraceStep(answer, answer.step - 1);
    }));
    nav.appendChild(toolButton('Next →', function () {
      setTraceStep(answer, answer.step + 1);
    }));
    view.slider = slider;
    view.count = count;
    panel.appendChild(nav);

    // The subject with its two cursors: where the attempt started, where
    // PCRE2 stands; the status line under the list says the same in words.
    var subjectLine = text('pre', 'pg-trace-subject');
    subjectLine.setAttribute('aria-hidden', 'true');
    view.subject = subjectLine;
    panel.appendChild(subjectLine);

    var list = text('ol', 'pg-trace-steps');
    list.tabIndex = 0;
    list.setAttribute('aria-label', 'Steps; the arrow keys, Home and End move between them');
    steps.forEach(function (step, index) {
      var item = text('li', 'pg-trace-step' + (step.flags & 2 ? ' is-backtrack' : ''));
      item.setAttribute('data-step', String(index));
      if (step.flags & 1) {
        item.appendChild(text('span', 'pg-trace-attempt', 'New match attempt'));
      }
      if (step.flags & 2) {
        item.appendChild(text('span', 'pg-trace-backtrack', 'Backtracked before this item'));
      }
      var line = text('span', 'pg-trace-try', 'Try ');
      line.appendChild(text('code', null, traceItemText(answer, step)));
      line.appendChild(text('span', 'pg-trace-at', ' at ' + step.s));
      if (typeof step.n === 'number' && step.n !== TRACE_CALLOUT_AUTO) {
        line.appendChild(text('span', 'pg-trace-at', ' (callout ' + step.n + ')'));
      }
      item.appendChild(line);
      view.items.push(item);
      list.appendChild(item);
    });
    list.addEventListener('click', function (event) {
      var item = event.target.closest('li[data-step]');
      if (item) {
        setTraceStep(answer, Number(item.getAttribute('data-step')));
      }
    });
    list.addEventListener('keydown', function (event) {
      if (event.altKey || event.ctrlKey || event.metaKey) {
        return;
      }
      var target = -1;
      switch (event.key) {
        case 'ArrowRight': case 'ArrowDown': target = answer.step + 1; break;
        case 'ArrowLeft': case 'ArrowUp': target = answer.step - 1; break;
        case 'Home': target = 0; break;
        case 'End': target = steps.length - 1; break;
        default: return;
      }
      event.preventDefault();
      setTraceStep(answer, target);
    });
    view.list = list;
    panel.appendChild(list);

    var status = text('p', 'pg-trace-status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    view.status = status;
    panel.appendChild(status);

    var captures = text('div', 'pg-trace-captures');
    view.captures = captures;
    panel.appendChild(captures);

    renderTraceLabel(panel, answer);
    setTraceStep(answer, answer.step);
    if (announceResult) {
      var check = answer.selfCheck;
      announce('Traced: ' + steps.length + (steps.length === 1 ? ' step. ' : ' steps. ') + traceOutcomeLine(answer) + (check && check.agrees === false ? ' The engine disagrees.' : (check && check.agrees ? ' The engine agrees.' : '')));
    }
  }

  // The current step: the list's aria-current, the slider, the cursors in
  // the subject, the captures as PCRE2 held them, the item lit in the
  // pattern field and the node under it lit in the tree.
  function setTraceStep(answer, index) {
    var view = answer.view;
    var steps = answer.trace ? answer.trace.steps : null;
    if (!view || !steps || !steps.length) {
      return;
    }
    index = Math.max(0, Math.min(index, steps.length - 1));
    answer.step = index;
    var step = steps[index];
    if (view.current) {
      view.current.removeAttribute('aria-current');
      view.current.classList.remove('is-current');
    }
    view.current = view.items[index];
    view.current.setAttribute('aria-current', 'step');
    view.current.classList.add('is-current');
    if (view.list.scrollHeight > view.list.clientHeight) {
      view.current.scrollIntoView({ block: 'nearest' });
    }
    view.slider.value = String(index);
    view.count.textContent = (index + 1) + ' / ' + steps.length;

    clear(view.subject);
    view.subject.appendChild(traceSubjectFragment(answer, step));

    var item = traceItemText(answer, step);
    var where = 'item ' + (index + 1) + ' of ' + steps.length + ' at subject byte ' + step.s + (typeof step.start === 'number' && step.start !== step.s ? ' (the attempt started at byte ' + step.start + ')' : '');
    view.status.textContent = (step.flags & 1 ? 'New match attempt. ' : '') + (step.flags & 2 ? 'Backtracked before this item. ' : '') + 'Try ' + (step.len ? quoted(item) : item) + ': ' + where + '.';

    clear(view.captures);
    view.captures.appendChild(traceCapturesFragment(answer, index));

    // The item in the pattern field, and the node around it in the tree.
    traceMark = { start: step.p, end: step.p + step.len };
    var node = traceNodeAt(step.p);
    if (node !== currentNode) {
      setCurrentNode(node);
    } else if (current) {
      renderPatternMirror(current.payload, current.regex, lintFor === current.regex ? lastLint : null);
    }
  }

  // The deepest node of the tree that holds a body offset: the nodes are
  // in pre-order, so the last one around the offset is the deepest.
  function traceNodeAt(offset) {
    var found = -1;
    nodes.forEach(function (entry, index) {
      if (typeof entry.start === 'number' && entry.start <= offset && offset < entry.end) {
        found = index;
      }
    });
    return found;
  }

  // The subject, the start of the attempt and the current position as two
  // marks in it; one mark when they coincide. The marks are for the eye,
  // the status line says the same.
  function traceSubjectFragment(answer, step) {
    var subject = answer.payload.input.subject;
    var fragment = document.createDocumentFragment();
    var atStart = typeof step.start === 'number' ? unitAt(answer.subjectUnits, step.start) : -1;
    var atNow = unitAt(answer.subjectUnits, step.s);
    var cuts = [];
    if (atStart >= 0 && atStart !== atNow) {
      cuts.push({ at: atStart, className: 'pg-trace-cursor is-start', title: 'start of the attempt, byte ' + step.start });
    }
    cuts.push({ at: atNow, className: 'pg-trace-cursor is-now' + (atStart === atNow ? ' is-start' : ''), title: 'subject byte ' + step.s });
    cuts.sort(function (a, b) {
      return a.at - b.at;
    });
    var from = 0;
    cuts.forEach(function (cut) {
      if (cut.at > from) {
        fragment.appendChild(document.createTextNode(subject.slice(from, cut.at)));
        from = cut.at;
      }
      var mark = text('mark', cut.className);
      mark.title = cut.title;
      fragment.appendChild(mark);
    });
    if (from < subject.length) {
      fragment.appendChild(document.createTextNode(subject.slice(from)));
    }
    fragment.appendChild(document.createTextNode('\n'));
    return fragment;
  }

  // The captures at a step, as PCRE2 held them: groups from 1, the pairs
  // below capture_top; pair 0 is not a capture during a callout.
  function traceCapturesFragment(answer, index) {
    var fragment = document.createDocumentFragment();
    var pairs = answer.captures[index] || [];
    var names = Object.assign({}, current.payload.matches && current.payload.matches.names ? current.payload.matches.names : {}, current.payload.groupNames || {});
    if (pairs.length <= 1) {
      fragment.appendChild(text('p', 'pg-tool-hint', 'No group captured yet.'));
      return fragment;
    }
    var table = document.createElement('table');
    table.className = 'pg-trace-table';
    var caption = text('caption', null, 'Captures at this step');
    table.appendChild(caption);
    var head = document.createElement('thead');
    var headRow = document.createElement('tr');
    ['Group', 'Text', 'Bytes'].forEach(function (title) {
      var cell = text('th', null, title);
      cell.scope = 'col';
      headRow.appendChild(cell);
    });
    head.appendChild(headRow);
    table.appendChild(head);
    var body = document.createElement('tbody');
    for (var group = 1; group < pairs.length; group += 1) {
      var row = document.createElement('tr');
      var pair = Array.isArray(pairs[group]) ? pairs[group] : null;
      var name = text('th', null, names[String(group)] ? names[String(group)] + ' (' + group + ')' : String(group));
      name.scope = 'row';
      row.appendChild(name);
      var value = text('td', null);
      if (pair) {
        value.appendChild(text('code', null, decoder.decode(answer.subjectBytes.slice(pair[0], pair[1]))));
      } else {
        value.appendChild(text('span', 'pg-trace-unset', 'unset'));
      }
      row.appendChild(value);
      row.appendChild(text('td', 'pg-trace-bytes', pair ? pair[0] + '–' + pair[1] : '—'));
      body.appendChild(row);
    }
    table.appendChild(body);
    fragment.appendChild(table);
    return fragment;
  }

  // The tab strip's hands: a click opens a tab; the arrow keys, Home and
  // End move between tabs and open the one they land on. A modified key
  // stays the browser's (Alt+Left is Back).
  Array.prototype.forEach.call(el.toolTabs, function (tab, index, tabs) {
    tab.addEventListener('click', function () {
      stopStroll();
      activateTool(tab.getAttribute('data-tool'), false);
    });
    tab.addEventListener('keydown', function (event) {
      if (event.altKey || event.ctrlKey || event.metaKey) {
        return;
      }
      var target = -1;
      switch (event.key) {
        case 'ArrowRight': target = (index + 1) % tabs.length; break;
        case 'ArrowLeft': target = (index - 1 + tabs.length) % tabs.length; break;
        case 'Home': target = 0; break;
        case 'End': target = tabs.length - 1; break;
        default: return;
      }
      event.preventDefault();
      stopStroll();
      activateTool(tabs[target].getAttribute('data-tool'), true);
    });
  });

  // The strip stays at the top of the answers as they scroll under it; a
  // shadow under it says something is there. Its height, one row of tabs
  // or two, is what a row brought into view must clear.
  el.results.addEventListener('scroll', function () {
    el.tabStrip.classList.toggle('is-scrolled', el.results.scrollTop > 0);
  }, { passive: true });
  function measureStrip() {
    el.results.style.setProperty('--pg-strip-h', el.tabStrip.offsetHeight + 'px');
  }
  window.addEventListener('resize', measureStrip);
  if (window.ResizeObserver) {
    // A badge, or a font that arrives late, can wrap the strip on its own.
    new ResizeObserver(measureStrip).observe(el.tabStrip);
  }
  measureStrip();

  // --- the tree's two voices ----------------------------------------------

  // The tree, or the same pattern in plain English: two radios, the arrow
  // keys move between them. A first visit hears the tree; the prose is
  // remembered only once chosen, and forgotten when the tree is chosen back.
  var VOICE_STORAGE_KEY = 'playground-voice';
  var voice = 'tree';
  try {
    if (localStorage.getItem(VOICE_STORAGE_KEY) === 'prose') {
      voice = 'prose';
    }
  } catch (error) {/* the choice simply does not persist */}

  function setVoice(next, focus, remember) {
    voice = next === 'prose' ? 'prose' : 'tree';
    Array.prototype.forEach.call(el.voices, function (option) {
      var on = option.getAttribute('data-voice') === voice;
      option.setAttribute('aria-checked', on ? 'true' : 'false');
      option.tabIndex = on ? 0 : -1;
      if (on && focus) {
        option.focus();
      }
    });
    el.treeWrap.hidden = voice !== 'tree';
    el.explain.hidden = voice !== 'prose';
    if (!remember) {
      return;
    }
    try {
      if (voice === 'prose') {
        localStorage.setItem(VOICE_STORAGE_KEY, 'prose');
      } else {
        localStorage.removeItem(VOICE_STORAGE_KEY);
      }
    } catch (error) {/* the choice simply does not persist */}
  }

  Array.prototype.forEach.call(el.voices, function (option) {
    option.addEventListener('click', function () {
      stopStroll();
      setVoice(option.getAttribute('data-voice'), false, true);
    });
    option.addEventListener('keydown', function (event) {
      if (event.altKey || event.ctrlKey || event.metaKey || ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(event.key) === -1) {
        return;
      }
      event.preventDefault();
      stopStroll();
      setVoice(voice === 'tree' ? 'prose' : 'tree', true, true);
    });
  });
  var announceEngineError = false; // after "Try it": the engine's own words, once

  // --- the URL hash --------------------------------------------------------

  function readHash() {
    var hash = window.location.hash.replace(/^#/, '');
    if (!hash) {
      return null;
    }
    var params = new URLSearchParams(hash);
    if (!params.has('pattern')) {
      return null;
    }
    var subject = params.get('subject') || '';
    var cut = false;
    if (byteLength(subject) > SUBJECT_CAP) {
      subject = new TextDecoder().decode(encoder.encode(subject).slice(0, SUBJECT_CAP)).replace(/\uFFFD$/, '');
      cut = true;
    }
    return {
      regex: params.get('pattern') || '',
      subject: subject,
      cut: cut,
      version: -1 !== VERSIONS.indexOf(params.get('php')) ? params.get('php') : chosen,
    };
  }

  function writeHash(request) {
    var params = new URLSearchParams();
    params.set('pattern', request.regex);
    params.set('subject', request.subject);
    params.set('php', request.version);
    try {
      window.history.replaceState(null, '', '#' + params.toString());
    } catch (error) {/* a browser that refuses keeps the URL as it is */}
  }

  // --- wiring --------------------------------------------------------------

  VERSIONS.forEach(function (value) {
    var option = document.createElement('option');
    option.value = value;
    option.textContent = 'PHP ' + value;
    option.selected = value === chosen;
    el.version.appendChild(option);
  });
  el.version.addEventListener('change', function () {
    chosen = el.version.value;
    try {
      localStorage.setItem(STORAGE_KEY, chosen);
    } catch (error) {/* the choice simply does not persist */}
    analyzeNow();
  });

  [el.pattern, el.subject].forEach(function (area) {
    area.addEventListener('focus', function () {
      stopStroll();
      if (area === el.pattern) {
        el.patternEditor.classList.remove('is-untouched');
      }
    });
    area.addEventListener('input', function () {
      stopStroll();
      // Before the debounce fires: the field must read as it is typed,
      // and a keystroke ends the caret that said it could be.
      if (area === el.pattern) {
        el.patternEditor.classList.remove('is-live');
        el.patternEditor.classList.remove('is-untouched');
      }
      refreshFields();
      schedule();
    });
    area.addEventListener('scroll', function () {
      area.previousElementSibling.scrollTop = area.scrollTop;
      area.previousElementSibling.scrollLeft = area.scrollLeft;
    });
  });
  el.run.addEventListener('click', analyzeNow);
  // Ctrl/⌘+Enter runs from anywhere in the window, the fields included.
  root.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
      event.preventDefault();
      analyzeNow();
    }
  });

  Array.prototype.forEach.call(el.examples, function (chip) {
    chip.addEventListener('click', function () {
      el.pattern.value = chip.getAttribute('data-regex') || '';
      el.subject.value = chip.getAttribute('data-subject') || '';
      refreshFields();
      analyzeNow();
    });
  });

  // --- the walk through the tree ------------------------------------------

  // Before a hand reaches for the tree, a light walks it top to bottom,
  // one node at a time, the way a pointer would: the tree is something to
  // point at, and the walk says so without a word. It lights the row and
  // the piece of the pattern, nothing the live regions would announce. It
  // runs on the sample alone, only while the tree is on screen, never
  // under a reduced-motion preference, and the first gesture on the tree
  // or in a field ends it for good.
  var STROLL_STEP_MS = 650;
  var STROLL_REST_MS = 1400;
  var stroll = { timer: 0, at: -1, ended: false, visible: true, observer: null };

  function strollLight(index) {
    if (stroll.at >= 0 && nodes[stroll.at]) {
      nodes[stroll.at].button.classList.remove('is-visiting');
    }
    stroll.at = index;
    Array.prototype.forEach.call(el.treePattern.querySelectorAll('mark'), function (mark) {
      mark.classList.toggle('is-visiting', index >= 0 && Number(mark.getAttribute('data-index')) === index);
    });
    if (index >= 0 && nodes[index]) {
      nodes[index].button.classList.add('is-visiting');
      revealInTree(nodes[index].button);
    }
  }

  // The answers scroll on their own on a wide screen: the lit row stays
  // in view there, without moving the page.
  function revealInTree(item) {
    var box = el.results;
    if (box.scrollHeight <= box.clientHeight) {
      return;
    }
    var row = item.firstElementChild.getBoundingClientRect();
    var frame = box.getBoundingClientRect();
    // The strip of tabs is pinned over the top of the answers: a row
    // under it is not in view.
    var top = frame.top + el.tabStrip.offsetHeight;
    if (row.top < top) {
      box.scrollTop += row.top - top;
    } else if (row.bottom > frame.bottom) {
      box.scrollTop += row.bottom - frame.bottom;
    }
  }

  function strollStep() {
    stroll.timer = 0;
    if (stroll.ended || !stroll.visible || document.hidden || !nodes.length) {
      return;
    }
    var next = stroll.at + 1;
    if (next >= nodes.length) {
      // The walk rests at the top before it goes again.
      strollLight(-1);
      stroll.timer = window.setTimeout(strollStep, STROLL_REST_MS);
      return;
    }
    strollLight(next);
    stroll.timer = window.setTimeout(strollStep, STROLL_STEP_MS);
  }

  function strollResume() {
    if (!stroll.ended && !stroll.timer && stroll.visible && !document.hidden) {
      stroll.timer = window.setTimeout(strollStep, STROLL_STEP_MS);
    }
  }

  function startStroll() {
    var motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    // A walk is for a tree on screen: another tab open, or the prose, and
    // there is nothing to walk.
    if (stroll.ended || (motion && motion.matches) || !nodes.length || activeTool !== 'tree' || voice !== 'tree') {
      return;
    }
    // A preference set while the walk runs ends it as well.
    if (motion && motion.addEventListener) {
      motion.addEventListener('change', function (event) {
        if (event.matches) {
          stopStroll();
        }
      });
    }
    if ('IntersectionObserver' in window) {
      stroll.observer = new IntersectionObserver(function (entries) {
        stroll.visible = entries.some(function (entry) {
          return entry.isIntersecting;
        });
        strollResume();
      }, { threshold: 0.25 });
      stroll.observer.observe(el.treeBox);
    }
    document.addEventListener('visibilitychange', strollResume);
    strollResume();
  }

  function stopStroll() {
    if (stroll.ended) {
      return;
    }
    stroll.ended = true;
    window.clearTimeout(stroll.timer);
    stroll.timer = 0;
    if (stroll.observer) {
      stroll.observer.disconnect();
    }
    document.removeEventListener('visibilitychange', strollResume);
    strollLight(-1);
  }

  // --- the tree's hands ---------------------------------------------------

  // The deepest item under the pointer, the focus or the click is the one
  // meant: items nest, and an event on a child reaches its parents too.
  ['mouseover', 'focusin', 'click'].forEach(function (type) {
    el.treeNodes.addEventListener(type, function (event) {
      var item = event.target.closest('[role="treeitem"]');
      if (item) {
        stopStroll();
        setCurrentNode(Number(item.getAttribute('data-index')));
      }
    });
  });
  ['touchstart', 'keydown'].forEach(function (type) {
    el.treeBox.addEventListener(type, stopStroll, { passive: true });
  });
  // The arrow keys walk the tree: down and up in reading order, right to
  // the first child, left to the parent, Home and End to the ends.
  el.treeNodes.addEventListener('keydown', function (event) {
    var item = event.target.closest('[role="treeitem"]');
    if (!item || !nodes.length) {
      return;
    }
    var index = Number(item.getAttribute('data-index'));
    var target = -1;
    switch (event.key) {
      case 'ArrowDown': target = Math.min(index + 1, nodes.length - 1); break;
      case 'ArrowUp': target = Math.max(index - 1, 0); break;
      case 'Home': target = 0; break;
      case 'End': target = nodes.length - 1; break;
      case 'ArrowRight': target = childNodes(nodes[index].node).length ? index + 1 : index; break;
      case 'ArrowLeft': target = parentIndex(index); break;
      default: return;
    }
    event.preventDefault();
    if (target >= 0 && target !== index) {
      nodes[target].button.focus();
    }
  });

  function parentIndex(index) {
    var item = nodes[index].button.parentNode.closest('[role="treeitem"]');
    return item ? Number(item.getAttribute('data-index')) : index;
  }
  el.treePattern.addEventListener('mouseover', function (event) {
    var mark = event.target.closest('mark');
    if (mark) {
      stopStroll();
      var index = Number(mark.getAttribute('data-index'));
      setCurrentNode(index);
      if (nodes[index]) {
        nodes[index].button.scrollIntoView({ block: 'nearest' });
      }
    }
  });

  window.addEventListener('resize', refreshFields);

  setVoice(voice, false, false);
  activateTool(activeTool, false);

  // --- first paint ---------------------------------------------------------

  var fromHash = readHash();
  if (fromHash) {
    el.pattern.value = fromHash.regex;
    el.subject.value = fromHash.subject;
    chosen = fromHash.version;
    el.version.value = chosen;
    refreshFields();
    setStatus(fromHash.cut ? 'The subject in this link was cut at ' + SUBJECT_CAP + ' bytes. Loading the engine…' : 'Loading the engine for the pattern in this link…', 'busy');
    el.diagnostics.appendChild(text('li', 'pg-diag is-empty', 'Loading…'));
    setState('Loading…', 'busy');
    analyzeNow();
  } else if (sample && sample.analyze && sample.default) {
    el.pattern.value = sample.default.regex;
    el.subject.value = sample.default.subject;
    refreshFields();
    render(sample.analyze, { regex: sample.default.regex, subject: sample.default.subject, version: chosen }, true);
    renderLint(sample.lint ? sample.lint.lint : null);
    startStroll();
  } else {
    refreshFields();
  }
})();
