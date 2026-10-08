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

- Left from this list: the lookbehind validation below.

### The character-set analysis

- Sets cover bytes 0x00-0x7F only: a dot or a negated class never meets a
  byte above it. The nested-loop rules and `quantifier.concatenation` refuse
  to decide in that case, which costs them findings.
- Case-insensitivity and lookarounds are ignored: `/(?:a|A)+$/i` (exponential)
  gets no overlap warning, and `(?:,a*(?:(?!z)a)*)+$` no nested warning.

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


The normalization-free contract is pinned by
`tests/Unit/NormalizationInvariantTest.php`: the engine, the printer and the
solver each keep `/é/u` and `/e\u{301}/u` apart.

Smaller, same family: the compiled-size floor compares code
points through mbstring's lowercase, which misses case pairs PCRE2 folds
(Greek `[ςσ]` is not seen as one); mbstring's full mappings (`ß` → `SS`,
`İ` → two code points) only ever feed boolean checks, but its tables can
drift from PCRE2's, and the `/i`-useless rule has no locale guard where a
host `setlocale()` can rebuild PCRE's case tables.

### Other findings

- `regex.lint.anchor.impossible.end` says nothing under `(*ANY)`, whose
  newlines reach above ASCII, where the character sets stop; under
  `(*CRLF)` and `(*ANYCRLF)` it checks only that the tail can start a line
  end, so `/(*CRLF)a$\r\r/` is missed.
- Lookbehind validation (each against PCRE2 10.49):
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

## Settled by design (2026-10-08)

Decided without the maintainer, as asked; each can be reopened.

- **Railroad labels show text, not syntax.** A box holds the characters it
  matches and a quantifier is drawn as a loop, so `a\Q{2}\E` shows a box
  "a{2}" and `a\.b` a box "a.b": nothing inside a box is read as syntax.
- **The console form of a pattern favours showing hidden characters over
  reading back exactly.** It may not read back as itself with a plain `}`
  or a control byte as delimiter, an invalid pattern (`\c` before a control
  byte, invalid UTF-8 under `/u`), or an `x` comment dropped inside bracket
  delimiters: every such case is rare, an invalid pattern is printed beside
  its error, and the JSON, Checkstyle and JUnit reports carry the pattern
  exactly as written.
- **Pretty printing lays a pattern out under `x` only** (545d5949): without
  `x` the output stays on one line, so it is always the same pattern.
- **Symfony patterns are linted as Symfony matches them** (352887c7): a
  route requirement as `{^...$}sD` (plus `u` under `utf8`), a security path
  as `{...}s`, a host as `{...}i`; never as a delimited regex.
- **The lint rules read characters with PCRE's C tables.** PHP builds
  locale tables only after `setlocale(LC_CTYPE, …)` to a non-C locale, which
  few applications do and which PHP 8 itself discourages: under such a
  locale `/a\B\xE9/` may match where `impossible.boundary` says it cannot.
  A known limit rather than a model per locale.
- **Rules stay silent under `xx` and the ASCII options** (`(?a)`, `(?aD)`…)
  rather than ask the automata under the wrong flags: losing a finding there
  is the safe side, and those options are rare.
- **An alternative of a verb alone is not an empty alternative.** `a|(*ACCEPT)`
  or `x|(*COMMIT)` matches the empty string, but the verb says it on
  purpose: `alternation.empty` keeps reporting only alternatives with
  nothing in them.
- **Capture types carry no case facts.** `lowercase-string` and caseless
  values would have to model Turkish casing, the Kelvin sign, the long s and
  locale tables; the types say less rather than something wrong.
- **A baseline path reads `\` as a separator,** so one baseline serves
  Windows and Unix; a Unix file name holding a literal `\` is not worth
  breaking that.
- **The linear-time ReDoS tests time the engine:** with Xdebug on they go
  over their cap, so they run with `XDEBUG_MODE=off`, as the suite does.
- **The last code unit through `(*ACCEPT)` in `DEFINE`, and under `(*UCP)`
  without `u`:** both differ from pcre2test only on paths no caller reaches;
  left as they are until one does.

- **The SonarPHP parity rules stay on.** They add about 37 % to the lint
  of the corpus fixture (1.15 s to 1.6 s for 1,645 patterns), about
  0.27 ms a pattern, for defects found against the engine; a rule asks the
  automata eight questions at most per pattern.
- **The ReDoS latency limit is 6 ms at the 99th percentile** (it was 5 ms):
  the search cost, added on purpose, took the analysis to about 5.5 ms, and
  no single hot spot stands out to win the difference back.

- **The byte test of the heuristic ReDoS profile stays.** Under `/u` it
  skips an adjacency across a multibyte seam, but the verdict comes from the
  proof, which reads code points (`/^é+é+$/u` is proven polynomial,
  `/^(?:xé)+é+$/u` proven linear, both right); the profile only speaks when
  the proof gives up.

- **`quantifier.lazyToClass` keeps no DFA cache:** it asks match
  equivalence, which explores two priority NFAs and builds no DFA, and each
  of its questions is about one pattern.

- **A stray byte in a report is written `\xHH`,** the same text as the
  escape `\xHH` written in a pattern: both stand for that byte in PCRE, so
  the report still says what the pattern matches; the bytes themselves would
  make the JSON invalid.
- **`(*UCP)` without `/u` needs nothing in the character sets:** `\w`, `\d`
  and `\s` keep the same ASCII members under it (checked byte by byte), the
  sets stop at 0x7F, and the rules that look above ASCII read the verb.

- **Two names for one branch-reset number stay refused, whatever the
  release.** PCRE2 10.49 accepts `/(?J)(?<n>a)(?|(?<m>b)|(?<n>c))/` and
  `/(?<n3>a)(?|(?<n1>x)|(?<n3>y))/J` when the later name already exists,
  but drops the earlier one: `$matches` has no `m` key. No ChangeLog entry
  from 10.45 to 10.50 announces it (10.47 rewrote the name lookup), every PHP
  bundles 10.44, which refuses both, and a pattern that silently loses a
  name deserves the error.

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

### Smaller findings, each confirmed against the engine

- The language server judges a pattern for the PHP running it: it does not
  read the project's PHP range from `composer.json`, as `regex lint` does.
- The linter validates each pattern at every PHP version of the range; a
  pattern that reads no version-dependent rule could skip the extra runs (the
  flag must travel with the cached tree, not in a side channel).

### Upstream, the maintainer's call

- Psalm 6.19's type combiner turns `numeric-string|'a'` into `numeric-string`
  (order-dependent); the Psalm plugin emits no `numeric-string` because of it.
- Psalm's `preg_match_all` stub types `MARK` wrongly under
  `PREG_OFFSET_CAPTURE`.
