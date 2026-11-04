'use strict';

// The step debugger's engine: PCRE2 itself — the upstream sources of one
// release, unmodified, compiled to WebAssembly behind a small shim that
// runs one pcre2_match() with automatic callouts and records every step as
// JSON. It lives in its own worker, apart from the PHP engines' one, so a
// pattern that runs to the match limit with a callout per item holds this
// worker alone; the page owns the watchdog and may terminate it at any
// moment, at the price of the module's reload.
//
// The module rides in the playground's bundle (the same file the PHP
// worker fetches, so the browser's cache serves it twice), base64 under a
// "modules" key named by the PCRE2 release, with the sha256 of its bytes.
// The bytes are checked against that sha256 before they are instantiated:
// a module that does not match its checksum is never run.
//
// Messages in:
//
//   { id, type: 'trace', bundleUrl, release, body, subject,
//     compileOptions, extraOptions, matchLimit, depthLimit, stepCap }
//       body and subject are strings, encoded here as UTF-8; the options
//       are the PCRE2 bits as PHP passes them, resolved by the page
//   { id, type: 'version', bundleUrl, release }
//
// Messages out: { id, type: 'result', payload } with payload.protocol 1,
// or { id, type: 'fatal', message }.

const PROTOCOL = 1;

const encoder = new TextEncoder();
const decoder = new TextDecoder();

let bundle = null;       // the parsed bundle, fetched once
let loading = null;      // the module's promise: { release, exports, memory }
let loadedRelease = null;
let job = 0;             // the id of the request in flight, for an uncaught error

async function loadBundle(url) {
  if (!bundle) {
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error('the trace module could not be loaded (HTTP ' + response.status + ')');
    }
    bundle = await response.json();
  }
  return bundle;
}

function fromBase64(text) {
  const binary = atob(text);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes;
}

async function sha256(bytes) {
  if (!self.crypto || !self.crypto.subtle) {
    throw new Error('the trace module could not be verified: this browser offers no SubtleCrypto in a worker');
  }
  const digest = new Uint8Array(await self.crypto.subtle.digest('SHA-256', bytes));
  let hex = '';
  for (let i = 0; i < digest.length; i += 1) {
    hex += (digest[i] < 16 ? '0' : '') + digest[i].toString(16);
  }
  return hex;
}

// The module's one import: Emscripten's memory-growth notice, answered by
// a no-op. The memory grows under malloc and under pcre2_trace_run itself;
// a growth detaches the previous ArrayBuffer, so every read and write
// below takes a fresh view of memory.buffer and keeps none.
const IMPORTS = { env: { emscripten_notify_memory_growth: function () {} } };

// The module of one release: fetched with the bundle, checked against its
// recorded sha256, instantiated with its one import (the memory-growth
// notice above), and initialised once — the module is a WASI reactor and
// _initialize() must run before any other export.
async function module(bundleUrl, release) {
  if (loading && loadedRelease === release) {
    return loading;
  }
  loadedRelease = release;
  loading = (async () => {
    const modules = (await loadBundle(bundleUrl)).modules || {};
    const entry = modules['pcre2-' + release] || modules[release];
    if (!entry || typeof entry.base64 !== 'string') {
      throw new Error('no trace module for PCRE2 ' + release);
    }
    const bytes = fromBase64(entry.base64);
    if (typeof entry.bytes === 'number' && entry.bytes !== bytes.length) {
      throw new Error('the trace module does not match its checksum');
    }
    if ((await sha256(bytes)) !== String(entry.sha256).toLowerCase()) {
      throw new Error('the trace module does not match its checksum');
    }
    const result = await WebAssembly.instantiate(bytes, IMPORTS);
    const exports = result.instance.exports;
    for (const name of ['memory', 'malloc', 'free', 'pcre2_trace_run', 'pcre2_trace_free', 'pcre2_trace_version']) {
      if (!(name in exports)) {
        throw new Error('the trace module exports no ' + name);
      }
    }
    if (typeof exports._initialize === 'function') {
      exports._initialize();
    }
    return { release: entry.release || release, exports: exports, memory: exports.memory };
  })();
  loading.catch(() => {
    // A failed load is not kept: the next request tries again.
    loading = null;
    loadedRelease = null;
  });
  return loading;
}

// Bytes into the module's memory: one allocation, then a fresh view,
// since malloc may grow the memory and detach the previous buffer.
function write(wasm, bytes) {
  const pointer = wasm.exports.malloc(Math.max(1, bytes.length));
  if (!pointer) {
    throw new Error('the trace module is out of memory');
  }
  new Uint8Array(wasm.memory.buffer, pointer, bytes.length).set(bytes);
  return pointer;
}

// The NUL-terminated UTF-8 string at a pointer, read through a view taken
// now: the run that returned the pointer may have grown the memory.
function readString(wasm, pointer) {
  const heap = new Uint8Array(wasm.memory.buffer);
  let end = pointer;
  while (end < heap.length && heap[end] !== 0) {
    end += 1;
  }
  return decoder.decode(heap.subarray(pointer, end));
}

function uint32(value) {
  return Number(value) >>> 0;
}

async function trace(message) {
  const id = message.id;
  job = id;
  try {
    const wasm = await module(message.bundleUrl, String(message.release));
    const body = encoder.encode(String(message.body));
    const subject = encoder.encode(String(message.subject));
    const bodyPointer = write(wasm, body);
    const subjectPointer = write(wasm, subject);
    let json = '';
    try {
      const pointer = wasm.exports.pcre2_trace_run(
        bodyPointer, body.length,
        subjectPointer, subject.length,
        uint32(message.compileOptions), uint32(message.extraOptions),
        uint32(message.matchLimit), uint32(message.depthLimit), uint32(message.stepCap)
      );
      try {
        json = readString(wasm, pointer);
      } finally {
        wasm.exports.pcre2_trace_free(pointer);
      }
    } finally {
      wasm.exports.free(bodyPointer);
      wasm.exports.free(subjectPointer);
    }
    const document = JSON.parse(json);
    if (document && typeof document.fatal === 'string') {
      throw new Error('the trace module failed: ' + document.fatal);
    }
    self.postMessage({ id: id, type: 'result', payload: Object.assign({ protocol: PROTOCOL, release: wasm.release }, document) });
  } catch (error) {
    self.postMessage({ id: id, type: 'fatal', message: String((error && error.message) || error) });
  }
}

async function version(message) {
  const id = message.id;
  job = id;
  try {
    const wasm = await module(message.bundleUrl, String(message.release));
    const pointer = wasm.exports.pcre2_trace_version();
    self.postMessage({ id: id, type: 'result', payload: { protocol: PROTOCOL, version: readString(wasm, pointer) } });
  } catch (error) {
    self.postMessage({ id: id, type: 'fatal', message: String((error && error.message) || error) });
  }
}

// One request at a time: the page sends the next when this one answered.
let chain = Promise.resolve();

self.onmessage = (event) => {
  const message = event.data;
  if (!message || typeof message.type !== 'string') {
    return;
  }
  switch (message.type) {
    case 'trace':
      chain = chain.then(() => trace(message));
      return;
    case 'version':
      chain = chain.then(() => version(message));
      return;
  }
};

self.addEventListener('error', (event) => {
  self.postMessage({ id: job, type: 'fatal', message: 'trace worker error: ' + event.message + ' @ ' + event.filename + ':' + event.lineno });
});
self.addEventListener('unhandledrejection', (event) => {
  self.postMessage({ id: job, type: 'fatal', message: 'trace worker rejection: ' + String(event.reason) });
});
