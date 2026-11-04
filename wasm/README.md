# wasm/

Native code the documentation site runs in the browser. Today it holds one
module: the PCRE2 trace shim behind the playground's step debugger.

## pcre2-trace/

The step debugger shows how PCRE2 walks a pattern over a subject: every
item the matcher reaches, the cursor, the captures so far, each backtrack
and each new attempt. PCRE2 reports those as callouts when a pattern is
compiled with `PCRE2_AUTO_CALLOUT`; `trace.c` runs one `pcre2_match()`
that way and records every callout into one JSON document.

| file | role |
|---|---|
| `trace.h`, `trace.c` | the shim: `pcre2_trace_run()` returns the JSON, `pcre2_trace_free()` releases it, `pcre2_trace_version()` names the PCRE2 release linked. The same source compiles natively against a system pcre2 and to WebAssembly against the pinned release |
| `trace-cli.c` | the shim as a command, for the test that compares it with `pcre2test` and `preg_match()` on the host's PCRE2 |
| `build.sh` | builds `build/pcre2-10.44.wasm` inside the pinned Emscripten container; `--write-manifest` records the build, `--check` verifies it |
| `manifest.json` | the last build: PCRE2 tag and commit, Emscripten image and digest, the module's size, sha256, exports and imports |
| `pin-traces.sh` | pins `pcre2test`'s own trace of the test cases for one PCRE2 release |
| `php-pcre-options/generate.php` | writes the table of options PHP passes to PCRE2 at each PHP version the playground offers |

The module is not tracked: `bin/playground-bundle` embeds the bytes from
`build/` into the playground's bundle, and the CI job rebuilds it from the
same container and compares the sha256 with the manifest.

### The trace document

```json
{"pcre2": "10.44", "compile": {"error": null},
 "steps": [{"p": 0, "len": 1, "s": 0, "n": 255, "flags": 1, "start": 0, "top": 1, "cap": [null]}],
 "steps_total": 17, "truncated": false,
 "outcome": {"rc": 1, "ovector": [[0, 3], [0, 2]], "error": null, "mark": null}}
```

One step per callout: `p` the byte offset of the next item in the pattern,
`len` its length (0 at the end of the pattern), `s` the cursor in the
subject, `n` the callout number (255 for automatic callouts), `flags`
PCRE2's callout flags (1 a new match attempt, 2 a backtrack before this
item), `start` where the current attempt started, `top` one more than the
highest group set so far, `cap` the offset pairs below `top` (`null` for an
unset group; group 0 stays unset until the match ends) sent only when they
changed since the previous recorded step. `steps_total` counts every
callout; past the step cap, or past 8 MiB of recorded steps (hundreds of
groups rewritten on every step), the shim stops recording and the match
goes on, so `truncated` is true and `outcome` is still the engine's.

`compile.error` carries PCRE2's code, offset and message when the pattern
does not compile (then `steps` is empty and `outcome` null). `outcome.rc`
is `pcre2_match()`'s return: the number of pairs set on a match (`ovector`
holds them), -1 for no match, any other negative value an error with its
code and message (`ovector` is empty: the engine does not write it on
failure). A pattern with an explicit callout `(?Cn)` reports `n` in the
steps it reaches.

### Building the module

The build runs only inside the pinned Emscripten container, so the bytes
are the same on every machine. From the repository root:

```bash
docker run --rm --platform linux/amd64 -v "$PWD:/work" -w /work \
  emscripten/emsdk@sha256:27bc6267cb285223b8aebb7627bfebae7cb3ad2aaa0d5923b8aa5321793033e8 \
  wasm/pcre2-trace/build.sh --write-manifest
```

The script clones PCRE2 at the pinned tag into a temporary directory
outside the tree (or copies the checkout given with `--source <dir>`),
checks the commit, uses the generic configuration (`config.h.generic`,
`pcre2.h.generic`, `pcre2_chartables.c.dist`) after asserting the limits
php-src's bundled copy ships with (heap 20000000, link size 2, match limit
10000000, name size 128, newline LF, nesting 250), and compiles the 8-bit
interpreter sources (no JIT, no DFA) with Unicode support plus `trace.c`
as a standalone module whose only import is Emscripten's memory-growth
notice (`env.emscripten_notify_memory_growth`, a no-op on the page); the
memory grows on demand up to 1 GB. It prints the module's size and
sha256. Run it twice: the sha256 must not move. `--check` exits 1 when the
sha256 differs from `manifest.json`, which is what CI does.

The module is a WASI reactor: a loader calls `_initialize()` once before
any other export, writes the pattern and the subject into memory it takes
from `malloc`, calls `pcre2_trace_run()`, reads the NUL-terminated JSON at
the returned pointer and releases it with `pcre2_trace_free()`.

### Checking the shim against pcre2test

`tests/Documentation/PcreTraceShimTest.php` compiles `trace.c` and
`trace-cli.c` with the host's `cc` against `pcre2-config --cflags --libs8`
(with an rpath to that library, so another libpcre2-8 earlier in the
linker's path cannot answer instead),
then compares the shim's JSON with the trace `pcre2test` itself prints for
the same patterns (`tests/Documentation/Fixtures/pcre2-trace/cases.txt`),
pinned verbatim per PCRE2 release as `<release>.txt`. Pinned releases:

| release | pinned from |
|---|---|
| 10.49 | Homebrew's `pcre2test` on macOS |
| 10.42 | Ubuntu 24.04's `pcre2-utils` package |
| 10.44 | `pcre2test` built from the `pcre2-10.44` tag in `ubuntu:24.04` with `cmake -DPCRE2_BUILD_TESTS=ON -DPCRE2_SUPPORT_JIT=OFF` |

To pin another release, with a `pcre2test` of that release at hand:

```bash
wasm/pcre2-trace/pin-traces.sh 10.45 /path/to/pcre2test
```

The script refuses a binary of another release. A host whose pcre2 has no
pinned file skips the comparison with the release named. After adding a
case to `cases.txt`, re-pin every release and add the case to the test's
table.

### The PHP options table

The debugger traces PCRE2 with the options PHP would pass, and PHP's
wrapper changes between versions: `(?<=a\K)` is allowed from 8.1 to 8.4
and refused in 8.5, the `n` modifier exists from 8.2, `r` from 8.4.
`docs/assets/js/php-pcre-options.js` holds one row per exact PHP version
the playground's engines report, each read from `ext/pcre/php_pcre.c` at
that version's tag and naming the commit. To add a row, with a php-src
checkout at hand:

```bash
php wasm/pcre2-trace/php-pcre-options/generate.php /path/to/php-src php-8.1.28 php-8.2.11 php-8.3.11 php-8.4.1 php-8.5.2
```

The rows are written in the order given. The script reads the modifier
switch, the extra-options define, the `coptions` initialiser, the ini
limits wired to the match context and `pcre_handle_exec_error`, and the
bundled PCRE2 release from `ext/pcre/pcre2lib/pcre2.h`. The lines it reads
are also vendored as excerpts under
`tests/Documentation/Fixtures/php-pcre/<version>.txt`, each headed by the
tag and the commit, so the test re-reads them and compares with the
tracked table without cloning php-src: a new row needs its excerpt too.
Without a checkout, `--from-file <version>=<path>:<commit>:<pcre2>` reads
a saved `php_pcre.c`.
