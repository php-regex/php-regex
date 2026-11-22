# wasm/

Native code the documentation site runs in the browser: the PCRE2 trace
shim behind the playground's step debugger, built once per PCRE2 release the
playground's PHP engines bundle, and the PHP 8.6 engine itself, which
php-wasm does not publish yet.

## pcre2-trace/

The step debugger shows how PCRE2 walks a pattern over a subject: every
item the matcher reaches, the cursor, the captures so far, whether it
backtracked since the previous item (a flag, never a count) and each new
attempt. PCRE2 reports those as callouts when a pattern is
compiled with `PCRE2_AUTO_CALLOUT`; `trace.c` runs one `pcre2_match()`
that way and records every callout into one JSON document.

| file | role |
|---|---|
| `trace.h`, `trace.c` | the shim: `pcre2_trace_run()` returns the JSON, `pcre2_trace_free()` releases it, `pcre2_trace_version()` names the PCRE2 release linked. The same source compiles natively against a system pcre2 and to WebAssembly against each pinned release |
| `trace-cli.c` | the shim as a command, for the test that compares it with `pcre2test` and `preg_match()` on the host's PCRE2 |
| `build.sh` | builds `build/pcre2-<release>.wasm` for one release inside the pinned Emscripten container; `--write-manifest` records the build, `--check` verifies it |
| `manifest.json` | the last build of each release: PCRE2 tag and commit, patches, Emscripten image and digest, the module's size, sha256, exports and imports |
| `patches/` | the patches php-src applies to its bundled copy of a release, applied by `build.sh` on top of the tag |
| `pin-traces.sh` | pins `pcre2test`'s own trace of the test cases for one PCRE2 release |
| `php-pcre-options/generate.php` | writes the table of options PHP passes to PCRE2 at each PHP version the playground offers |

The releases, by the playground's engines:

| PCRE2 | engines | patches |
|---|---|---|
| 10.35 | PHP 8.0.30 | none |
| 10.39 | PHP 8.1.28 | `gh-11374` |
| 10.40 | PHP 8.2.11 | none |
| 10.42 | PHP 8.3.11 | `gh-11374` |
| 10.44 | PHP 8.4.1, 8.5.2, 8.6.0 | none |

`gh-11374` is php-src's backport of PCRE2 10.43's fix for the captures an
interpreter restores after a recursion (php-src commit `83a505e85f9`),
carried in php-src's bundled 10.39 from 8.1.26 and in its bundled 10.42 from
8.3.0; with it, each module's library sources equal php-src's bundled copy
byte for byte.

The `build/` directory is not tracked: `bin/playground-bundle` copies each
module from it to `docs/assets/playground/pcre2-<release>.wasm`, served
beside the playground's bundle, and the CI job rebuilds every release from
the same container and compares the sha256 with the manifest.

### The trace document

The module's own answer for `a(b)` on `xab` (start optimizations skip
offset 0, where no `a` stands):

```json
{"pcre2": "10.44", "compile": {"error": null},
 "steps": [{"p": 0, "len": 1, "s": 1, "n": 255, "flags": 1, "start": 1, "top": 1, "cap": [null]},
           {"p": 1, "len": 1, "s": 2, "n": 255, "flags": 0, "start": 1, "top": 1},
           {"p": 2, "len": 1, "s": 2, "n": 255, "flags": 0, "start": 1, "top": 1},
           {"p": 3, "len": 1, "s": 3, "n": 255, "flags": 0, "start": 1, "top": 1},
           {"p": 4, "len": 0, "s": 3, "n": 255, "flags": 0, "start": 1, "top": 2, "cap": [null, [2, 3]]}],
 "steps_total": 5, "truncated": false,
 "outcome": {"rc": 2, "ovector": [[1, 3], [2, 3]], "error": null, "mark": null}}
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
is `pcre2_match()`'s return: on a match, one more than the highest group
set (`ovector` holds that many pairs, `null` for a group left unset below
it: `(a)?(b)` on `b` gives 3 and `[[0, 1], null, [0, 1]]`), -1 for no match, any other negative value an error with its
code and message (`ovector` is empty: the engine does not write it on
failure). A pattern with an explicit callout `(?Cn)` reports `n` in the
steps it reaches.

### Building the module

The build runs only inside the pinned Emscripten container, so the bytes
are the same on every machine. From the repository root:

```bash
docker run --rm --platform linux/amd64 -v "$PWD:/work" -w /work \
  emscripten/emsdk@sha256:27bc6267cb285223b8aebb7627bfebae7cb3ad2aaa0d5923b8aa5321793033e8 \
  wasm/pcre2-trace/build.sh 10.44 --write-manifest
```

The script clones PCRE2 at the release's pinned tag into a temporary
directory outside the tree (or copies the checkout given with
`--source <dir>`), checks the commit, applies the release's patches, uses
the generic configuration (`config.h.generic`, `pcre2.h.generic`,
`pcre2_chartables.c.dist`) after asserting the limits php-src's bundled
copy ships with (heap 20000000, link size 2, match limit 10000000, name size
32 up to 10.42 and 128 from 10.44, newline LF, nesting 250), and compiles
the 8-bit interpreter sources (no JIT, no DFA; before 10.42,
`pcre2_jit_compile.c` too, built without JIT for the stub
`pcre2_code_free()` calls) with Unicode support plus `trace.c`
as a standalone module whose only import is Emscripten's memory-growth
notice (`env.emscripten_notify_memory_growth`, a no-op on the page); the
memory grows on demand up to 1 GB. It prints the module's size and
sha256. Run it twice: the sha256 must not move. `--check` exits 1 when the
sha256 differs from the release's entry in `manifest.json`, which is what CI
does, once per release.

The module is a WASI reactor: a loader calls `_initialize()` once before
any other export, writes the pattern and the subject into memory it takes
from `malloc`, calls `pcre2_trace_run()`, reads the NUL-terminated JSON at
the returned pointer and releases it with `pcre2_trace_free()`, then
releases the pattern and the subject with `free()`, on an error too
(`docs/assets/js/trace-worker.js` does so in a `finally`).

### Checking the shim against pcre2test

`tests/Documentation/PcreTraceShimTest.php` compiles `trace.c` and
`trace-cli.c` with the host's `cc` against `pcre2-config --cflags` and the
static `libpcre2-8.a` beside the headers (so another libpcre2-8 earlier in
the dynamic linker's path cannot answer instead; `--libs8` when there is no
archive),
then compares the shim's JSON with the trace `pcre2test` itself prints for
the same patterns (`tests/Documentation/Fixtures/pcre2-trace/cases.txt`),
pinned verbatim per PCRE2 release as `<release>.txt`. Pinned releases:

| release | pinned from |
|---|---|
| 10.49 | Homebrew's `pcre2test` on macOS |
| 10.35, 10.40, 10.44 | `pcre2test` built from the release's tag in `ubuntu:24.04` with `cmake -DPCRE2_BUILD_TESTS=ON -DPCRE2_SUPPORT_JIT=OFF` |
| 10.39, 10.42 | the same, with `patches/gh-11374.patch` applied (10.42's matches Ubuntu 24.04's `pcre2-utils` package line for line) |

Before 10.38 a `\K` in a lookaround needs no option and `pcre2test` knows
no `allow_lookaround_bsk` modifier, so `pin-traces.sh` drops the modifier
for those releases, which asks them for what it asks the later ones.

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
and refused in 8.5 (8.0's bundled 10.35 predates the option and passes no
bit), the `n` modifier exists from 8.2, `r` from 8.4.
`docs/assets/js/php-pcre-options.js` holds one row per exact PHP version
the playground's engines report, each read from `ext/pcre/php_pcre.c` at
that version's tag and naming the commit. The generator writes only the
tags it is given, so the command lists every row the table keeps: a new
minor adds its tag, and an engine php-wasm upgrades (8.4.1 to 8.4.2)
replaces its old tag, never beside it. With a php-src checkout at hand:

```bash
php wasm/pcre2-trace/php-pcre-options/generate.php /path/to/php-src php-8.0.30 php-8.1.28 php-8.2.11 php-8.3.11 php-8.4.1 php-8.5.2 php-8.6.0 php-<new version>
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

## php-wasm/

The playground and the Run buttons run PHP compiled to WebAssembly by
[php-wasm](https://github.com/seanmorris/php-wasm). Its 0.2.0 packages stop
at PHP 8.5.2 and its Makefile refuses 8.6, so the PHP 8.6 engine is built
here from php-wasm's v0.2.0 sources with 8.6.0 added, and the site serves
it beside the page. Every other version still loads from jsDelivr.

| file | role |
|---|---|
| `build.sh` | builds the engine of one PHP version into `build/` inside php-wasm's own builder image; `--write-manifest` records the build, `--check` verifies it |
| `patches/php8.6.patch` | php-wasm's `patch/php8.5.patch` ported to php-src 8.6.0, applied by php-wasm's own patch step |
| `patches/php-wasm-8.6.patch` | the v0.2.0 tree with 8.6 added: the Makefile's version lists and 8.6.0 tag, `PhpWeb.mjs`'s `8.6` case, mbstring's `8.6.mjs` |
| `patches/vrzno-8.6.patch` | vrzno, php-wasm's JavaScript bridge at the commit php-wasm pins, ported to 8.6: `offsetof()` for the removed `XtOffsetOf()`, and `php_stream_wrapper_log_error()`'s new arguments as php-src's `UPGRADING.INTERNALS` gives them |
| `manifest.json` | the last build: php-src tag and commit, the PCRE2 release it bundles, php-wasm tag and commit, each of the three patches' sha256, the Emscripten image and digest, the build date stamped into PHP, and every served file's size and sha256 |

The port of the php-src patch differs from 8.5's in what php-src 8.6.0
moved: `zend_free_internal_arg_info()` takes a second argument, `persistent`
(`true` for an internal class's methods), the CLI's `main()` moved to
`sapi/cli/php_cli_main.c` and returns its status, so php-wasm's
`wasm_sapi_cli_main()` calls `do_php_cli()` and the `#ifdef __EMSCRIPTEN__`
around `exit()` is gone, and the hunks of `zend_API.h`, `credits_ext.h`,
`tokenizer.c` and `phpdbg_io.c` apply at new places (the new licence headers,
the new credits). Every other hunk applies as it is. vrzno, which
`PhpBase.mjs` calls for the values a page shares with PHP, is compiled from
the commit php-wasm pins with its port applied, handed to php-wasm's import
step as a development checkout (`VRZNO_DEV_PATH`).

### Building the engine

Docker is the only requirement; the build runs in containers only. From the
repository root:

```bash
wasm/php-wasm/build.sh 8.6.0 --write-manifest
```

The script builds php-wasm's builder image from its
`emscripten-builder.dockerfile` at the pinned tag, on the Emscripten 6.0.6
image pinned by digest (`linux/amd64`), then runs inside it: it clones
php-wasm, php-src and oniguruma at their tags and vrzno at the commit
php-wasm pins, checks each commit, applies the patches, and runs php-wasm's
own recipe with the configuration
its CI publishes the packages from (`.github/.env_8.5.dynamic.ci`, with the
version changed): the libraries PHP's configure links against, the web
loader `php8.6-web.mjs` and its module, mbstring and oniguruma as shared
objects, then php-wasm's compress step, which names the module after its
sha1. PHP stamps its build date and the build machine's `uname` into the
binary: the date is the php-src tag commit's (`SOURCE_DATE_EPOCH`) and the
`uname` a fixed string, so a rebuild gives the same bytes. Run it twice; the
sha256s must not move. On Apple silicon the image is emulated, and the
builder image and the engine take about an hour each.

`build/` is not tracked: `bin/playground-bundle` copies each file from it,
only when its size and sha256 are the manifest's, to `docs/assets/php-wasm/`
and `docs/assets/php-wasm-mbstring/`, the layout of the two npm packages, so
`PhpWeb.mjs` and mbstring's `index.mjs` load as they do from jsDelivr, with
the same module API. Without a build, the bundle requires the served files
to be the pinned ones, and changes nothing. The served files are marked
`-text` in `.gitattributes`, so git never rewrites them, and Jekyll is told
to keep `_Event.mjs`, which `PhpBase.mjs` imports.

php-wasm is Apache-2.0 (or GPL-2.0): its `LICENSE`, `LICENSE-GPL` and
`NOTICE` travel in both served directories, and the playground's footer
credits it.

### Checking the engine

`tests/Documentation/PlaygroundPhpEngineTest.php` holds the served files to
the manifest, the manifest to `build.sh` and to the options table's 8.6.0
row (the same php-src commit, the same PCRE2 release), and the worker to its
split. `bin/playground-check` runs the served web build under Node as the
worker runs it, on every outcome and probe witness the other engines answer,
and asks it three questions PHP 8.6.0 and the 8.5.2 engine answer apart, as
the native `php:8.6.0-cli` and `php:8.5.2-cli` images do: `/\C/u` does not
compile (8.6 adds `PCRE2_NEVER_BACKSLASH_C` under `u`), a group named `MARK`
beside a `(*MARK:x)` leaves one `MARK` key, and `preg_grep()` over a
malformed subject under `u` returns `false` instead of the entries before
it. The engine's PCRE2 is 10.44, so the step debugger traces it with the
`pcre2-10.44` module the 8.4 and 8.5 engines already use, with the 8.6.0
row's options.
