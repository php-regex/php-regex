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
3. **Packagist** — check that every package receives its updates on each
   push (the GitHub integration on the Packagist account, or a webhook per
   repository): without them Packagist crawls a repository about once a
   week.

## Before 2.0.0: known defects to fix

Found while auditing the lint output over the corpus and while reworking the
ReDoS proof and the lexer (October 2026). Each one is confirmed against
`preg_match()` or measured, and predates the fixes made then. 2.0.0 freezes
the public API, so they go before the tag.

Nothing is left open in this list: every finding was fixed, or settled
below with what it costs.

## Settled by design (2026-10-08)

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

- **The language server judges at the floor of the project's range**,
  resolved at `initialize` (its options, `regex.json`, then `composer.json`);
  the other boundaries of the range are `regex lint`'s.
- **The linter validates a pattern at every boundary of the PHP range.**
  Measured on the corpus fixture (1,645 patterns, warm): 0.44 ms a pattern
  for one target, about 1.4 ms more for three more. Skipping the runs a
  pattern does not need would take knowing that its validation read no
  version-dependent rule, but `PcreTarget::$phpVersionId` is read directly
  in many places: the knowledge would have to come from routing every such
  read through one method first. Correctness of the range comes first.

- **The character sets stop at 0x7F.** Above it a dot or a negated class
  holds nothing the sets can show, so the nested-loop rules and
  `quantifier.concatenation` refuse to decide there: they lose findings,
  never report a false one. Sets over every byte and code point would be a
  rewrite of `ByteCharSet` and of every rule that reads it.
- **The lint rules read no lookaround in a loop:** `(?:,a*(?:(?!z)a)*)+$`,
  exponential on the engine, gets no nested warning; the ReDoS check reports
  it ("Potential backtracking"), which is where a backtracking verdict
  belongs.
- **The compiled-size floor stays a floor.** It misses case pairs mbstring
  does not lower alike (`[ςσ]`), so it counts them as a class; measured on
  PCRE2 10.49 under `/u`, `(?:[ςσ]){4096}`, `(?:[σΣ]){3855}` and
  `(?:[kK]){1681}` are refused by PCRE and accepted by the floor, and no
  repeat is ever refused that PCRE accepts. A floor that missed less would
  have to read PCRE2's case sets.
- **`impossible.end` stays silent under `(*ANY)`**, whose newlines reach
  above ASCII, and under `(*CRLF)` and `(*ANYCRLF)` it checks only that the
  tail can start a line end (`/(*CRLF)a$\r\r/` is missed): both lose a
  finding, never report a false one.

- **The library never normalizes Unicode.** PCRE2 does not: a pattern in
  NFC never matches an NFD subject (`/é/u` against `"e\u{0301}"`, `/ui`
  included), `/^\p{L}+$/u` does not match a decomposed é, and `/ß/iu` does
  not match `"ss"` (simple folding only). That absence is the contract,
  pinned by `tests/Unit/NormalizationInvariantTest.php`. mbstring's full
  case mappings (`ß` → `SS`) only ever feed boolean checks.

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

All fixed or settled (see "Settled by design").

### Upstream, the maintainer's call

- Psalm 6.19's type combiner turns `numeric-string|'a'` into `numeric-string`
  (order-dependent); the Psalm plugin emits no `numeric-string` because of it.
- Psalm's `preg_match_all` stub types `MARK` wrongly under
  `PREG_OFFSET_CAPTURE`.
