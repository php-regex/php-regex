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
  // A repository's page of findings names that repository.
  var pageRepo = root.getAttribute('data-findings-repo') || '';

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
    matchSummary: root.querySelector('#pg-match-summary'),
    subjectMatches: root.querySelector('.pg-subject-matches'),
    matchDetails: root.querySelector('.pg-match-details'),
    version: root.querySelector('#pg-version'),
    run: root.querySelector('.pg-run'),
    runKey: root.querySelector('.pg-run-key'),
    examples: root.querySelectorAll('.pg-example'),
    status: root.querySelector('.pg-status'),
    explain: root.querySelector('.pg-explain'),
    diagnostics: root.querySelector('.pg-diagnostics'),
    lintNote: root.querySelector('.pg-lint-note'),
    facts: root.querySelector('.pg-facts'),
    state: root.querySelector('.pg-state'),
    stateWord: root.querySelector('.pg-state-word'),
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
    examplesList: root.querySelector('.pg-examples'),
    findingsBrowse: root.querySelector('.pg-findings-browse'),
    findingsDoor: root.querySelector('.pg-findings-browse > summary'),
    findingsCount: root.querySelector('.pg-findings-count'),
    findingsFilter: root.querySelector('.pg-findings-filter'),
    body: root.querySelector('.pg-body'),
    findingsTree: root.querySelector('.pg-findings-tree'),
    findingsEmpty: root.querySelector('.pg-findings-empty'),
    textField: root.querySelector('.pg-field-text'),
    findingCard: root.querySelector('.pg-finding-card'),
    findingNotice: root.querySelector('.pg-finding-notice'),
    findingAvatar: root.querySelector('.pg-finding-avatar'),
    findingRepo: root.querySelector('.pg-finding-repo'),
    findingTarget: root.querySelector('.pg-finding-target'),
    permalink: root.querySelector('.pg-snippet-permalink'),
    snippetFile: root.querySelector('.pg-snippet-file'),
    snippet: root.querySelector('.pg-snippet'),
    findingIssues: root.querySelector('.pg-finding-issues'),
    rewrite: root.querySelector('.pg-rewrite'),
    rewriteDiff: root.querySelector('.pg-rewrite-diff'),
    witness: root.querySelector('.pg-witness'),
    outcome: root.querySelector('.pg-finding-outcome'),
    findingCopy: root.querySelector('.pg-finding-copy'),
    shareCopy: root.querySelector('.pg-share-copy'),
    shareNative: root.querySelector('.pg-share-native'),
    findingLeft: root.querySelector('.pg-finding-left'),
    findingLeftText: root.querySelector('.pg-finding-left-text'),
    findingBack: root.querySelector('.pg-finding-back'),
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
  var bootNotice = '';      // a warning met during the boot, kept over its stages
  var linkNotice = null;    // { text, subject }: a warning about the text a link brought
  var current = null;       // the last payload rendered: { payload, regex, subject, version, prerendered }
  var activeFinding = null; // the finding the window shows, read-only, or null in the sandbox
  var lastFinding = null;   // "slug#id" of the last finding shown: lit in the list
  var pageTouched = false;  // a gesture came before a repository page's finding arrived
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
    if (!message && linkWarning()) {
      message = linkWarning();
      tone = 'warn';
    }
    el.status.textContent = message || '';
    el.status.className = 'pg-status' + (tone ? ' is-' + tone : '');
  }

  // A link whose subject was cut warns about the text it brought.
  function noteCutLink(link) {
    if (link.cut) {
      linkNotice = { text: 'The subject in this link was cut at ' + SUBJECT_CAP + ' bytes.', subject: link.subject };
    }
  }

  // The link's warning holds while the text it is about is the one in the
  // field: a keystroke, an example or a finding moves it on.
  function linkWarning() {
    return linkNotice && el.subject.value === linkNotice.subject ? linkNotice.text : '';
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

  // The light and the word in the title bar: what the window is doing
  // right now. A phone shows the light alone; its title still names it.
  function setState(word, tone) {
    el.stateWord.textContent = word;
    el.state.title = word;
    el.state.className = 'pg-state' + (tone ? ' is-' + tone : '');
  }

  // The loader over the window's body: shown from the moment an engine
  // is asked for until it is ready, naming the stage the status line
  // names. It is for the eye alone; the live regions already speak.
  function showLoader(stage) {
    el.loaderStage.textContent = stage;
    el.loader.hidden = false;
    root.classList.add('is-booting');
  }

  function hideLoader() {
    el.loader.hidden = true;
    root.classList.remove('is-booting');
    bootNotice = '';
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
    var filled = area === el.subject && window.matchMedia('(min-width: 1024px)').matches;
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
      setState('Ready', 'ok');
      setStatus(reason + ' The engine could not judge the pattern for backtracking; press Run to try again.', 'warn');
      updateBadges();
      drain();
      return;
    }
    if (failures[key] < 2) {
      bootNotice = reason + ' Starting the engine again…';
      setStatus(bootNotice, 'warn');
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
      setState('Ready', 'ok');
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
      // The card names the stage; the status line keeps a warning the
      // boot met, and otherwise says the stage under the card's clip.
      var notice = bootNotice || linkWarning();
      setStatus(notice || stage, notice ? 'warn' : 'stage');
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
      setState('Ready', 'ok');
    } else if (request.type === 'match') {
      if (current && current.regex === request.regex && current.subject === request.subject) {
        matchFor = requestKey(request);
        current.payload.analysisEngine = current.payload.analysisEngine || current.payload.engine;
        current.payload.engine = payload.engine || current.payload.engine;
        current.payload.engineError = payload.engineError;
        current.payload.matches = payload.matches;
        current.payload.input = payload.input || current.payload.input;
        renderCaption(current.payload, false);
        // A keystroke or another PHP version since the request dropped the
        // marks: the answer to the old input is kept, not drawn; the new
        // input's answer draws.
        if (fieldsRead(request)) {
          renderSubjectMarks(current.payload, request.subject);
        }
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
    // An analysis may carry its match sets: drawn only while the fields
    // still say what was asked, like a match answer.
    if (fieldsRead(request)) {
      renderSubjectMarks(payload, request.subject);
    }
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
      // Nothing ran yet: the light in the title bar says the sample, the
      // bar says what judged it.
      if (payload.target) {
        el.facts.appendChild(chip('sample judged for', 'PHP ' + payload.target.php + ' · PCRE2 ' + payload.target.pcre));
      }
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
  // The line after the field's label counts them, in sight: an empty match
  // underlines nothing, and the marks stop at the engine's cap. Under the
  // field, a folded disclosure holds each match one by one, its groups
  // under it — read in sight once opened, and by a screen reader, which
  // the hidden mirror does not serve. The live line speaks the count.
  function renderSubjectMarks(payload, subject) {
    var matches = payload.matches;
    var ranges = [];
    var done = !!(matches && !matches.pending && matches.sets);
    clear(el.subjectMatches);
    if (done) {
      var names = Object.assign({}, matches.names || {}, payload.groupNames || {});
      matches.sets.forEach(function (set, i) {
        var whole = set['0'];
        if (whole) {
          ranges.push({ start: whole[2], end: whole[2] + whole[0].length, className: 'pg-mark-match' + (i % 2 ? ' is-alt' : ''), index: i });
          el.subjectMatches.appendChild(matchItem(set, i, subject, names));
        }
      });
    }
    // PHP's warning leaves no match: a pattern refused at compile time, or
    // a match it could not finish (/(?=a\K)/ on "a"). The line says so
    // beside the field once the live line has spoken; the engine's words
    // are in the diagnostics, where the pattern field's tint points.
    el.matchSummary.textContent = done ? matchSummary(matches) : (!matches && payload.engineError ? 'PHP could not complete the match' : '');
    el.matchDetails.hidden = !el.subjectMatches.firstChild;
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
      announce('PHP could not complete the match: ' + payload.engineError);
    }
  }

  // How many matches, in words: the marks' share when the engine capped the
  // sets, and the empty ones named, since no mark shows them. The empty
  // ones are counted in the sets returned: capped, they are counted among
  // the underlined, and the line says "of them".
  function matchSummary(matches) {
    if (matches.count === 0) {
      return matches.error && matches.error !== 'No error' ? 'No match: ' + matches.error + '.' : 'No match';
    }
    var empty = matches.sets.filter(function (set) {
      return set['0'] && set['0'][0] === '';
    }).length;
    var capped = matches.shown < matches.count;
    var line = matches.count === 1 ? '1 match' : matches.count + ' matches';
    if (capped) {
      line += ', the first ' + matches.shown + ' underlined';
    }
    if (empty) {
      line += matches.count === 1 ? ', empty' : ', ' + empty + (capped ? ' of them empty' : ' empty');
    }
    return line;
  }

  // One match: its number, its text, where it starts and ends in the field,
  // then one row per group that took part — the group by its name and its
  // number (or its number alone), what it caught, where. Positions count
  // characters: the driver's offsets are UTF-16 units, the marks' measure,
  // and a reader counts an emoji as one. Pointing at a match lights its
  // mark in the field.
  function matchItem(set, i, subject, names) {
    var whole = set['0'];
    var item = text('li', 'pg-match');
    var head = text('div', 'pg-match-head');
    head.appendChild(text('span', 'pg-match-n', '#' + (i + 1)));
    head.appendChild(caught(whole[0]));
    var start = Array.from(subject.slice(0, whole[2])).length;
    head.appendChild(text('span', 'pg-match-at', 'characters ' + start + ' to ' + (start + Array.from(whole[0]).length)));
    item.appendChild(head);
    var groups = Object.keys(set).filter(function (key) {
      return key !== '0';
    });
    if (groups.length) {
      var list = text('dl', 'pg-groups');
      groups.forEach(function (key) {
        var capture = set[key];
        list.appendChild(text('dt', 'pg-group-name', names[key] ? names[key] + ' (#' + key + ')' : '#' + key));
        var value = text('dd', 'pg-group-value');
        value.appendChild(caught(capture[0]));
        list.appendChild(value);
        list.appendChild(text('dd', 'pg-group-at pg-match-at', 'at ' + Array.from(subject.slice(0, capture[2])).length));
      });
      item.appendChild(list);
    }
    item.addEventListener('mouseenter', function () {
      lightMatch(i, true);
    });
    item.addEventListener('mouseleave', function () {
      lightMatch(i, false);
    });
    return item;
  }

  // What a match or a group caught: the text in the mono voice, or the
  // word "empty" where it caught nothing, since an empty code reads blank.
  function caught(value) {
    return value === '' ? text('span', 'pg-match-empty', 'empty') : text('code', 'pg-match-value', value);
  }

  function lightMatch(index, on) {
    var marks = el.subjectMirror.querySelectorAll('mark[data-index="' + index + '"]');
    Array.prototype.forEach.call(marks, function (mark) {
      mark.classList.toggle('is-current', on);
    });
  }

  // Whether the fields, and the PHP version, still say what a request
  // asked: an answer to anything else has nothing to draw in the field.
  function fieldsRead(request) {
    return el.pattern.value === request.regex && el.subject.value === request.subject && request.version === chosen;
  }

  // A keystroke makes the answer stale: the count, the list and the marks
  // go at once, the text stays in the mirror, and the answer to the new
  // input draws them again.
  function dropSubjectMarks() {
    el.matchSummary.textContent = '';
    clear(el.subjectMatches);
    el.matchDetails.hidden = true;
    clear(el.subjectMirror);
    el.subjectMirror.appendChild(markRanges(el.subject.value, []));
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

  // One line, split into its voices: a branch keyword, a labelled kind
  // ("Anchor: …", a quiet tag that names the tree's word for it), a bare
  // quoted literal, or plain prose. Each sentence is shown as the library
  // wrote it.
  function explainVoices(content, head) {
    var keyword = content.match(/^(IF|THEN|ELSE|EITHER|OR)(?:\s+(.*))?$/);
    var labeled = keyword ? null : content.match(/^([A-Z][A-Za-z]*(?: [A-Za-z]+)*): (.+)$/);
    if (keyword) {
      head.appendChild(text('span', 'pg-explain-keyword', keyword[1]));
      if (keyword[2]) {
        head.appendChild(document.createTextNode(' '));
        head.appendChild(text('span', 'pg-explain-text', keyword[2]));
      }
    } else if (labeled) {
      head.appendChild(text('span', 'pg-explain-kind', labeled[1]));
      head.appendChild(document.createTextNode(' '));
      head.appendChild(text('span', 'pg-explain-text', labeled[2]));
    } else if (/^['"].*['"]$/.test(content)) {
      head.appendChild(text('span', 'pg-explain-literal', content));
    } else {
      head.appendChild(text('span', 'pg-explain-text', content));
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
    if (left === 'trace' && tool !== left) {
      // A trace that was playing stops with the tab that is left.
      traceStopPlay();
    }
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
      if (on) {
        scrollTabIntoView(tab);
      }
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
    if (tool === 'trace') {
      // The panel is drawn again: a trace that was playing stops with it.
      traceStopPlay();
    }
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
      answer.marks = traceMarks(payload.steps || [], answer.captures);
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
    // The line's light, one per state: waiting, not compared, agrees,
    // disagrees.
    box.className = 'pg-trace-check is-' + (check && check.pending ? 'pending' : (!check || check.agrees === null ? 'off' : (check.agrees ? 'ok' : 'error')));
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

  // The outcome's light: green for a match, red where PCRE2 stopped on an
  // error or PHP refuses the match, amber where the module ran out of
  // memory and there is no verdict, the quiet ink for no match.
  function traceOutcomeTone(answer) {
    var outcome = answer.trace.outcome || {};
    if (outcome.error) {
      return outcome.error.code === PCRE2_ERROR_NOMEMORY ? 'warn' : 'error';
    }
    if (reversedMatch(outcome)) {
      return 'error';
    }
    return outcome.rc > 0 && Array.isArray(outcome.ovector) && outcome.ovector[0] ? 'ok' : 'none';
  }

  // --- the debugger around the trace ----------------------------------------

  // The trace is a recording, so the debugger travels it both ways: one
  // step back or on, and to the previous or the next stop — a backtrack,
  // the start of a match attempt, a capture that moved, whichever are
  // ticked. The icons are drawn in the header's dialect, a 16-unit box and
  // square strokes: a filled shape runs, an arrow to a dot takes one step.
  var SVG_NS = 'http://www.w3.org/2000/svg';
  var TRACE_ICONS = {
    rewind: 'M13 3v10M10 3 3 8l7 5z',
    back: 'M14.5 8H6M9.5 4l-4 4 4 4M1.4 7.4h1v1.2h-1z',
    step: 'M1.5 8H10M6.5 4l4 4-4 4M13.6 7.4h1v1.2h-1z',
    resume: 'M3 3v10M6 3l7 5-7 5z',
    play: 'M4.5 3l8 5-8 5z',
    pause: 'M4 3.5h1.5v9H4zM10.5 3.5H12v9h-1.5z'
  };
  var TRACE_CONTROLS = [
    { act: 'rewind', label: 'Rewind to the previous stop', hint: 'Shift+←', keys: 'Shift+ArrowLeft', icon: 'rewind', solid: true },
    { act: 'back', label: 'Step back', hint: '←', keys: 'ArrowLeft', icon: 'back', solid: false },
    { act: 'step', label: 'Step', hint: '→', keys: 'ArrowRight', icon: 'step', solid: false },
    { act: 'resume', label: 'Resume to the next stop', hint: 'Shift+→', keys: 'Shift+ArrowRight', icon: 'resume', solid: true }
  ];
  var TRACE_PLAY = { act: 'play', label: 'Play', hint: 'Space', keys: 'Space', icon: 'play', solid: true };
  var TRACE_STOPS = [
    { kind: 'backtrack', label: 'Every backtrack', hint: 'A step PCRE2 reached after backtracking — a flag on the step, at least one backtrack since the step before, never a count of them. The number beside counts the flagged steps.' },
    { kind: 'attempt', label: 'Every new attempt', hint: 'The first step of a match attempt: PCRE2 starts over from another place in the text.' },
    { kind: 'capture', label: 'Every capture change', hint: 'A step at which a group holds another capture than at the step before.' }
  ];
  // The stops Resume and Rewind travel between: the two events PCRE2
  // flags from the start, a capture that moved once it is ticked. The
  // choice holds for the page's life, from one trace to the next.
  var traceStops = { backtrack: true, attempt: true, capture: false };
  // Playback: a whole trace in about twelve seconds whatever its length —
  // a short one a readable step at a time, a long one striding over steps
  // rather than running for minutes.
  var TRACE_PLAY_MS = 12000;
  var TRACE_PLAY_SLOWEST_MS = 400;
  var TRACE_PLAY_FASTEST_MS = 30;
  var tracePlay = { timer: null, answer: null };
  // The chart's box, in its own units: the positions are drawn between top
  // and base, the backtracks ticked along the floor under them.
  var TRACE_CHART = { width: 1000, top: 8, base: 82, floor: 100, columns: 500 };
  // The rows of the steps list stand in chunks of this many: a screen or
  // two of rows, laid out and skipped by the browser as one.
  var TRACE_CHUNK_ROWS = 100;

  function traceIcon(name, solid) {
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', solid ? 'icon is-solid' : 'icon');
    svg.setAttribute('viewBox', '0 0 16 16');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', TRACE_ICONS[name]);
    svg.appendChild(path);
    return svg;
  }

  // A button of the debugger: an icon, a name for a voice, a tooltip that
  // teaches its key.
  function traceButton(control) {
    var button = text('button', 'pg-trace-btn');
    button.type = 'button';
    button.setAttribute('data-act', control.act);
    button.setAttribute('aria-label', control.label);
    button.title = control.label + ' (' + control.hint + ')';
    button.setAttribute('aria-keyshortcuts', control.keys);
    button.appendChild(traceIcon(control.icon, control.solid));
    return button;
  }

  // A pane, as a debugger's sidebar stacks them: a head that names it and
  // the group, and a note at the head's far end over the column it reads.
  function tracePane(name, title, note) {
    var pane = text('div', 'pg-trace-pane is-' + name);
    var head = text('div', 'pg-trace-pane-head');
    var label = text('span', null, title);
    label.id = 'pg-trace-pane-' + name;
    head.appendChild(label);
    if (note) {
      head.appendChild(text('span', 'pg-trace-pane-note', note));
    }
    pane.setAttribute('role', 'group');
    pane.setAttribute('aria-labelledby', label.id);
    pane.appendChild(head);
    return pane;
  }

  // A line of the execution point, named in its gutter.
  function traceLine(label, line) {
    var row = text('div', 'pg-trace-line');
    row.setAttribute('data-label', label);
    row.appendChild(line);
    return row;
  }

  // A count as the page writes them: thousands parted by a space.
  function spaced(count) {
    return count.toLocaleString('en-US').replace(/,/g, ' ');
  }

  // What the trace holds besides its steps, read once: where each match
  // attempt starts, where PCRE2 backtracked, where a group's capture
  // moved, and the attempt every step belongs to.
  function traceMarks(steps, captures) {
    var marks = { backtrack: [], attempt: [], capture: [], moved: {}, attemptOf: [] };
    var attempt = 0;
    steps.forEach(function (step, index) {
      if (step.flags & 1) {
        attempt += 1;
        marks.attempt.push(index);
      }
      if (step.flags & 2) {
        marks.backtrack.push(index);
      }
      if (index > 0 && captures[index] !== captures[index - 1] && movedGroups(captures[index - 1], captures[index]).length) {
        marks.capture.push(index);
        marks.moved[index] = true;
      }
      marks.attemptOf.push(Math.max(attempt, 1));
    });
    return marks;
  }

  // The groups whose capture differs between two steps, from 1: pair 0 is
  // not a capture during a callout.
  function movedGroups(before, after) {
    var moved = [];
    var top = Math.max(before.length, after.length);
    for (var group = 1; group < top; group += 1) {
      var was = Array.isArray(before[group]) ? before[group] : null;
      var is = Array.isArray(after[group]) ? after[group] : null;
      if ((was === null) !== (is === null) || (was && (was[0] !== is[0] || was[1] !== is[1]))) {
        moved.push(group);
      }
    }
    return moved;
  }

  function traceIsStop(answer, index) {
    var step = answer.trace.steps[index];
    return Boolean((traceStops.backtrack && step.flags & 2) || (traceStops.attempt && step.flags & 1) || (traceStops.capture && answer.marks.moved[index]));
  }

  // The nearest stop before or after the current step; an end of the trace
  // when none is left that way, as a program runs to its exit.
  function traceSeek(answer, direction) {
    var last = answer.trace.steps.length - 1;
    var index = answer.step + direction;
    while (index > 0 && index < last && !traceIsStop(answer, index)) {
      index += direction;
    }
    return Math.max(0, Math.min(index, last));
  }

  // One door for every gesture, a button, a key or a row: playback ends at
  // the first of them, for the hand has taken over.
  function traceAct(answer, act) {
    if (act === 'play') {
      traceTogglePlay(answer);
      return;
    }
    traceStopPlay();
    switch (act) {
      case 'rewind': setTraceStep(answer, traceSeek(answer, -1)); break;
      case 'back': setTraceStep(answer, answer.step - 1); break;
      case 'step': setTraceStep(answer, answer.step + 1); break;
      case 'resume': setTraceStep(answer, traceSeek(answer, 1)); break;
      case 'first': setTraceStep(answer, 0); break;
      case 'last': setTraceStep(answer, answer.trace.steps.length - 1); break;
      default: break;
    }
  }

  // Played from its last step, the trace starts over, as a film does. The
  // status line is not live while the trace runs: a voice would read every
  // step of a run it cannot follow.
  function traceTogglePlay(answer) {
    if (tracePlay.timer) {
      traceStopPlay();
      return;
    }
    var view = answer.view;
    var steps = answer.trace.steps;
    if (!view || steps.length < 2) {
      return;
    }
    if (answer.step >= steps.length - 1) {
      setTraceStep(answer, 0);
    }
    var tick = Math.max(TRACE_PLAY_FASTEST_MS, Math.min(TRACE_PLAY_SLOWEST_MS, TRACE_PLAY_MS / steps.length));
    var stride = Math.max(1, Math.ceil(steps.length * tick / TRACE_PLAY_MS));
    view.status.setAttribute('aria-live', 'off');
    syncTracePlay(view, true);
    tracePlay.answer = answer;
    tracePlay.timer = window.setInterval(function () {
      if (!view.root.isConnected || toolAnswers.trace !== answer) {
        traceStopPlay();
        return;
      }
      setTraceStep(answer, answer.step + stride);
      if (answer.step >= steps.length - 1) {
        traceStopPlay();
      }
    }, tick);
  }

  // The timer ends here, wherever playback is stopped from; the status
  // line is live again and says the step the run stopped on.
  function traceStopPlay() {
    if (!tracePlay.timer) {
      return;
    }
    window.clearInterval(tracePlay.timer);
    tracePlay.timer = null;
    var answer = tracePlay.answer;
    tracePlay.answer = null;
    if (answer && answer.view && answer.view.root.isConnected) {
      answer.view.status.setAttribute('aria-live', 'polite');
      syncTracePlay(answer.view, false);
      setTraceStep(answer, answer.step);
    }
  }

  function syncTracePlay(view, playing) {
    var label = playing ? 'Pause' : TRACE_PLAY.label;
    view.play.setAttribute('aria-label', label);
    view.play.title = label + ' (' + TRACE_PLAY.hint + ')';
    view.play.classList.toggle('is-playing', playing);
    view.play.querySelector('path').setAttribute('d', TRACE_ICONS[playing ? 'pause' : 'play']);
  }

  // The match's flight, drawn once as the timeline's ground: the position
  // in the text at every step, a stair that climbs as PCRE2 consumes and
  // drops where it backtracked. Past the chart's columns a column holds
  // the lowest and the highest position of its steps, so a sawtooth of
  // backtracks stays a band and never a line that hides it. Hairlines part
  // the match attempts; ticks along the floor mark the backtracks.
  function traceChart(answer) {
    var steps = answer.trace.steps;
    var last = steps.length - 1;
    var columns = Math.min(steps.length, TRACE_CHART.columns);
    var peak = 1;
    var lows = [];
    var highs = [];
    steps.forEach(function (step, index) {
      var column = Math.min(columns - 1, Math.floor(index * columns / steps.length));
      peak = Math.max(peak, step.s);
      lows[column] = lows[column] === undefined ? step.s : Math.min(lows[column], step.s);
      highs[column] = highs[column] === undefined ? step.s : Math.max(highs[column], step.s);
    });
    var round = function (value) {
      return Math.round(value * 10) / 10;
    };
    var x = function (column) {
      return columns > 1 ? round(column * TRACE_CHART.width / (columns - 1)) : 0;
    };
    var y = function (position) {
      return round(TRACE_CHART.base - position * (TRACE_CHART.base - TRACE_CHART.top) / peak);
    };
    var line = 'M0 ' + y(highs[0]);
    for (var column = 1; column < columns; column += 1) {
      line += 'H' + x(column) + 'V' + y(highs[column]);
    }
    line += 'H' + TRACE_CHART.width;
    var band = '';
    if (lows.some(function (low, at) { return low < highs[at]; })) {
      band = line + 'V' + y(lows[columns - 1]);
      for (var back = columns - 1; back > 0; back -= 1) {
        band += 'H' + x(back) + 'V' + y(lows[back - 1]);
      }
      band += 'H0Z';
    }
    // One stroke per place on the chart, however many steps share it.
    var strokes = function (indexes, from, to) {
      var seen = {};
      var path = '';
      indexes.forEach(function (index) {
        var at = last ? Math.round(index * TRACE_CHART.width / last) : 0;
        if (!seen[at]) {
          seen[at] = true;
          path += 'M' + at + ' ' + from + 'V' + to;
        }
      });
      return path;
    };
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', 'pg-trace-chart');
    svg.setAttribute('viewBox', '0 0 ' + TRACE_CHART.width + ' ' + TRACE_CHART.floor);
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    [
      ['is-area', line + 'V' + TRACE_CHART.base + 'H0Z'],
      ['is-band', band],
      ['is-line', line],
      ['is-attempt', strokes(answer.marks.attempt.filter(function (index) { return index > 0; }), 0, TRACE_CHART.base)],
      ['is-capture', strokes(answer.marks.capture, 0, TRACE_CHART.top)],
      ['is-backtrack', strokes(answer.marks.backtrack, TRACE_CHART.floor, TRACE_CHART.base + 6)]
    ].forEach(function (layer) {
      if (!layer[1]) {
        return;
      }
      var path = document.createElementNS(SVG_NS, 'path');
      path.setAttribute('class', layer[0]);
      path.setAttribute('d', layer[1]);
      svg.appendChild(path);
    });
    return svg;
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

    // What the record says of itself before its steps: where it stops
    // short, where the text was cut for it.
    var notes = [];
    if (trace.truncated) {
      // The record stops at the step cap, or earlier when the recorded steps
      // outgrow their byte cap (hundreds of groups rewritten at every step).
      notes.push(text('p', 'pg-tool-line is-warn', 'Recording stopped after ' + steps.length.toLocaleString('en-US').replace(/,/g, ' ') + ' steps' + (steps.length < TRACE_STEP_CAP ? ' (the record reached its size cap)' : '') + '; the outcome is the engine’s.'));
    }
    if (input.subjectTruncated) {
      notes.push(text('p', 'pg-tool-line is-warn', 'The trace reads the first ' + TRACE_SUBJECT_CAP.toLocaleString('en-US').replace(',', ' ') + ' bytes of the subject.'));
    }
    if (!steps.length) {
      panel.appendChild(text('p', 'pg-trace-outcome is-' + traceOutcomeTone(answer), traceOutcomeLine(answer)));
      notes.forEach(function (note) {
        panel.appendChild(note);
      });
      panel.appendChild(text('p', 'pg-tool-hint', 'PCRE2 made no step: its start-of-match optimizations found no position worth trying.'));
      renderTraceLabel(panel, answer);
      if (announceResult) announce('Traced: no step. ' + traceOutcomeLine(answer));
      return;
    }

    // The debugger, top to bottom: the transport with the step's number and
    // the match's outcome; the timeline, a slider over the chart of the
    // match; the execution point — the pattern with the item PCRE2 is
    // about to try, the text with what the attempt consumed — under the
    // status line that says the same in words; then the panes: the steps,
    // the stops, the captures.
    var frame = text('div', 'pg-trace');
    view.root = frame;
    view.tiles = patternTokens(current.payload).sort(function (a, b) {
      return a.start - b.start;
    });
    var head = text('div', 'pg-trace-head');

    var bar = text('div', 'pg-trace-bar');
    var controls = text('div', 'pg-trace-controls');
    controls.setAttribute('role', 'group');
    controls.setAttribute('aria-label', 'Step controls');
    view.buttons = {};
    TRACE_CONTROLS.forEach(function (control) {
      view.buttons[control.act] = traceButton(control);
      controls.appendChild(view.buttons[control.act]);
    });
    bar.appendChild(controls);
    var count = text('span', 'pg-trace-count');
    view.count = text('b');
    count.appendChild(view.count);
    count.appendChild(document.createTextNode(' / ' + spaced(steps.length)));
    bar.appendChild(count);
    bar.appendChild(text('p', 'pg-trace-outcome is-' + traceOutcomeTone(answer), traceOutcomeLine(answer)));
    head.appendChild(bar);
    notes.forEach(function (note) {
      head.appendChild(note);
    });

    // The timeline: play, then the chart with the slider laid over it. The
    // chart is for the eye alone; the slider is the platform's own — its
    // arrows, its drag, a finger on a phone.
    var timeline = text('div', 'pg-trace-timeline');
    view.play = traceButton(TRACE_PLAY);
    view.play.classList.add('pg-trace-play');
    timeline.appendChild(view.play);
    var track = text('div', 'pg-trace-track');
    track.appendChild(traceChart(answer));
    track.appendChild(text('span', 'pg-trace-veil'));
    track.appendChild(text('span', 'pg-trace-playhead'));
    var slider = document.createElement('input');
    slider.type = 'range';
    slider.id = 'pg-trace-step';
    slider.min = '0';
    slider.max = String(steps.length - 1);
    slider.value = String(answer.step);
    slider.setAttribute('aria-label', 'Step');
    slider.addEventListener('input', function () {
      traceStopPlay();
      setTraceStep(answer, parseInt(slider.value, 10) || 0);
    });
    track.appendChild(slider);
    view.track = track;
    view.slider = slider;
    timeline.appendChild(track);
    head.appendChild(timeline);

    // The execution point. The two lines are for the eye, colored as the
    // fields are; the status line above them is the one a voice hears, and
    // it says the step's number first.
    var point = text('div', 'pg-trace-point');
    var status = text('p', 'pg-trace-status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    view.status = status;
    point.appendChild(status);
    view.pattern = text('pre', 'pg-trace-pattern');
    view.pattern.setAttribute('aria-hidden', 'true');
    point.appendChild(traceLine('Pattern', view.pattern));
    view.subject = text('pre', 'pg-trace-subject');
    view.subject.setAttribute('aria-hidden', 'true');
    point.appendChild(traceLine('Text', view.subject));
    head.appendChild(point);
    frame.appendChild(head);

    // The steps, as a debugger lists its frames: the item in the mono
    // voice, the byte it stands at to the right, the number in the gutter,
    // a mark in the gutter where PCRE2 backtracked. A match attempt opens
    // with a header of its own, pinned while its steps scroll past. The
    // rows stand in chunks, one list to a voice: a chunk out of sight is
    // skipped by the browser, so a step costs the same on a trace of
    // twenty thousand as on one of twenty.
    var panes = text('div', 'pg-trace-panes');
    var stepsPane = tracePane('steps', 'Steps', 'at byte');
    var list = text('div', 'pg-trace-steps');
    list.tabIndex = 0;
    list.setAttribute('role', 'list');
    list.setAttribute('aria-label', 'Steps; the arrow keys, Home and End move between them');
    var attempt = 0;
    var chunk = null;
    var chunks = [];
    steps.forEach(function (step, index) {
      if (step.flags & 1) {
        attempt += 1;
        var header = text('div', 'pg-trace-attempt', 'Attempt ' + attempt + ' \u00b7 from byte ' + (typeof step.start === 'number' ? step.start : step.s));
        header.setAttribute('role', 'listitem');
        header.setAttribute('data-step', String(index));
        list.appendChild(header);
        chunk = null;
      }
      if (!chunk || chunk.childNodes.length >= TRACE_CHUNK_ROWS) {
        chunk = text('div', 'pg-trace-chunk');
        chunk.setAttribute('role', 'none');
        chunks.push(chunk);
        list.appendChild(chunk);
      }
      var row = text('div', 'pg-trace-step' + (step.flags & 2 ? ' is-backtrack' : '') + (answer.marks.moved[index] ? ' is-capture' : ''));
      row.setAttribute('role', 'listitem');
      row.setAttribute('data-step', String(index));
      row.setAttribute('data-n', String(index + 1));
      if (step.flags & 2) {
        row.appendChild(text('span', 'visually-hidden', 'Backtracked. '));
      }
      if (answer.marks.moved[index]) {
        row.appendChild(text('span', 'visually-hidden', 'Capture changed. '));
      }
      row.appendChild(text('code', step.len ? null : 'is-end', traceItemText(answer, step)));
      row.appendChild(text('span', 'pg-trace-at', typeof step.n === 'number' && step.n !== TRACE_CALLOUT_AUTO ? step.s + ' (callout ' + step.n + ')' : String(step.s)));
      view.items.push(row);
      chunk.appendChild(row);
    });
    // A chunk the browser skips still stands as tall as its rows: it is
    // told how many it holds.
    chunks.forEach(function (held) {
      held.style.setProperty('--rows', String(held.childNodes.length));
    });
    list.addEventListener('click', function (event) {
      var row = event.target.closest('[data-step]');
      if (row) {
        traceStopPlay();
        setTraceStep(answer, Number(row.getAttribute('data-step')));
      }
    });
    view.list = list;
    stepsPane.appendChild(list);
    panes.appendChild(stepsPane);

    // The stops, as a debugger lists what it pauses on: a checkbox, the
    // mark the stop wears in the list and on the timeline, and how many
    // the trace holds.
    var side = text('div', 'pg-trace-side');
    var stopsPane = tracePane('stops', 'Breakpoints');
    var stops = text('ul', 'pg-trace-stops');
    TRACE_STOPS.forEach(function (stop) {
      var held = answer.marks[stop.kind].length;
      var row = text('li');
      var label = text('label', 'pg-trace-stop');
      label.title = stop.hint;
      var input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = traceStops[stop.kind];
      input.setAttribute('aria-label', stop.label + ', ' + held + ' in this trace');
      input.addEventListener('change', function () {
        traceStops[stop.kind] = input.checked;
        frame.classList.toggle('no-' + stop.kind, !input.checked);
      });
      frame.classList.toggle('no-' + stop.kind, !input.checked);
      label.appendChild(input);
      label.appendChild(text('span', 'pg-trace-stop-mark is-' + stop.kind));
      label.appendChild(text('span', 'pg-trace-stop-name', stop.label));
      label.appendChild(text('span', 'pg-trace-stop-count', spaced(held)));
      row.appendChild(label);
      stops.appendChild(row);
    });
    stopsPane.appendChild(stops);
    side.appendChild(stopsPane);

    // The captures, as a debugger lists a scope. Pointing at a group shows
    // its span in the text above.
    var capturesPane = tracePane('captures', 'Captures');
    var captures = text('div', 'pg-trace-captures');
    view.captures = captures;
    captures.addEventListener('mouseover', function (event) {
      var row = event.target.closest('tr[data-from]');
      if (row) {
        tracePeek(answer, [Number(row.getAttribute('data-from')), Number(row.getAttribute('data-to'))]);
      }
    });
    captures.addEventListener('mouseleave', function () {
      tracePeek(answer, null);
    });
    capturesPane.appendChild(captures);
    side.appendChild(capturesPane);
    panes.appendChild(side);
    frame.appendChild(panes);

    frame.addEventListener('click', function (event) {
      var button = event.target.closest('button[data-act]');
      if (button && button.getAttribute('aria-disabled') !== 'true') {
        traceAct(answer, button.getAttribute('data-act'));
      }
    });
    panel.appendChild(frame);
    var pinned = list.querySelector('.pg-trace-attempt');
    view.pinned = pinned ? pinned.offsetHeight : 0;
    renderTraceLabel(frame, answer);
    setTraceStep(answer, answer.step);
    if (announceResult) {
      var check = answer.selfCheck;
      announce('Traced: ' + steps.length + (steps.length === 1 ? ' step. ' : ' steps. ') + traceOutcomeLine(answer) + (check && check.agrees === false ? ' The engine disagrees.' : (check && check.agrees ? ' The engine agrees.' : '')));
    }
  }

  // The current step: the list's aria-current, the slider and the playhead
  // over the chart, the ends of the transport, the status line, the item
  // in the pattern and the cursor in the text, the captures as PCRE2 held
  // them, the item lit in the pattern field and the node under it lit in
  // the tree.
  function setTraceStep(answer, index) {
    var view = answer.view;
    var steps = answer.trace ? answer.trace.steps : null;
    if (!view || !steps || !steps.length) {
      return;
    }
    var last = steps.length - 1;
    index = Math.max(0, Math.min(index, last));
    answer.step = index;
    var step = steps[index];
    if (view.current) {
      view.current.removeAttribute('aria-current');
      view.current.classList.remove('is-current');
    }
    view.current = view.items[index];
    view.current.setAttribute('aria-current', 'step');
    view.current.classList.add('is-current');

    view.slider.value = String(index);
    view.slider.setAttribute('aria-valuetext', 'Step ' + (index + 1) + ' of ' + steps.length);
    view.track.style.setProperty('--t', String(last ? index / last : 0));
    view.count.textContent = spaced(index + 1);
    // An end of the trace dims a button and never disables it: a disabled
    // button drops the focus to the body, and the arrows would step nothing.
    ['rewind', 'back'].forEach(function (act) {
      view.buttons[act].setAttribute('aria-disabled', index === 0 ? 'true' : 'false');
    });
    ['step', 'resume'].forEach(function (act) {
      view.buttons[act].setAttribute('aria-disabled', index === last ? 'true' : 'false');
    });

    fillTraceStatus(answer, step, index);
    view.peek = null;
    clear(view.pattern);
    view.pattern.appendChild(tracePatternFragment(view, step));
    clear(view.subject);
    view.subject.appendChild(traceSubjectFragment(answer, step));
    clear(view.captures);
    view.captures.appendChild(traceCapturesFragment(answer, index));

    // Everything is written; the layout is read once, here, to bring the
    // row, the item and the cursor into the view of their own boxes.
    traceReveal(view.list, view.current, view.pinned);
    traceReveal(view.pattern, view.pattern.querySelector('.pg-trace-item'), 0);
    traceReveal(view.subject, view.subject.querySelector('.pg-trace-cursor.is-now'), 0);

    // The item in the pattern field, and the node around it in the tree.
    traceMark = { start: step.p, end: step.p + step.len };
    var node = traceNodeAt(step.p);
    if (node !== currentNode) {
      setCurrentNode(node);
    } else if (current) {
      renderPatternMirror(current.payload, current.regex, lintFor === current.regex ? lastLint : null);
    }
  }

  // Brings a row into the view of the box that holds it, by that box's own
  // scroll and only when the box scrolls: the answers column and the page
  // stay where the visitor left them. head is what a pinned header covers
  // at the box's top.
  function traceReveal(box, row, head) {
    if (!row || box.scrollHeight <= box.clientHeight) {
      return;
    }
    var top = row.getBoundingClientRect().top - box.getBoundingClientRect().top - box.clientTop + box.scrollTop;
    var bottom = top + row.offsetHeight;
    if (top < box.scrollTop + head) {
      box.scrollTop = Math.max(0, top - head);
    } else if (bottom > box.scrollTop + box.clientHeight) {
      box.scrollTop = bottom - box.clientHeight;
    }
  }

  // The status line, a debugger's "paused" strip: what brought PCRE2 here —
  // a new attempt, a backtrack, a capture that moved — then what it is
  // about to try and where. The step's number comes first, for a voice:
  // the eye has the counter.
  function fillTraceStatus(answer, step, index) {
    var status = answer.view.status;
    var steps = answer.trace.steps;
    var events = [];
    if (step.flags & 1) {
      events.push(['is-attempt', 'New attempt']);
    }
    if (step.flags & 2) {
      events.push(['is-backtrack', 'Backtracked']);
    }
    if (answer.marks.moved[index]) {
      events.push(['is-capture', 'Capture changed']);
    }
    clear(status);
    status.className = 'pg-trace-status' + (step.flags & 2 ? ' is-backtrack' : '');
    status.appendChild(text('span', 'visually-hidden', 'Step ' + (index + 1) + ' of ' + steps.length + '. '));
    events.forEach(function (entry) {
      status.appendChild(text('span', 'pg-trace-event ' + entry[0], entry[1]));
      status.appendChild(text('span', 'visually-hidden', '. '));
    });
    var doing = text('span', 'pg-trace-doing');
    if (step.len) {
      doing.appendChild(document.createTextNode('Trying '));
      doing.appendChild(text('code', null, traceItemText(answer, step)));
    } else {
      doing.appendChild(document.createTextNode(traceItemText(answer, step)));
    }
    doing.appendChild(document.createTextNode(' at byte ' + step.s));
    status.appendChild(doing);
    status.appendChild(text('span', 'visually-hidden', ', '));
    status.appendChild(text('span', 'pg-trace-where', 'attempt ' + answer.marks.attemptOf[index] + ' of ' + Math.max(answer.marks.attempt.length, 1) + (typeof step.start === 'number' ? ', from byte ' + step.start : '')));
  }

  // The pattern as its field shows it, the delimiters and the flags around
  // the body, in the engine's token colors, with the item PCRE2 is about
  // to try marked in it; at the end of the pattern, the closing delimiter.
  function tracePatternFragment(view, step) {
    var fragment = document.createDocumentFragment();
    var regex = current.regex;
    var item = unitRange(current.payload, step.p, step.p + step.len);
    if (!item) {
      fragment.appendChild(document.createTextNode(regex));
      return fragment;
    }
    fragment.appendChild(tileText(regex, view.tiles, 0, item.start));
    var mark = text('mark', 'pg-trace-item');
    mark.appendChild(tileText(regex, view.tiles, item.start, item.end));
    fragment.appendChild(mark);
    fragment.appendChild(tileText(regex, view.tiles, item.end, regex.length));
    return fragment;
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

  // A text for a debugger's eye: every line break shown, so the place
  // before one is a place the cursor can be seen at.
  function traceText(parent, string) {
    string.split('\n').forEach(function (line, index) {
      if (index > 0) {
        parent.appendChild(text('span', 'pg-trace-nl', '\u21b5'));
        parent.appendChild(document.createTextNode('\n'));
      }
      if (line) {
        parent.appendChild(document.createTextNode(line));
      }
    });
  }

  function traceCursor(className, title) {
    var cursor = text('mark', 'pg-trace-cursor ' + className);
    cursor.title = title;
    return cursor;
  }

  // The text, with what the attempt consumed so far marked from where the
  // attempt started to where PCRE2 stands, the cursor at that place, and
  // what was passed before the attempt in the quiet ink. A lookbehind
  // reads before the attempt's start: nothing is consumed there, and both
  // places are shown. The marks are for the eye; the status line says the
  // same.
  function traceSubjectFragment(answer, step) {
    var subject = answer.payload.input.subject;
    var fragment = document.createDocumentFragment();
    var now = unitAt(answer.subjectUnits, step.s);
    var start = typeof step.start === 'number' ? unitAt(answer.subjectUnits, step.start) : now;
    if (Math.min(start, now) > 0) {
      var past = text('span', 'pg-trace-past');
      traceText(past, subject.slice(0, Math.min(start, now)));
      fragment.appendChild(past);
    }
    if (start < now) {
      var consumed = text('mark', 'pg-trace-consumed');
      consumed.title = 'consumed by this attempt, from byte ' + step.start;
      traceText(consumed, subject.slice(start, now));
      fragment.appendChild(consumed);
    }
    fragment.appendChild(traceCursor('is-now', 'subject byte ' + step.s));
    if (now < start) {
      traceText(fragment, subject.slice(now, start));
      fragment.appendChild(traceCursor('is-start', 'start of the attempt, byte ' + step.start));
    }
    traceText(fragment, subject.slice(Math.max(start, now)));
    if (!subject) {
      fragment.appendChild(text('span', 'pg-trace-nl', 'empty text'));
    }
    fragment.appendChild(document.createTextNode('\n'));
    return fragment;
  }

  // Pointing at a group in the captures shows its span in the text; the
  // step's own marks come back when the pointer leaves.
  function tracePeek(answer, pair) {
    var view = answer.view;
    var key = pair ? pair[0] + ':' + pair[1] : null;
    if (!view || view.peek === key) {
      return;
    }
    view.peek = key;
    clear(view.subject);
    if (!pair) {
      view.subject.appendChild(traceSubjectFragment(answer, answer.trace.steps[answer.step]));
      traceReveal(view.subject, view.subject.querySelector('.pg-trace-cursor.is-now'), 0);
      return;
    }
    var subject = answer.payload.input.subject;
    var from = unitAt(answer.subjectUnits, pair[0]);
    var to = unitAt(answer.subjectUnits, pair[1]);
    traceText(view.subject, subject.slice(0, from));
    var span = text('mark', 'pg-trace-peek' + (to > from ? '' : ' is-empty'));
    traceText(span, subject.slice(from, to));
    view.subject.appendChild(span);
    traceText(view.subject, subject.slice(Math.max(from, to)));
    view.subject.appendChild(document.createTextNode('\n'));
    traceReveal(view.subject, span, 0);
  }

  // The captures at a step, as PCRE2 held them: groups from 1, the pairs
  // below capture_top; pair 0 is not a capture during a callout. A group
  // whose capture moved at this step is marked, as a debugger marks the
  // variable a step changed.
  function traceCapturesFragment(answer, index) {
    var fragment = document.createDocumentFragment();
    var pairs = answer.captures[index] || [];
    var names = Object.assign({}, current.payload.matches && current.payload.matches.names ? current.payload.matches.names : {}, current.payload.groupNames || {});
    if (pairs.length <= 1) {
      fragment.appendChild(text('p', 'pg-tool-hint', 'No group captured yet.'));
      return fragment;
    }
    var moved = index > 0 ? movedGroups(answer.captures[index - 1] || [], pairs) : [];
    var table = document.createElement('table');
    table.className = 'pg-trace-table';
    table.appendChild(text('caption', 'visually-hidden', 'Captures at this step'));
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
      var changed = moved.indexOf(group) !== -1;
      if (changed) {
        row.className = 'is-changed';
      }
      if (pair) {
        row.setAttribute('data-from', String(pair[0]));
        row.setAttribute('data-to', String(pair[1]));
      }
      var name = text('th', null, names[String(group)] ? names[String(group)] + ' ' : String(group));
      name.scope = 'row';
      if (names[String(group)]) {
        name.appendChild(text('span', 'pg-trace-group', String(group)));
      }
      if (changed) {
        name.appendChild(text('span', 'visually-hidden', ' (changed at this step)'));
      }
      row.appendChild(name);
      var value = text('td', null);
      if (pair) {
        value.appendChild(text('code', null, decoder.decode(answer.subjectBytes.slice(pair[0], pair[1]))));
      } else {
        value.appendChild(text('span', 'pg-trace-unset', 'unset'));
      }
      row.appendChild(value);
      row.appendChild(text('td', 'pg-trace-bytes', pair ? pair[0] + '\u2013' + pair[1] : '\u2014'));
      body.appendChild(row);
    }
    table.appendChild(body);
    fragment.appendChild(table);
    return fragment;
  }

  // The debugger's keys, read once on the panel so they answer wherever
  // the focus stands in it: the arrows step, Shift travels to a stop, Home
  // and End reach the ends, Space plays. Inside the list, up and down step
  // too, as they walk any list. A modified key stays the browser's; the
  // slider and the checkboxes keep their own, and a button its Space.
  function onTraceKey(event) {
    if (event.altKey || event.ctrlKey || event.metaKey) {
      return;
    }
    var answer = toolAnswers.trace;
    var view = answer ? answer.view : null;
    if (!view || !view.root || !view.root.isConnected || event.target.tagName === 'INPUT') {
      return;
    }
    var inList = view.list.contains(event.target);
    var act = '';
    switch (event.key) {
      case 'ArrowRight': act = event.shiftKey ? 'resume' : 'step'; break;
      case 'ArrowLeft': act = event.shiftKey ? 'rewind' : 'back'; break;
      case 'ArrowDown': act = inList ? 'step' : ''; break;
      case 'ArrowUp': act = inList ? 'back' : ''; break;
      case 'Home': act = 'first'; break;
      case 'End': act = 'last'; break;
      case ' ': act = event.target.tagName === 'BUTTON' ? '' : 'play'; break;
      default: break;
    }
    if (!act) {
      return;
    }
    event.preventDefault();
    traceAct(answer, act);
  }
  toolPanel('trace').addEventListener('keydown', onTraceKey);

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
    syncTabFades();
    syncExampleFades();
  }
  // The fades say themselves, only where more tabs wait — the strip is
  // one line, and a travelled edge is the whole affordance it needs.
  function syncTabFades() {
    syncEdgeFades(el.tabStrip);
  }
  // The row of examples, where it scrolls under the fields, says the same
  // of the examples past its edge; a column or a wrapped row never fades.
  function syncExampleFades() {
    syncEdgeFades(el.examplesList);
  }
  function syncEdgeFades(strip) {
    var max = strip.scrollWidth - strip.clientWidth;
    strip.classList.toggle('has-more-right', max > 4 && strip.scrollLeft < max - 4);
    strip.classList.toggle('has-more-left', max > 4 && strip.scrollLeft > 4);
  }
  // The open tab, or the one the arrows land on, is carried to the
  // middle of the strip: a tab reached by keyboard is a tab opened, and
  // a hidden one opens to its own name.
  function scrollTabIntoView(tab) {
    var left = Math.max(0, tab.offsetLeft - (el.tabStrip.clientWidth - tab.offsetWidth) / 2);
    // Less motion asked for: the strip jumps to the tab, never glides.
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    try {
      el.tabStrip.scrollTo({ left: left, behavior: still ? 'auto' : 'smooth' });
    } catch (error) {
      el.tabStrip.scrollLeft = left;
    }
  }
  el.tabStrip.addEventListener('scroll', syncTabFades, { passive: true });
  el.examplesList.addEventListener('scroll', syncExampleFades, { passive: true });
  el.tabStrip.addEventListener('focusin', function (event) {
    var tab = event.target.closest('.pg-tab');
    if (tab) {
      scrollTabIntoView(tab);
    }
  });
  window.addEventListener('resize', measureStrip);
  if (window.ResizeObserver) {
    // A badge, or a font that arrives late, widens a tab without moving
    // the strip's own box: the tabs are watched too, so the fades follow.
    var stripObserver = new ResizeObserver(measureStrip);
    stripObserver.observe(el.tabStrip);
    stripObserver.observe(el.examplesList);
    Array.prototype.forEach.call(el.tabStrip.querySelectorAll('.pg-tab'), function (tab) {
      stripObserver.observe(tab);
    });
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
      track('voice_toggled', { voice: voice });
    });
    option.addEventListener('keydown', function (event) {
      if (event.altKey || event.ctrlKey || event.metaKey || ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(event.key) === -1) {
        return;
      }
      event.preventDefault();
      stopStroll();
      setVoice(voice === 'tree' ? 'prose' : 'tree', true, true);
      track('voice_toggled', { voice: voice });
    });
  });
  var announceEngineError = false; // after "Try it": the engine's own words, once

  // --- findings ------------------------------------------------------------

  // What the linter reports on the corpus of open-source projects: an
  // index of the repositories, fetched from the site itself when the list
  // opens, and each repository's findings, fetched when it opens. All of
  // it was written by bin/findings-report from the last corpus run;
  // nothing here asks GitHub. In a finding the text field gives its place
  // to a card that cannot be typed into. Opening a finding is a run
  // gesture: the engine answers it, as it answers an example.

  var findingsIndex = null;   // the index, once fetched
  var findingsLoading = null; // the promise of the index
  var repoFiles = {};         // slug -> the promise of that repository's findings
  var activeRepo = null;      // the repository of the finding the window shows
  var filterTimer = null;

  function fetchJson(request) {
    return request.then(function (response) {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    });
  }

  function loadIndex() {
    if (!findingsLoading) {
      findingsLoading = fetchJson(fetch('/assets/findings/index.json')).then(function (index) {
        findingsIndex = index;
        // The fold names the project on its page, the corpus on the home;
        // one project needs no filter.
        el.findingsCount.textContent = pageRepo ? pageRepo : String(index.repos.length);
        if (pageRepo) {
          el.findingsFilter.hidden = true;
        }
        buildTree();
        return index;
      });
      findingsLoading.catch(function () {
        findingsLoading = null;
        el.findingsEmpty.textContent = 'The findings could not be loaded; try again later.';
        el.findingsEmpty.hidden = false;
      });
    }
    return findingsLoading;
  }

  function loadRepo(slug) {
    if (!repoFiles[slug]) {
      repoFiles[slug] = fetchJson(fetch('/assets/findings/repos/' + slug + '.json'));
      repoFiles[slug].catch(function () {
        delete repoFiles[slug];
      });
    }
    return repoFiles[slug];
  }

  function repoNamed(name) {
    var repos = findingsIndex ? findingsIndex.repos : [];
    for (var i = 0; i < repos.length; i += 1) {
      if (repos[i].repo === name || repos[i].slug === name) {
        return repos[i];
      }
    }
    return null;
  }

  function findingUrl(repo, finding) {
    return window.location.origin + '/findings/' + repo.repo + '/#' + finding.id;
  }

  // The analytics hear a gesture by its name, with a finding's id at
  // most: never a pattern, never a text. Without the analytics (outside
  // production, or blocked), nothing is said.
  function track(name, params) {
    if (typeof window.gtag === 'function') {
      window.gtag('event', name, params || {});
    }
  }

  // The tree speaks the syntax tree's dialect: each item is the tree item
  // itself, one tab stop for the whole tree, the arrow keys move between
  // the items shown. Owners, then their repositories (the engine each was
  // judged for under its name), then the findings, fetched on opening.
  function treeItem(level, className) {
    var item = text('li', 'pg-tree-item ' + className);
    item.setAttribute('role', 'treeitem');
    item.setAttribute('aria-level', String(level));
    item.setAttribute('tabindex', '-1');
    return item;
  }

  function treeGroup(item) {
    var group = text('ul', 'pg-tree-group');
    group.setAttribute('role', 'group');
    group.hidden = true;
    item.setAttribute('aria-expanded', 'false');
    item.appendChild(group);
    return group;
  }

  function buildTree() {
    clear(el.findingsTree);
    var owners = [];
    var byOwner = {};
    findingsIndex.repos.forEach(function (repo) {
      // A repository's page speaks of that repository alone: the tree
      // shows its project, the other two hundred stay on the home.
      if (pageRepo && repo.repo !== pageRepo) {
        return;
      }
      if (!byOwner[repo.owner]) {
        byOwner[repo.owner] = [];
        owners.push(repo.owner);
      }
      byOwner[repo.owner].push(repo);
    });
    owners.forEach(function (login) {
      var item = treeItem(1, 'is-owner');
      item.setAttribute('data-owner', login);
      var row = text('span', 'pg-tree-row');
      var avatar = document.createElement('img');
      avatar.src = findingsIndex.owners[login].avatar;
      avatar.alt = '';
      avatar.width = 20;
      avatar.height = 20;
      avatar.loading = 'lazy';
      row.appendChild(avatar);
      row.appendChild(text('span', 'pg-tree-name', login));
      row.appendChild(text('span', 'pg-tree-count', String(byOwner[login].reduce(function (sum, repo) {
        return sum + repo.findings;
      }, 0))));
      item.appendChild(row);
      var group = treeGroup(item);
      byOwner[login].forEach(function (repo) {
        group.appendChild(repoRow(repo));
      });
      el.findingsTree.appendChild(item);
    });
    var first = el.findingsTree.querySelector('[role="treeitem"]');
    if (first) {
      first.setAttribute('tabindex', '0');
    }
    markLast();
    filterTree();
  }

  function repoRow(repo) {
    var item = treeItem(2, 'is-repo');
    item.setAttribute('data-slug', repo.slug);
    // The engine rides the row's tooltip: a tree of hundreds of rows is
    // one line per row; the card says the engine in chips for the chosen
    // finding.
    item.title = 'PHP ' + repo.target.php + ' · PCRE2 ' + repo.target.pcre;
    // A tooltip shows to a pointer only; the description is read out on
    // focus too.
    item.setAttribute('aria-description', 'PHP ' + repo.target.php + ' · PCRE2 ' + repo.target.pcre);
    var row = text('span', 'pg-tree-row');
    row.appendChild(text('span', 'pg-tree-name', repo.repo.split('/')[1]));
    row.appendChild(text('span', 'pg-tree-count' + (repo.fail ? ' is-fail' : ''), String(repo.findings)));
    item.appendChild(row);
    treeGroup(item);
    return item;
  }

  function findingRow(repo, finding) {
    var item = treeItem(3, 'is-finding' + (finding.level === 'fail' ? ' is-fail' : ''));
    item.setAttribute('data-key', repo.slug + '#' + finding.id);
    var row = text('span', 'pg-tree-row');
    row.appendChild(text('span', 'pg-tree-level', finding.level === 'fail' ? 'FAIL' : 'WARN'));
    row.appendChild(text('span', 'pg-tree-name', finding.file.split('/').pop() + ':' + finding.line));
    row.appendChild(text('span', 'pg-tree-count', String(finding.issues.length)));
    item.appendChild(row);
    // The rule's message rides the tooltip and the card; the row is one
    // line, scannable at three hundred siblings.
    item.title = finding.file + ':' + finding.line + ' · ' + finding.issues[0].message;
    item.setAttribute('aria-description', item.title);
    return item;
  }

  function expandItem(item, open) {
    var group = item.querySelector(':scope > .pg-tree-group');
    if (!group) {
      return Promise.resolve();
    }
    item.setAttribute('aria-expanded', open ? 'true' : 'false');
    group.hidden = !open;
    if (!open && group.querySelector('[tabindex="0"]')) {
      group.querySelector('[tabindex="0"]').setAttribute('tabindex', '-1');
      item.setAttribute('tabindex', '0');
    }
    var slug = item.getAttribute('data-slug');
    if (!open || !slug || group.children.length) {
      return Promise.resolve();
    }
    var repo = repoNamed(slug);
    return loadRepo(slug).then(function (file) {
      if (!group.children.length) {
        file.findings.forEach(function (finding) {
          group.appendChild(findingRow(repo, finding));
        });
        markLast();
      }
    });
  }

  // One tab stop for the whole tree, always on an item shown: a filter or
  // a fold that hides it hands it to the first item left.
  function keepTabStop() {
    var stop = el.findingsTree.querySelector('[tabindex="0"]');
    if (stop && !stop.closest('[hidden]')) {
      return;
    }
    if (stop) {
      stop.setAttribute('tabindex', '-1');
    }
    var first = visibleItems()[0];
    if (first) {
      first.setAttribute('tabindex', '0');
    }
  }

  function visibleItems() {
    return Array.prototype.filter.call(el.findingsTree.querySelectorAll('[role="treeitem"]'), function (item) {
      return !item.closest('[hidden]');
    });
  }

  function focusItem(item) {
    Array.prototype.forEach.call(el.findingsTree.querySelectorAll('[tabindex="0"]'), function (other) {
      other.setAttribute('tabindex', '-1');
    });
    item.setAttribute('tabindex', '0');
    item.focus();
  }

  // One group stands open at a time, at each level: opening a project
  // closes the others, opening a repository closes its siblings — the
  // list stays a window, never a wall. A link or the filter still opens
  // what they name: they speak outside the accordion.
  function collapseSiblings(item) {
    Array.prototype.forEach.call(item.parentElement.children, function (sibling) {
      if (sibling !== item && sibling.getAttribute && sibling.getAttribute('aria-expanded') === 'true') {
        expandItem(sibling, false);
      }
    });
  }

  function openGroup(item) {
    collapseSiblings(item);
    return expandItem(item, true);
  }

  // A finding's project and repository, opened when closed: Back to the
  // finding comes after the accordion may have folded them. An open group
  // is left as it is, and so are the siblings the filter opened.
  function revealRepo(repo) {
    var ownerItem = el.findingsTree.querySelector('[data-owner="' + repo.owner.replace(/"/g, '') + '"]');
    var repoItem = el.findingsTree.querySelector('[data-slug="' + repo.slug.replace(/"/g, '') + '"]');
    // A filter typed since the finding was left may hide its row: cleared,
    // the row the card stands for is in the list again. A row clicked is
    // never hidden, so a search in use survives.
    if (repoItem && repoItem.hidden && el.findingsFilter.value) {
      el.findingsFilter.value = '';
      filterTree();
    }
    if (ownerItem && ownerItem.getAttribute('aria-expanded') === 'false') {
      openGroup(ownerItem);
    }
    return repoItem && repoItem.getAttribute('aria-expanded') === 'false' ? openGroup(repoItem) : Promise.resolve();
  }

  function activateItem(item) {
    pageTouched = true;
    var key = item.getAttribute('data-key');
    if (key) {
      openFindingKey(key);
    } else if (item.getAttribute('aria-expanded') === 'true') {
      expandItem(item, false);
    } else {
      openGroup(item);
    }
  }

  // The arrow keys walk the shown items: down and up in reading order,
  // right opens an item or steps into it, left closes it or steps out to
  // its parent, Home and End to the ends, Enter opens.
  function onTreeKey(event) {
    var item = event.target.closest('[role="treeitem"]');
    if (!item || event.altKey || event.ctrlKey || event.metaKey) {
      return;
    }
    var items = visibleItems();
    var index = items.indexOf(item);
    var target = null;
    switch (event.key) {
      case 'ArrowDown': target = items[Math.min(index + 1, items.length - 1)]; break;
      case 'ArrowUp': target = items[Math.max(index - 1, 0)]; break;
      case 'Home': target = items[0]; break;
      case 'End': target = items[items.length - 1]; break;
      case 'ArrowRight':
        if (item.getAttribute('aria-expanded') === 'false') {
          openGroup(item);
        } else if (item.getAttribute('aria-expanded') === 'true') {
          target = items[index + 1] || null;
        }
        break;
      case 'ArrowLeft':
        if (item.getAttribute('aria-expanded') === 'true') {
          expandItem(item, false);
        } else {
          target = item.parentNode.closest('[role="treeitem"]');
        }
        break;
      case 'Enter':
      case ' ':
        activateItem(item);
        break;
      default: return;
    }
    event.preventDefault();
    if (target) {
      focusItem(target);
    }
  }

  // The filter reads the index alone: an owner, a repository, or a rule
  // a repository met, without opening any.
  function filterTree() {
    if (!findingsIndex) {
      return;
    }
    var query = el.findingsFilter.value.trim().toLowerCase();
    var shown = 0;
    Array.prototype.forEach.call(el.findingsTree.children, function (ownerItem) {
      var any = false;
      Array.prototype.forEach.call(ownerItem.querySelectorAll('.is-repo'), function (repoItem) {
        var repo = repoNamed(repoItem.getAttribute('data-slug'));
        var match = !query || repo.repo.toLowerCase().indexOf(query) !== -1 || repo.rules.some(function (rule) {
          return rule.toLowerCase().indexOf(query) !== -1;
        });
        repoItem.hidden = !match;
        any = any || match;
        shown += match ? 1 : 0;
      });
      ownerItem.hidden = !any;
      if (query && any) {
        expandItem(ownerItem, true);
      }
    });
    el.findingsEmpty.textContent = 'No project matches.';
    el.findingsEmpty.hidden = shown !== 0;
    keepTabStop();
  }

  // The finding the window shows is pressed; the one shown last, outlined:
  // one click away. Its repository too, while it is closed.
  function markLast() {
    var lastSlug = lastFinding ? lastFinding.split('#')[0] : null;
    Array.prototype.forEach.call(el.findingsTree.querySelectorAll('[role="treeitem"]'), function (item) {
      var key = item.getAttribute('data-key');
      var shown = !!activeFinding && key === activeFinding.key;
      item.setAttribute('aria-selected', shown ? 'true' : 'false');
      item.classList.toggle('is-last', !shown && ((key && key === lastFinding) || (!key && lastSlug !== null && item.getAttribute('data-slug') === lastSlug)));
      if (shown) {
        revealRow(item);
      }
    });
  }

  // The tree is a window of its own: the row the window shows is brought
  // inside it, never left scrolling below the fold. The tree scrolls, not
  // the page — scrollIntoView would move every ancestor, and on a narrow
  // screen pull the page from the card up to the list.
  function revealRow(item) {
    // A row inside a closed group has no box: measured, it would throw
    // the tree to its top.
    if (!item.offsetParent) {
      return;
    }
    var tree = el.findingsTree;
    var row = item.firstElementChild || item;
    var top = row.getBoundingClientRect().top - tree.getBoundingClientRect().top + tree.scrollTop;
    if (top < tree.scrollTop) {
      tree.scrollTop = top;
    } else if (top + row.offsetHeight > tree.scrollTop + tree.clientHeight) {
      tree.scrollTop = top + row.offsetHeight - tree.clientHeight;
    }
  }

  function openFindingKey(key) {
    var cut = key.indexOf('#');
    var slug = key.slice(0, cut);
    var id = key.slice(cut + 1);
    return loadIndex().then(function () {
      var repo = repoNamed(slug);
      return repo ? revealRepo(repo).then(function () {
        return loadRepo(slug);
      }).then(function (file) {
        var finding = file.findings.filter(function (candidate) {
          return candidate.id === id;
        })[0];
        if (finding) {
          enterFinding(repo, finding, true);
        }
      }) : null;
    });
  }

  // A repository's own page: its finding from the address, else its
  // first. The link was the gesture, as a link carrying a pattern is.
  function openRepoPage(name) {
    // The finding the link names, read before anything can rewrite the
    // address; and a gesture made while the data travels wins over it.
    var wanted = window.location.hash.replace(/^#/, '');
    setStatus('Loading the findings of ' + name + '…', 'busy');
    loadIndex().then(function () {
      var repo = repoNamed(name);
      if (!repo) {
        setStatus('The latest run reports nothing on ' + name + '.', 'error');
        return null;
      }
      return revealRepo(repo).then(function () {
        return loadRepo(repo.slug);
      }).then(function (file) {
        if (pageTouched) {
          // The visitor acted first: the window is theirs, at the home's
          // address, where a pattern may be written.
          setStatus('', '');
          try {
            window.history.replaceState(null, '', '/');
          } catch (error) {/* the address stays as it was */}
          return;
        }
        var finding = file.findings.filter(function (candidate) {
          return candidate.id === wanted;
        })[0];
        enterFinding(repo, finding || file.findings[0], true);
        // A link to a finding the latest run no longer reports says so on
        // the card, which the engine's answers never overwrite.
        if (wanted && !finding) {
          el.findingNotice.textContent = 'The finding ' + wanted + ' is not in the latest run; ' + repo.repo + '\'s first finding is shown.';
          el.findingNotice.hidden = false;
        }
      });
    }).catch(function () {
      setStatus('The findings could not be loaded; try again later.', 'error');
    });
  }

  function enterFinding(repo, finding, run) {
    stopStroll();
    activeRepo = repo;
    activeFinding = finding;
    finding.key = repo.slug + '#' + finding.id;
    lastFinding = finding.key;
    // The project's PHP when the playground offers it; the visitor's own
    // choice otherwise, left stored as it was.
    var judged = repo.target.php.split('.').slice(0, 2).join('.');
    if (VERSIONS.indexOf(judged) !== -1) {
      chosen = judged;
      el.version.value = chosen;
    }
    el.pattern.value = finding.pattern;
    el.subject.value = finding.subject;
    el.textField.hidden = true;
    el.findingCard.hidden = false;
    el.findingLeft.hidden = true;
    el.findingNotice.hidden = true;
    renderFindingCard(repo, finding);
    refreshFields();
    dropSubjectMarks();
    storeFinding(finding.key);
    markLast();
    try {
      window.history.replaceState(null, '', '/findings/' + repo.repo + '/#' + finding.id);
    } catch (error) {/* the address stays as it was */}
    track('finding_opened', { finding_id: finding.id });
    if (run) {
      analyzeNow();
    }
  }

  function leaveFinding(reason) {
    if (!activeFinding) {
      return;
    }
    lastFinding = activeFinding.key;
    activeFinding = null;
    el.findingCard.hidden = true;
    el.textField.hidden = false;
    el.findingLeft.hidden = reason === 'source';
    el.findingLeftText.textContent = reason === 'edit' ? 'Edited copy, no longer the finding.' : 'A copy of the finding, yours to edit.';
    try {
      window.history.replaceState(null, '', '/');
    } catch (error) {/* the address stays as it was */}
    markLast();
  }

  function editCopy() {
    if (!activeFinding) {
      return;
    }
    el.subject.value = activeFinding.subject;
    leaveFinding('copy');
    refreshFields();
    el.subject.focus();
    analyzeNow();
  }

  function renderFindingCard(repo, finding) {
    var owner = findingsIndex.owners[repo.owner];
    clear(el.findingAvatar);
    el.findingAvatar.href = owner.profile;
    var avatar = document.createElement('img');
    avatar.src = owner.avatar;
    avatar.alt = repo.owner + ' on GitHub';
    avatar.width = 24;
    avatar.height = 24;
    el.findingAvatar.appendChild(avatar);
    el.findingRepo.textContent = repo.repo;
    el.permalink.href = finding.permalink;
    el.permalink.textContent = 'View on GitHub at ' + repo.sha.slice(0, 7);
    // The engine as two mono chips and a quiet date: three natures of
    // information, not one sentence wrapped over two lines.
    clear(el.findingTarget);
    el.findingTarget.appendChild(text('span', 'visually-hidden', 'Judged for '));
    el.findingTarget.appendChild(text('span', 'pg-finding-engine', 'PHP ' + repo.target.php));
    el.findingTarget.appendChild(text('span', 'pg-finding-engine', 'PCRE2 ' + repo.target.pcre));
    // The source says itself only when it is one: a fallback notice is
    // the pipeline's business, not the visitor's.
    var targetSource = repo.target.source && repo.target.source.indexOf('fallback') !== 0 ? repo.target.source + ' · ' : '';
    el.findingTarget.appendChild(text('span', 'pg-finding-asof', targetSource + 'code as of ' + repo.committed));
    el.snippetFile.textContent = finding.file + ':' + finding.line + (finding.source ? ' · ' + finding.source : '') + (finding.location ? ' · ' + finding.location : '');

    clear(el.snippet);
    finding.snippet.forEach(function (line) {
      var row = text('span', 'pg-snippet-line' + (line.focus ? ' is-focus' : ''));
      row.appendChild(text('span', 'pg-snippet-number', String(line.number)));
      line.runs.forEach(function (run) {
        row.appendChild(run[1] ? text('span', run[1], run[0]) : document.createTextNode(run[0]));
      });
      el.snippet.appendChild(row);
    });

    renderIssues(finding);

    el.rewrite.hidden = !finding.rewrite;
    clear(el.rewriteDiff);
    if (finding.rewrite) {
      el.rewriteDiff.appendChild(text('span', 'pg-fix-del', '- ' + finding.pattern));
      el.rewriteDiff.appendChild(document.createTextNode('\n'));
      el.rewriteDiff.appendChild(text('span', 'pg-fix-add', '+ ' + finding.rewrite.optimized));
      finding.rewrite.changes.forEach(function (change) {
        el.rewriteDiff.appendChild(document.createTextNode('\n'));
        el.rewriteDiff.appendChild(text('span', 'pg-rewrite-change', change));
      });
    }

    renderWitness(finding);
    el.shareNative.hidden = typeof navigator.share !== 'function';
  }

  // The linter's own words, worst first, each with its rule's page on
  // this site.
  function renderIssues(finding) {
    clear(el.findingIssues);
    finding.issues.forEach(function (issue) {
      var item = text('li', 'pg-finding-issue-row is-' + issue.level);
      item.appendChild(text('span', 'pg-finding-level', issue.level === 'fail' ? 'FAIL' : 'WARN'));
      item.appendChild(text('span', 'pg-finding-message', issue.message));
      if (issue.hint) {
        item.appendChild(text('span', 'pg-finding-hint', issue.hint));
      }
      if (issue.docs) {
        var link = text('a', 'pg-finding-rule', issue.rule);
        link.href = issue.docs;
        item.appendChild(link);
      }
      el.findingIssues.appendChild(item);
    });
  }

  // A proven ReDoS: the string that breaks the pattern, as the ReDoS tab
  // spells it, the repeated part marked; then where the corpus run's
  // replay gave up, in the log's own sentence.
  function renderWitness(finding) {
    clear(el.witness);
    el.witness.hidden = !finding.witness;
    el.outcome.hidden = !finding.witness || !finding.witness.length;
    if (!finding.witness) {
      return;
    }
    el.witness.appendChild(text('span', 'pg-witness-label', 'Witness: '));
    if (finding.witness.prefix) {
      el.witness.appendChild(text('code', null, '"' + finding.witness.prefix + '"'));
      el.witness.appendChild(document.createTextNode(' + '));
    }
    el.witness.appendChild(text('code', 'pg-witness-pump', '"' + finding.witness.pump + '"'));
    el.witness.appendChild(document.createTextNode(' × ' + finding.witness.n));
    if (finding.witness.suffix) {
      el.witness.appendChild(document.createTextNode(' + '));
      el.witness.appendChild(text('code', null, '"' + finding.witness.suffix + '"'));
    }
    el.outcome.textContent = 'Replayed on PCRE2 ' + finding.witness.pcre + ' (JIT off, backtrack limit ' + finding.witness.limit + '): preg_match() gives up from length ' + finding.witness.length + '.';
  }

  el.findingsTree.addEventListener('click', function (event) {
    var item = event.target.closest('[role="treeitem"]');
    if (item) {
      focusItem(item);
      activateItem(item);
    }
  });
  el.findingsTree.addEventListener('keydown', onTreeKey);
  el.findingsFilter.addEventListener('input', function () {
    window.clearTimeout(filterTimer);
    filterTimer = window.setTimeout(filterTree, 120);
  });
  // --- the pane separator --------------------------------------------------

  // The two panes answer the hand: one separator on their boundary,
  // dragged by the pointer, walked by the arrow keys, doubled back to
  // its default by a double click, the chosen width persisting between
  // visits. The answers pane gives what is left; nothing ever disappears
  // below its floor.
  var SPLIT_STORAGE_KEY = 'playground-split';
  var SPLIT_MIN = 144;      // a pane nobody can read
  var SPLIT_LAST_MIN = 320; // the answers never disappear
  var SPLIT_STEP = 16;      // one key press, one notch
  var paneWidth = null;     // the text pane's width in px, null = the stylesheet's word
  var panesMedia = window.matchMedia ? window.matchMedia('(min-width: 1024px)') : null;

  // The panes share the body's content box: its padding and its one gap
  // are not pane.
  function paneBounds() {
    var style = getComputedStyle(el.body);
    var gap = parseFloat(style.columnGap) || 0;
    var total = el.body.clientWidth
      - (parseFloat(style.paddingLeft) || 0)
      - (parseFloat(style.paddingRight) || 0)
      - gap * 1;
    return { total: total };
  }

  function clampPaneWidths() {
    var bounds = paneBounds();
    paneWidth = Math.min(Math.max(paneWidth, SPLIT_MIN), Math.max(SPLIT_MIN, bounds.total - SPLIT_LAST_MIN));
  }

  function applyPaneWidths() {
    if (!panesMedia || !panesMedia.matches || !paneWidth) {
      el.body.style.gridTemplateColumns = '';
      positionSplitters();
      return;
    }
    clampPaneWidths();
    el.body.style.gridTemplateColumns = paneWidth + 'px minmax(0, 1fr)';
    positionSplitters();
  }

  function storePaneWidth() {
    try {
      localStorage.setItem(SPLIT_STORAGE_KEY, JSON.stringify({ text: paneWidth }));
    } catch (error) {/* the width simply does not persist */}
  }

  function readPaneWidth() {
    try {
      var stored = JSON.parse(localStorage.getItem(SPLIT_STORAGE_KEY) || 'null');
      if (stored && typeof stored.text === 'number') {
        paneWidth = stored.text;
      }
    } catch (error) {/* the stylesheet's word stands */}
  }

  function measurePaneWidth() {
    var tracks = getComputedStyle(el.body).gridTemplateColumns.split(' ').map(parseFloat);
    if (tracks.length >= 2) {
      paneWidth = tracks[0];
    }
  }

  function nudgePane(delta) {
    if (!paneWidth) {
      measurePaneWidth();
    }
    clampPaneWidths();
    var before = paneWidth;
    paneWidth = paneWidth + delta;
    applyPaneWidths();
    if (paneWidth !== before) {
      storePaneWidth();
    }
  }

  function dragSplitter(handle) {
    handle.addEventListener('pointerdown', function (down) {
      if (!paneWidth) {
        measurePaneWidth();
      }
      down.preventDefault();
      try {
        handle.setPointerCapture(down.pointerId);
      } catch (error) {/* the drag simply follows the pointer */}
      var start = paneWidth;
      var move = function (moveEvent) {
        paneWidth = start + (moveEvent.clientX - down.clientX);
        applyPaneWidths();
      };
      // A drag the platform takes away — a scroll claims the pointer, the
      // capture is lost — ends like one the hand ended.
      var up = function () {
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', up);
        handle.removeEventListener('pointercancel', up);
        handle.removeEventListener('lostpointercapture', up);
        clampPaneWidths();
        applyPaneWidths();
        storePaneWidth();
      };
      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', up);
      handle.addEventListener('pointercancel', up);
      handle.addEventListener('lostpointercapture', up);
    });
    handle.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        nudgePane(-SPLIT_STEP);
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        nudgePane(SPLIT_STEP);
      }
    });
    handle.addEventListener('dblclick', function () {
      paneWidth = null;
      try {
        localStorage.removeItem(SPLIT_STORAGE_KEY);
      } catch (error) {/* nothing to drop */}
      applyPaneWidths();
      positionSplitters();
    });
  }

  // The handle rides the boundary; where the boundary sits, the computed
  // tracks say, whatever set them — the stylesheet or the hand.
  function positionSplitters() {
    if (!panesMedia || !panesMedia.matches) {
      return;
    }
    var handle = el.body.querySelector('.pg-splitter');
    var tracks = getComputedStyle(el.body).gridTemplateColumns.split(' ').map(parseFloat);
    if (!handle || tracks.length < 2) {
      return;
    }
    var style = getComputedStyle(el.body);
    var gap = parseFloat(style.columnGap) || 0;
    // An absolute box starts at the padding edge; the tracks, after it.
    var inset = parseFloat(style.paddingLeft) || 0;
    handle.hidden = false;
    var edge = tracks[0];
    handle.style.left = Math.round(inset + edge + gap / 2 - handle.offsetWidth / 2) + 'px';
  }

  function buildSplitters() {
    var handle = document.createElement('div');
    handle.className = 'pg-splitter';
    handle.setAttribute('role', 'separator');
    handle.setAttribute('aria-orientation', 'vertical');
    handle.setAttribute('aria-label', 'Text width');
    handle.setAttribute('data-pane', 'text');
    handle.tabIndex = 0;
    handle.title = 'Text width — drag, or press the arrow keys';
    el.body.appendChild(handle);
    dragSplitter(handle);
  }

  // The projects door opens to look, never to claim: no hand on it says
  // a gesture, so a repository's page keeps the finding its link asked
  // for even when the door opens while it loads. Its first opening
  // fetches the corpus, and a row in a closed door has nowhere to scroll
  // to: opening it brings the shown row in.
  el.findingsBrowse.addEventListener('toggle', function () {
    if (!el.findingsBrowse.open) {
      return;
    }
    loadIndex();
    var shown = el.findingsTree.querySelector('[aria-selected="true"]');
    if (shown) {
      revealRow(shown);
    }
  });
  // A click outside the panel closes it, as a popover's does: the tool
  // beneath is a click away, never a scroll.
  document.addEventListener('click', function (event) {
    if (el.findingsBrowse.open && !el.findingsBrowse.contains(event.target)) {
      el.findingsBrowse.open = false;
    }
  });
  // An Escape closes it too — from wherever the hand stands, inside the
  // panel or past it — and carries the hand back to the trigger that
  // opened it.
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && el.findingsBrowse.open) {
      el.findingsBrowse.open = false;
      el.findingsDoor.focus();
    }
  });

  buildSplitters();
  readPaneWidth();
  applyPaneWidths();
  if (panesMedia && panesMedia.addEventListener) {
    panesMedia.addEventListener('change', applyPaneWidths);
  }
  // A smaller window clamps the stored width again, then places the
  // handle.
  window.addEventListener('resize', applyPaneWidths);

  el.findingCopy.addEventListener('click', editCopy);
  el.findingBack.addEventListener('click', function () {
    if (lastFinding) {
      openFindingKey(lastFinding);
    }
  });
  el.shareCopy.addEventListener('click', function () {
    if (!activeFinding) {
      return;
    }
    track('share_clicked', { finding_id: activeFinding.id, method: 'copy' });
    var url = findingUrl(activeRepo, activeFinding);
    var said = function (words) {
      el.shareCopy.textContent = words;
      window.setTimeout(function () {
        el.shareCopy.textContent = 'Copy link';
      }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () {
        said('Copied');
      }, function () {
        said(url);
      });
    } else {
      said(url);
    }
  });
  el.shareNative.addEventListener('click', function () {
    if (!activeFinding || typeof navigator.share !== 'function') {
      return;
    }
    track('share_clicked', { finding_id: activeFinding.id, method: 'native' });
    navigator.share({ title: activeRepo.repo + ': ' + activeFinding.issues[0].message, url: findingUrl(activeRepo, activeFinding) }).catch(function () {/* the visitor closed the sheet */});
  });

  // --- the projects panel --------------------------------------------------

  // The projects live behind a door of their own, closed until a hand or
  // a repository's link opens it. The last finding opened is remembered,
  // lit in the tree when the door opens, never opened unasked: the home
  // stays the tool's face, a repository's page opens its finding.
  var FINDING_STORAGE_KEY = 'playground-finding';
  var storedFinding = null;
  try { storedFinding = localStorage.getItem(FINDING_STORAGE_KEY); } catch (error) {/* private mode keeps the default */}
  if (/^[\w~-]+__[\w~-]+#f-[0-9a-f]{10}(?:-\d+)?$/.test(storedFinding || '')) {
    lastFinding = storedFinding;
  } else if (storedFinding !== null) {
    storedFinding = null;
    try { localStorage.removeItem(FINDING_STORAGE_KEY); } catch (error) {/* nothing to drop */}
  }

  function storeFinding(key) {
    try { localStorage.setItem(FINDING_STORAGE_KEY, key); } catch (error) {/* the choice simply does not persist */}
  }

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
    // A finding's address never carries a pattern: a link that shows one
    // pattern under another's card cannot be built.
    if (activeFinding || window.location.pathname.indexOf('/findings/') === 0) {
      return;
    }
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
    track('version_changed', { php_version: chosen });
    pageTouched = true;
    try {
      localStorage.setItem(STORAGE_KEY, chosen);
    } catch (error) {/* the choice simply does not persist */}
    dropSubjectMarks();
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
      // Editing a finding's pattern leaves the exhibit: what follows is a
      // copy, back in the sandbox, and its address is the home's.
      pageTouched = true;
      if (area === el.pattern && activeFinding) {
        leaveFinding('edit');
      }
      // Before the debounce fires: the field must read as it is typed,
      // and a keystroke ends the caret that said it could be.
      if (area === el.pattern) {
        el.patternEditor.classList.remove('is-live');
        el.patternEditor.classList.remove('is-untouched');
      }
      refreshFields();
      dropSubjectMarks();
      schedule();
    });
    area.addEventListener('scroll', function () {
      area.previousElementSibling.scrollTop = area.scrollTop;
      area.previousElementSibling.scrollLeft = area.scrollLeft;
    });
  });
  // Run says its shortcut in the platform's own words.
  var onApple = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
  el.runKey.textContent = onApple ? '⌘↵' : 'Ctrl ↵';
  el.run.addEventListener('click', function () {
    track('run', { trigger: 'button' });
    pageTouched = true;
    analyzeNow();
  });
  // Ctrl/⌘+Enter runs from anywhere in the window, the fields included.
  root.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
      event.preventDefault();
      track('run', { trigger: 'keyboard' });
      pageTouched = true;
      analyzeNow();
    }
  });

  Array.prototype.forEach.call(el.examples, function (chip) {
    chip.addEventListener('click', function () {
      var exampleName = chip.querySelector('.pg-example-name');
      track('example_loaded', { example_name: exampleName ? exampleName.textContent : '' });
      pageTouched = true;
      leaveFinding('source');
      el.pattern.value = chip.getAttribute('data-regex') || '';
      el.subject.value = chip.getAttribute('data-subject') || '';
      refreshFields();
      dropSubjectMarks();
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
  // A findings page reached with a pattern in its hash is a sandbox link:
  // it opens at home, never under a finding's card.
  if (pageRepo && readHash()) {
    window.location.replace('/' + window.location.hash);
  } else if (fromHash) {
    el.pattern.value = fromHash.regex;
    el.subject.value = fromHash.subject;
    chosen = fromHash.version;
    el.version.value = chosen;
    refreshFields();
    noteCutLink(fromHash);
    setStatus(linkWarning() || 'Loading the engine for the pattern in this link…', linkWarning() ? 'warn' : 'busy');
    el.diagnostics.appendChild(text('li', 'pg-diag is-empty', 'Loading…'));
    setState('Loading…', 'busy');
    analyzeNow();
  } else if (pageRepo) {
    openRepoPage(pageRepo);
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
