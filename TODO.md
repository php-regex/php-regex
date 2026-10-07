# TODO

## Split into packages: what is left by hand

State on 2026-10-07, read from GitHub and Packagist:

- The 16 read-only repositories exist (`regex-rector` and `regex-psalm` since
  2026-10-07) and `.github/workflows/split.yml` pushes `2.x` to every one of
  them; the 16 packages and `php-regex/php-regex` are on Packagist
  (`regex-psalm` as a `psalm-plugin`, `regex-rector` as a
  `rector-extension`).
- `yoeunes/regex-parser` now reads `https://github.com/php-regex/php-regex`,
  as `php-regex/php-regex` does, and `php-regex/regex-parser` holds `2.x`
  only.

Left:

1. **`SPLIT_TOKEN`** — give the fine-grained token *Workflows: read and
   write* as well as *Contents*: GitHub refuses a push that creates or
   changes a file under `.github/workflows/` without it, and every split
   package carries `close-pull-request.yml`. The first push of
   `regex-rector` and `regex-psalm` failed for that reason and was made over
   SSH; a change to that workflow file would fail every split the same way.
2. **The `v2.0.0` tag** — `yoeunes/regex-parser` and `php-regex/php-regex`
   read the same repository, so a `v2.0.0` tag on the monorepo becomes a
   stable 2.0.0 of both, and a bare `composer require yoeunes/regex-parser`
   would install 2.x. Mark `yoeunes/regex-parser` abandoned in favour of
   `php-regex/regex-toolkit`, or point it at a repository holding 1.x only,
   before the tag.
3. **Packagist** — check that updates on push are on for every package (the
   GitHub integration on the Packagist account, or a webhook per
   repository): without them Packagist crawls a repository about once a
   week.

## Before 2.0.0: known defects to fix

Found while auditing the lint output over the corpus and while reworking the
ReDoS proof and the lexer (October 2026). Each one is confirmed against
`preg_match()` or measured, and predates the fixes made then. 2.0.0 freezes
the public API, so they go before the tag.

### Next steps

- ~~Merge the `before-2-0-defects` branch (frozen surfaces, then parser
  errors) into `2.x` and push.~~ (done: a48c101d, 8c03d93d)
- Still to fix from this list, in this order: display, printer, language
  server and transpilers (comment bytes, printer round trips, LSP hover
  and completion, `\k'n'` in JavaScript); linter and extractors
  (character sets, `.*+` under `/x`, newline conventions in anchors,
  Latin-1 re-encoding, Symfony block lists, `\N{U+…}` warning), then
  regenerate the corpus files; ReDoS precision (atomic unions, `xx`, the
  empty witness suffix).
- Then the lookbehind validation, name reader and error-order entries
  below, found while fixing the parser errors.

### The character-set analysis

- Sets cover bytes 0x00-0x7F only: a dot or a negated class never meets a
  byte above it. The nested-loop rules now refuse to decide in that case, but
  `regex.lint.quantifier.concatenation` still suggests rewrites that change
  the matches (`/^b+[é]+\z/u`, `/^\h+[\t ]+\z/` on a no-break space).
- Case-insensitivity and lookarounds are ignored: `/(?:a|A)+$/i` (exponential)
  gets no overlap warning, `(?:,a*(?:(?!z)a)*)+$` no nested warning, and
  `/^A?[^a]*\z/i` a concatenation hint that loses `"a"`.
- `(*UCP)` without `/u` is not seen: `\w` and `\d` stay ASCII.

### The ReDoS proof: precision

Sound in both cases, but verdicts the proof could give:

- It does not read an atomic or possessive alternation of one-character
  branches as the union of their sets: `/^(?>z(?i)|a|A)*$/` and
  `/^(?>a|a)+$/` get a heuristic verdict where the engine is linear, a gain
  to make with its own soundness check against the engine.
- It steps out of its model at the first `xx` option:
  `/^(?xx)(?x)(?:[ a]|\x20)*$/` (exponential on the engine) and
  `/^(?xx)(?:[a b]|\x20)*$/` (linear) both get a heuristic verdict. Reading
  `xx` as the automata solver does (a lone `x` clears it, a class drops its
  space and tab) would prove both.

### Byte mode and the Unicode-normalization invariant

Checked whether the library should model Unicode normalization (NFC vs NFD),
the way a text component would (October 2026). It should not: PCRE2 never
normalizes, and neither does the library — a pattern written in NFC never
matches an NFD subject (`/é/u` against `"e\u{0301}"`, `/ui` included),
`/^\p{L}+$/u` does not match a decomposed é (U+0301 is `\p{M}`), and
`/ß/iu` does not match `"ss"` (simple folding only). That absence is the
contract, and it must stay. What the check found instead is byte-versus-
code-point confusions in byte mode (patterns without `/u`), each confirmed
against the engine:

- Auto-possessivation compares the last byte of a literal with the first
  byte of what follows: under `/u` a continuation byte never meets a lead
  byte, so every multibyte boundary looks exclusive (`/é+é/u` would become
  `/é++é/u`, which matches nothing). The shipped optimizer rejects the
  rewrite through its equivalence check; the rewriter alone does not.
- The ReDoS adjacency analysis uses the same byte test, without the
  multibyte quarantine the nested-loop lint rules have: it is skipped
  whenever the seam bytes differ, which under `/u` happens across every
  multibyte character even when the code points on both sides coincide
  (`(?:xé)+é+` has é on both sides of the seam). No false "safe" has been
  reproduced yet; the gate itself is unsound under `/u`.
- Without `/u`, the transpilers re-read bytes ≥ 0x80 as code points of the
  target: JavaScript `/caf\xC3\xA9/` matches `"Ã©"` where PCRE matched the
  two raw bytes, and the Python output can hold a lone invalid byte whose
  source does not parse at all.

The normalization-free contract itself is untested: no decomposed pattern
anywhere in the suite, and the multibyte round-trip rows (`[«»“”]`,
`[\¡\¿]`) have no canonical decomposition, so a `Normalizer::normalize()`
slipped into any layer would stay green. Worth adding: a round-trip row for
`"/e\u{0301}/u"`, an engine row (NFC pattern, NFD subject: no match, `/ui`
included), and a solver row refusing `/é/u` ≡ `/e\u{301}/u`.

Smaller, same family: `mb_strlen()` on a class atom without an explicit
encoding (the range-start check); the compiled-size floor compares code
points through mbstring's lowercase, which misses case pairs PCRE2 folds
(Greek `[ςσ]` is not seen as one); mbstring's full mappings (`ß` → `SS`,
`İ` → two code points) only ever feed boolean checks, but its tables can
drift from PCRE2's, and the `/i`-useless rule has no locale guard where a
host `setlocale()` can rebuild PCRE's case tables.

### Other findings

- `regex.lint.anchor.impossible.end` says nothing under a newline convention
  other than LF, where it gave false warnings: `/(*CR)a$\n/`, which never
  matches, is still missed. Reading `\r` and `\r\n` as the newline there
  would report it.
- A PHP file holding one byte of invalid UTF-8 is re-encoded from Latin-1 as a
  whole before extraction, which double-encodes its UTF-8 patterns. It also
  shifts `column` and `file_offset` and changes `pattern` in the JSON report
  for that file.
- A lookahead before a loop yields a ReDoS witness with an empty suffix, which
  then matches: `/^(?=)(?:é|\W)*$/` gives `["", "éé", ""]`. The verdict is
  right (with the suffix `a` the engine goes 95, 1,535, 24,575 steps), only
  the witness is wrong.
- Under `(?J)`, PCRE accepts two names for one branch-reset number when the
  later name already exists: `/(?J)(?<n>a)(?|(?<m>b)|(?<n>c))/` on `ab`
  gives `{"0":"ab","n":"b","1":"a","2":"b"}`, with no `m` key. The library
  refuses it. Accepting it needs the capture shape to stop naming the shared
  record after the first branch that names it, and the `(?J)` rule of
  `capture-shapes.md` to say which name PHP keeps.
- `lint` skips a file that does not fit in its memory budget without saying so:
  at a low `memory_limit` (6 MB, `--jobs=1`) the JSON report reads
  `results: []` with exit 0, a clean run that is not one. A skipped file should
  be reported.
- Printer round trips that change the meaning:
  - the preserving printer drops `\Q` before a quoted NEL under `(*UTF)` and
    `x`, so the NEL becomes whitespace;
  - pretty mode lays out a pattern without `x` with newlines, and writes a
    multi-line `(?#...)` as `#` lines, so its output is no longer the same
    pattern (`/a(?#x\ny)b/` no longer matches `"ab"`). The tests pin that
    layout as a display form: a decision, whether pretty output must stay a
    pattern (lay out under `x` only) or is display only (say so where it is
    documented).
- The console form of a pattern does not always read back as itself:
  - an escape it inserts can hold an unusual delimiter (`}a\x{202E}b}u`
    with `}` as delimiter), and a control-byte delimiter is itself escaped
    (`\x01a b\x01x`);
  - an invalid pattern can read back as a valid one: `\c` before a control
    or non-ASCII byte (`/\c\u{85}/` shows as `\c\xC2\x85`), invalid UTF-8
    under `/u` (`\xA0` compiles once shown);
  - with a bracket delimiter, a `#` comment dropped under `x` can hold a
    bracket PHP counted, so the shown form no longer closes (`{{#,x} }x`
    shows as `{{}x`);
  - a message quoting a pattern without `/u` spells a hidden character
    `\x{202E}`, which PCRE refuses in that pattern.
- A railroad label spells quoted text as text, so `{2}` after an atom
  reads back as a quantifier (`a\Q{2}\E` shows as `a{2}`); a bare `\x`
  (PCRE2 10.44 and older) before `{` is not respelled either.
- Error order in a class left open at the end: a reversed range between
  two plain ASCII characters is reported first, as PCRE2 does; one with an
  escape at either end (`[\x7A-a`) still gives the unclosed class.
- Lookbehind validation (each against PCRE2 10.49):
  - the "lookbehind assertion is too complicated" budget is not PCRE's:
    PCRE counts past 2000 across the whole compile, with or without a branch
    reset; the library caps at 1000 per lookbehind and only with a branch
    reset, so `/(?<=(?1))…(?|x)/` with ten levels of doubling calls is
    refused (PCRE accepts it) and 2002 `(?<=a)` are accepted (PCRE refuses);
  - with a branch reset, measuring a lookbehind through doubling calls is
    still exponential (266 bytes take 19 s): the budget is checked only
    after a whole branch;
  - `\X` is judged before the branches are measured, and a lookbehind used
    as a condition inside a lookbehind is not measured, so the first error
    is not PCRE's.
  - a group around a lookbehind counts as being measured, where PCRE only
    counts a call from inside the group it calls and skips `(?(DEFINE)…)`:
    `/(a(?2))(c(?(DEFINE)(?<=(?1))))/` is refused as not limited at 19
    (PCRE compiles it); `/(a(?<=(?3)))(b(?<=(c(?2))))/` is refused at 14
    (PCRE at 2).
  - a lookbehind called from a condition that holds another lookbehind can
    pass unmeasured: `/(?<=(?1))((?(?<!(?2))x)b)((?(?<!(?1)c?)x))/` is
    accepted, PCRE refuses it as not limited at 28.
- In the JSON, Checkstyle and JUnit reports, a stray byte written `\xHH`
  reads the same as the four characters `\xHH` already in a pattern.

## Report the PCRE2 JIT crash upstream

Not filed yet. No issue about it existed on
[PCRE2Project/pcre2](https://github.com/PCRE2Project/pcre2/issues) when it was
found (September 2026). Once filed, link the issue from
[docs/concepts/pcre.md](docs/concepts/pcre.md#a-known-jit-crash) and from the
CHANGELOG entry.

Draft:

> **JIT: segfault on a backreference to a group set in a non-atomic lookahead, under a repeated branch reset**
>
> ```
> /(?|(\*)(*napla:(.+))|()(?=\S_(\2?)))+_/
>     *a_
> ```
>
> `pcre2test -jit` crashes on this subject (SIGSEGV); without `-jit` the
> answer is "No match". Found from testinput2 line 6281:
> `^(?|(\*)(*napla:\S*_(\2?+.+))|(\w)(?=\S*_(\2?+\1)))+_\2$` with
> `*a_cb2a1_a_1!_a_Z1a!_Z_Z`.
>
> Reproduced with 10.40, 10.42, 10.45, 10.47, 10.49 and main (ef110b8), built
> with the JIT, on arm64 (macOS) and x86_64 (Linux), and in PHP with
> `pcre.jit=1` (8.2 and 8.5 with 10.42, 8.4 with 10.49, the official
> `php:8.4-cli` image with 10.44).
>
> Every part is needed: the repeated branch reset `(?|...)+`, the non-atomic
> lookahead `(*napla:...)` that captures group 2 in the first branch, and the
> lookahead that captures `(\2?)` as group 2 in the second. Under lldb the
> fault is `EXC_BAD_ACCESS` in the JIT code, in the byte compare loop of the
> `\2` backreference, which seems to read through a stale capture pointer.

## Ecosystem review: what is left

Ten pieces of work from the October 2026 ecosystem review are merged into
`2.x` (up to 3e568588): public API scope, capture shapes for PHPStan and
Psalm, the JSON contract, the docs drift, `Regex::info()` /
`Regex::compatibility()` and the PHP range in the linter, the Rector and
Psalm packages, and the ReDoS search cost. Where the review and the 2.0
defect fixes redefined the same contract, the lint baseline keeps the
versioned format and its matching, written with snake_case keys, and every
lint JSON key is snake_case.

The follow-ups are merged too: the search cost's two false positives
(aa8747f6 to 52574b23) and the SonarPHP parity rules, with
`regex.replacement.undefinedGroup` and the Symfony route requirement fix.

### The maintainer's calls

- The ReDoS latency check (`php tests/Tools/redos-verdict-gate.php`) is above
  its 5.0 ms p99 limit: 5.25 ms before the search cost, about 5.5 ms with it.
  Raise the limit or speed up the per-attempt proof.
- The SonarPHP parity rules add about 37 % to the lint of the corpus fixture
  (1.15 s to 1.6 s for 1,645 patterns, Xdebug off): many patterns ask the
  automata one to three questions. A rule asks at most eight per pattern, a
  cap the corpus never reaches. Keep it, or make the automata rules opt-in.
- `tests/Support/LinearTimeAssertions.php` was loosened for shared runners
  (budget 4 s, ratio 3.5, a ratio read only when both readings are above
  0.05 s, best of 4): a quadratic regression whose small reading stays under
  0.05 s is caught by the 4 s budget only. Keep the strict values locally and
  the loose ones in CI (an environment variable), or lengthen the inputs.

### Smaller findings, each confirmed against the engine

- The Symfony route requirement normalizer is also used for the firewall and
  `access_control` patterns, with the same missing flags as below.
- The lint rules read characters with PCRE's C tables: after
  `setlocale(LC_CTYPE, 'fr_FR.ISO8859-1')`, `/a\B\xE9/` matches `"a\xE9"`,
  and `regex.lint.anchor.impossible.boundary` calls it impossible.
- `regex.lint.anchor.alternationPrecedence` keeps an emptied group in its tip
  (`/(?:(?:^))a|b/` gives `^(?:(?:(?:))a|b)`, which `group.empty` reports
  next), and stays silent on `(*SKIP:n)` with no `(*MARK:n)`, which the
  engine ignores.
- `regex.lint.quantifier.lazyToClass` asks the automata without a DFA cache.
- The automata solver refuses the `r` modifier ("Unsupported regex flags for
  automata: r") but reads `(?r:...)`; the lint rules spell it inline.
- The Symfony route requirement normalizer anchors and groups a requirement
  as the route compiler does, but leaves out the flags it compiles with:
  `sD`, plus `u` under the `utf8` option, after a leading `/`. A second
  anchor (`^^a$$`, `x*|^y`, `(?m)^a$|b`) matches in the linted pattern and
  never in the route; `.*+\n` under `s` is not reported. Mirroring the flags
  changes the verdict on every route, so it is a decision of its own.
  The compiler also strips a trailing `$` or `\z` that is escaped
  (`a\$` compiles to `(?P<x>a\)`, which fails at run time), where the
  normalizer keeps it as a literal and lints a valid pattern.
- `NodePredicates::applyInlineFlags()` keeps a flag string that loses `xx`
  (read as `x`), the ASCII options (`(?a)(?-aD)` keeps `\w` ASCII) and the
  `r` a `(?^)` drops: the lint rules that ask the automata stay silent under
  those options rather than carry them.
- `regex.lint.quantifier.emptyRepeat` stays silent where `quantifier.nested`,
  `quantifier.assertion`, `dotstar.nested` or `alternation.empty` report the
  repeat, even when that rule is turned off: `LintContext` does not say which
  rules are on.
- An alternative of `(*COMMIT)`, `(*SKIP)` or `(*ACCEPT)` alone matches the
  empty string, as an empty one does, but `regex.lint.alternation.empty` does
  not report it.
- The language server never runs the validator (only the parser), so it
  misses every validation error and the PHP range check.
- The linter validates each pattern at every PHP version of the range; a
  pattern that reads no version-dependent rule could skip the extra runs (the
  flag must travel with the cached tree, not in a side channel).
- The parser accepts `/(?:(?:a{1000}){1000}){1000}/`, which PCRE2 refuses
  ("regular expression is too large").
- At `php_version` 8.1 the parser accepts a raw NUL in a pattern; PHP 8.1
  refuses it.
- `/(?<n3>a)(?|(?<n1>x)|(?<n3>y))/J` compiles on PCRE2 10.49 but is refused
  for every release from 10.44 (right for 10.44); the release that relaxed it
  is unknown.
- Capture case facts (`lowercase-string` / `uppercase-string` and caseless
  values) were left out: Turkish casing, the Kelvin sign and the long s as
  sources, locale tables.
- A baseline path always reads `\` as a separator, so one baseline serves
  Windows and Unix; a file name holding a literal `\` on Unix matches the
  subdirectory of the same name.
- With Xdebug on (`debug,coverage`), the linear-time ReDoS tests go over
  their one-second cap; they pass with `XDEBUG_MODE=off`.
- Under `x` without `u`, PCRE2 skips byte 0x85 inside a raw multibyte literal
  (`/Å/x` matches `"\xC3"`), but the lexer keeps it; pcre2test gives the last
  code unit `\xc3`.
- The last code unit is not read through `(*ACCEPT)` inside `DEFINE` or a
  one-branch conditional (`/x(?(DEFINE)(*ACCEPT))b/`); unreachable today.
- `(*UCP)` without `u`: `/(*UCP)xk/i` gives the last code unit `k`, where
  pcre2test gives none; unreachable today.
- Under `u`, a class of POSIX classes covering everything
  (`/[[:^alpha:][:alpha:]]*\d$/u`) is undecided, so the quadratic search is
  missed (1.35 s at n = 20,000).

### Upstream, the maintainer's call

- Psalm 6.19's type combiner turns `numeric-string|'a'` into `numeric-string`
  (order-dependent); the Psalm plugin emits no `numeric-string` because of it.
- Psalm's `preg_match_all` stub types `MARK` wrongly under
  `PREG_OFFSET_CAPTURE`.
