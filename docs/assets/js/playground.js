'use strict';

// The home page's playground. A pattern, a subject and a PHP version go to
// the engine worker (run-worker.js, the same worker as the tutorial's Run
// buttons), where a PHP compiled to WebAssembly runs PHPRegex on the pattern
// and preg_match_all() on the subject. What comes back is one JSON document
// per request; this script renders it, and nothing else: every string the
// visitor typed, or the engine answered, reaches the page through
// textContent.
//
// Before the first run gesture the panels show the sample the site ships
// as data, rendered by the very same functions as a live answer. The engine
// (about five megabytes once, then cached) is downloaded on a run gesture
// only: typing, the Run button, the version select, an example, or a link
// that carries a pattern in its hash. Never on load, never on focus.
//
// Offsets: the library counts bytes of the pattern body, the browser counts
// UTF-16 units of the whole field. The engine returns the table that maps
// one to the other, and the matches already converted; this script only
// looks up.

(function () {
  var root = document.getElementById('playground');
  var tree = document.getElementById('pg-tree'); // always open, beside the answers
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
  var PROTOCOL = 1;               // the driver's contract this script renders
  var JSON_DUMPER = 1;            // the tree document this script walks
  var ANNOUNCE_IDLE_MS = 1000;

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
    patternMirror: root.querySelector('.pg-editor-pattern .pg-mirror'),
    patternCount: root.querySelector('#pg-pattern-count'),
    subject: root.querySelector('#pg-subject'),
    subjectMirror: root.querySelector('.pg-editor-subject .pg-mirror'),
    subjectCount: root.querySelector('#pg-subject-count'),
    version: root.querySelector('#pg-version'),
    run: root.querySelector('.pg-run'),
    examples: root.querySelectorAll('.pg-example'),
    status: root.querySelector('.pg-status'),
    matchList: root.querySelector('.pg-match-list'),
    matchesNote: root.querySelector('.pg-matches-note'),
    matchesEmpty: root.querySelector('.pg-matches-empty'),
    explain: root.querySelector('.pg-explain'),
    diagnostics: root.querySelector('.pg-diagnostics'),
    lintNote: root.querySelector('.pg-lint-note'),
    facts: root.querySelector('.pg-facts'),
    state: root.querySelector('.pg-state'),
    caption: document.querySelector('.pg-caption'),
    live: root.querySelector('.pg-live'),
    treePattern: tree.querySelector('.tree-pattern'),
    treeNodes: tree.querySelector('.tree-nodes'),
    treeDetail: tree.querySelector('.tree-detail'),
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

  function setStatus(message, tone) {
    el.status.textContent = message || '';
    el.status.className = 'pg-status' + (tone ? ' is-' + tone : '');
  }

  // The word in the title bar: what the window is doing right now.
  function setState(word, tone) {
    el.state.textContent = word;
    el.state.className = 'pg-state' + (tone ? ' is-' + tone : '');
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
    area.style.height = 'auto';
    area.style.height = area.scrollHeight + 'px';
    var mirror = area.previousElementSibling;
    if (mirror) {
      mirror.style.height = area.style.height;
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
    return request.type + '\u0000' + request.version + '\u0000' + request.regex + '\u0000' + (request.subject || '') + '\u0000' + (request.index === undefined ? '' : request.index);
  }

  function send(request) {
    if (!worker) {
      worker = createWorker();
      if (!worker) {
        setStatus('This browser cannot run the engine: it has no module workers.', 'error');
        setState('Stopped', 'error');
        return false;
      }
    }
    var id = nextId++;
    var message = Object.assign({ id: id, bundleUrl: bundleUrl }, request);
    inflight = { id: id, type: request.type, request: request };
    el.run.disabled = true;
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
    if (failures[key] < 2) {
      setStatus(reason + ' Starting the engine again…', 'warn');
      setState('Restarting…', 'busy');
      send(request);
      return;
    }
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
      el.matchesEmpty.hidden = false;
      el.matchesEmpty.textContent = 'The engine did not finish the match within ' + REQUEST_TIMEOUT_MS / 1000 + ' s. The analysis above stands.';
      setStatus(reason + ' The match could not finish on this input; the analysis stands.', 'warn');
    } else if (request.type === 'describe') {
      describeWanted = -1;
      setStatus('', '');
    } else {
      setStatus(reason + ' The engine could not finish on this input; the last answer stays below. Change the pattern or the subject to try again.', 'error');
    }
    announce('The engine stopped on this input.');
    drain();
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
      send(next);
      return;
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
      setStatus((STAGES[message.stage] || 'Loading…').replace('{v}', request.version), 'busy');
      setState('Loading…', 'busy');
      return;
    }
    if (message.type === 'ready') {
      booted = true;
      setState('Running…', 'busy');
      window.clearTimeout(timers[message.id]);
      timers[message.id] = window.setTimeout(function () {
        fail(request, 'The engine did not answer within ' + REQUEST_TIMEOUT_MS / 1000 + ' s.');
      }, REQUEST_TIMEOUT_MS);
      setStatus(request.type === 'analyze' ? 'Running on PHP ' + request.version + '…' : (request.type === 'match' ? 'Matching on PHP ' + request.version + '…' : ''), 'busy');
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
    if (typeof payload.fatal === 'string') {
      // The driver caught something: the last answer stays, the status says.
      setStatus('The engine failed on this request: ' + payload.fatal, 'error');
      if (request.type === 'match' && current) {
        matchFor = requestKey(request);
      } else if (request.type === 'lint' && current && current.regex === request.regex) {
        lintFor = request.regex;
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
        renderMatches(current.payload, request.subject);
        renderErrors(current.payload);
        if (lastLint) {
          renderLint(lastLint);
        }
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
    }
    drain();
  }

  // --- requests ------------------------------------------------------------

  var debounce = null;

  function schedule() {
    window.clearTimeout(debounce);
    debounce = window.setTimeout(analyzeNow, DEBOUNCE_MS);
  }

  function analyzeNow() {
    window.clearTimeout(debounce);
    var request = { type: 'analyze', regex: el.pattern.value, subject: el.subject.value, version: chosen };
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
    }
    current = { payload: payload, regex: request.regex, subject: request.subject, version: request.version, prerendered: prerendered };
    if (!prerendered) {
      // A fresh analysis asks for a fresh match, and gives a phase that was
      // given up another chance: pressing Run is how a visitor retries.
      matchFor = null;
      delete failures[requestKey({ type: 'match', regex: request.regex, subject: request.subject, version: request.version })];
      if (lastLint === null) {
        lintFor = null;
        delete failures[requestKey({ type: 'lint', regex: request.regex, version: request.version })];
      }
    }
    if (!prerendered && payload.matches === null && payload.engineError === null) {
      payload.matches = { pending: true };
    }
    renderCaption(payload, prerendered);
    renderPatternMirror(payload, request.regex, null);
    renderMatches(payload, request.subject);
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
      el.caption.appendChild(text('span', 'pg-caption-sample', 'Sample rendered ahead of time. Press Run, or type, to run the PHP in your browser.'));
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

  // The pattern field's mirror: the error position underlined, lint spans
  // dotted, the tree's current node highlighted.
  function renderPatternMirror(payload, regex, lint) {
    var ranges = [];
    (payload.errors || []).forEach(function (error) {
      var range = unitRange(payload, error.position, null);
      if (range) {
        ranges.push({ start: range.start, end: range.end, className: 'pg-mark-error', title: error.message });
      }
    });
    (lint || []).forEach(function (finding) {
      var range = unitRange(payload, finding.offset, finding.end);
      if (range) {
        ranges.push({ start: range.start, end: range.end, className: 'pg-mark-lint', title: finding.message });
      }
    });
    if (currentNode >= 0 && nodes[currentNode]) {
      var span = unitRange(payload, nodes[currentNode].start, nodes[currentNode].end);
      if (span) {
        ranges.unshift({ start: span.start, end: span.end, className: 'pg-mark-node' });
      }
    }
    clear(el.patternMirror);
    el.patternMirror.appendChild(markRanges(regex, dedupe(ranges)));
  }

  // Overlapping ranges would hide each other; the node highlight wins, then
  // the error, then lint.
  function dedupe(ranges) {
    var priority = { 'pg-mark-node': 0, 'pg-mark-error': 1, 'pg-mark-lint': 2 };
    ranges.sort(function (a, b) {
      return priority[a.className] - priority[b.className];
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

  function renderMatches(payload, subject) {
    clear(el.matchList);
    el.matchesNote.textContent = '';
    el.matchesEmpty.hidden = true;
    var matches = payload.matches;
    var ranges = [];

    if (!matches) {
      el.matchesEmpty.hidden = false;
      el.matchesEmpty.textContent = payload.engineError ? 'The engine refused the pattern: ' + payload.engineError : 'Nothing to match: the pattern does not compile.';
    } else if (matches.pending) {
      el.matchesEmpty.hidden = false;
      el.matchesEmpty.textContent = 'Matching on PHP ' + (current ? current.version : '') + '…';
    } else if (matches.count === 0) {
      el.matchesEmpty.hidden = false;
      el.matchesEmpty.textContent = matches.error && matches.error !== 'No error' ? 'No match: ' + matches.error + '.' : 'No match.';
      el.matchesNote.textContent = '0';
    } else {
      el.matchesNote.textContent = matches.shown < matches.count ? 'first ' + matches.shown + ' of ' + matches.count : String(matches.count);
      var names = Object.assign({}, matches.names || {}, payload.groupNames || {});
      matches.sets.forEach(function (set, i) {
        var whole = set['0'];
        var item = text('li', 'pg-match');
        var head = text('div', 'pg-match-head');
        head.appendChild(text('span', 'pg-match-n', '#' + (i + 1)));
        var value = text('code', 'pg-match-value', whole ? whole[0] : '');
        head.appendChild(value);
        if (whole) {
          head.appendChild(text('span', 'pg-match-at', 'at ' + whole[1] + (whole[1] !== whole[2] ? ' (byte) · ' + whole[2] + ' (char)' : '')));
          ranges.push({ start: whole[2], end: whole[2] + whole[0].length, className: 'pg-mark-match' + (i % 2 ? ' is-alt' : ''), index: i });
        }
        item.appendChild(head);
        var groups = Object.keys(set).filter(function (key) {
          return key !== '0';
        });
        if (groups.length) {
          var list = text('dl', 'pg-groups');
          groups.forEach(function (key) {
            var capture = set[key];
            var label = key;
            if (names[key]) {
              label = names[key] + ' (' + key + ')';
            }
            list.appendChild(text('dt', 'pg-group-name', label));
            var dd = text('dd', 'pg-group-value');
            dd.appendChild(text('code', null, capture[0]));
            dd.appendChild(text('span', 'pg-match-at', ' at ' + capture[2]));
            list.appendChild(dd);
          });
          item.appendChild(list);
        }
        item.addEventListener('mouseenter', function () {
          highlightMatch(i, true);
        });
        item.addEventListener('mouseleave', function () {
          highlightMatch(i, false);
        });
        el.matchList.appendChild(item);
      });
    }
    clear(el.subjectMirror);
    el.subjectMirror.appendChild(markRanges(subject, ranges));
    if (payload.errors && payload.errors.length) {
      announce((payload.errors[0].source === 'validate' ? 'Invalid for this engine: ' : 'Syntax error: ') + payload.errors[0].message);
    } else if (matches && !matches.pending) {
      announce(matches.count === 1 ? '1 match' : matches.count + ' matches');
    } else if (!matches && payload.engineError) {
      announce('The engine refused the pattern: ' + payload.engineError);
    }
  }

  function highlightMatch(index, on) {
    var marks = el.subjectMirror.querySelectorAll('mark[data-index="' + index + '"]');
    Array.prototype.forEach.call(marks, function (mark) {
      mark.classList.toggle('is-current', on);
    });
  }

  // The explanation, one line per construct, indented two spaces per level,
  // rebuilt as nested lists.
  function renderExplain(explain) {
    clear(el.explain);
    if (!explain) {
      el.explain.appendChild(text('p', 'pg-empty', 'No explanation: the pattern does not parse.'));
      return;
    }
    var lines = explain.split('\n');
    var stack = [{ depth: -1, list: text('ul', 'pg-explain-list') }];
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
      var item = text('li', 'pg-explain-item', content);
      stack[stack.length - 1].list.appendChild(item);
      var sublist = text('ul', 'pg-explain-list');
      item.appendChild(sublist);
      stack.push({ depth: depth, list: sublist });
    });
    el.explain.appendChild(stack[0].list);
    Array.prototype.forEach.call(el.explain.querySelectorAll('ul:empty'), function (empty) {
      empty.parentNode.removeChild(empty);
    });
  }

  var SEVERITY_LABELS = { critical: 'critical', error: 'error', warning: 'warning', style: 'style', perf: 'performance', info: 'info' };

  function renderErrors(payload) {
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
    showDetail(-1);
    if (!payload.ast) {
      el.treeDetail.textContent = (payload.errors && payload.errors[0]) ? 'No tree: ' + payload.errors[0].message : 'No tree.';
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
    // it and lighting a node lights its whole span.
    var base = payload.delimited ? payload.unitOf[payload.delimited.bodyStart] : 0;
    var bodyUnits = payload.delimited ? regex.slice(base, payload.unitOf[payload.delimited.bodyEnd]) : (payload.ast.source || '');
    el.treePattern.appendChild(pieceOf(payload, 0, bodyUnits, base));
    el.treePattern.appendChild(document.createTextNode('\n'));
  }

  function pieceOf(payload, index, body, base) {
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
        span.appendChild(document.createTextNode(body.slice(at, childFrom)));
      }
      span.appendChild(pieceOf(payload, childIndex, body, base));
      at = Math.max(at, (childEntry.end > childEntry.start ? childRange.end : childRange.start) - base);
      childIndex += subtreeSize(childIndex);
    });
    if (to > at) {
      span.appendChild(document.createTextNode(body.slice(at, to)));
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
    area.addEventListener('input', function () {
      refreshFields();
      schedule();
    });
    area.addEventListener('scroll', function () {
      area.previousElementSibling.scrollTop = area.scrollTop;
      area.previousElementSibling.scrollLeft = area.scrollLeft;
    });
  });
  el.run.addEventListener('click', analyzeNow);

  Array.prototype.forEach.call(el.examples, function (chip) {
    chip.addEventListener('click', function () {
      el.pattern.value = chip.getAttribute('data-regex') || '';
      el.subject.value = chip.getAttribute('data-subject') || '';
      refreshFields();
      analyzeNow();
    });
  });

  // The deepest item under the pointer, the focus or the click is the one
  // meant: items nest, and an event on a child reaches its parents too.
  ['mouseover', 'focusin', 'click'].forEach(function (type) {
    el.treeNodes.addEventListener(type, function (event) {
      var item = event.target.closest('[role="treeitem"]');
      if (item) {
        setCurrentNode(Number(item.getAttribute('data-index')));
      }
    });
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
      var index = Number(mark.getAttribute('data-index'));
      setCurrentNode(index);
      if (nodes[index]) {
        nodes[index].button.scrollIntoView({ block: 'nearest' });
      }
    }
  });

  window.addEventListener('resize', refreshFields);

  // --- first paint ---------------------------------------------------------

  var fromHash = readHash();
  if (fromHash) {
    el.pattern.value = fromHash.regex;
    el.subject.value = fromHash.subject;
    chosen = fromHash.version;
    el.version.value = chosen;
    refreshFields();
    setStatus(fromHash.cut ? 'The subject in this link was cut at ' + SUBJECT_CAP + ' bytes. Loading the engine…' : 'Loading the engine for the pattern in this link…', 'busy');
    el.matchesEmpty.hidden = false;
    el.matchesEmpty.textContent = 'Loading PHP ' + chosen + '…';
    el.diagnostics.appendChild(text('li', 'pg-diag is-empty', 'Loading…'));
    analyzeNow();
  } else if (sample && sample.analyze && sample.default) {
    el.pattern.value = sample.default.regex;
    el.subject.value = sample.default.subject;
    refreshFields();
    render(sample.analyze, { regex: sample.default.regex, subject: sample.default.subject, version: chosen }, true);
    renderLint(sample.lint ? sample.lint.lint : null);
  } else {
    refreshFields();
  }
})();
