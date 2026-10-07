# TODO

## Split into packages: the steps done by hand, in this order

The order matters: a new repository named `regex-parser` would take over the
old URL, and Packagist would lose the 1.x tags of `yoeunes/regex-parser` if its
source still pointed there.

1. ~~**GitHub** — rename `php-regex/regex-parser` to `php-regex/php-regex`~~ (done 2026-10-01)
   (Settings → General → Repository name). Stars, issues and history follow;
   GitHub redirects the old URL until a repository takes the old name back.
2. **Packagist** — `yoeunes/regex-parser` must read
   `https://github.com/php-regex/php-regex`. The URL cannot be edited on the
   site for a popular package: write to contact@packagist.org from the account
   that maintains it, and wait for their answer. Until then nothing breaks
   (GitHub redirects the old URL), but `php-regex/regex-parser` must not be
   created. `bin/split` refuses to push into a repository that serves the
   monorepo. `bin/status` shows a ✓ once Packagist has switched.
3. **GitHub** — create the 16 read-only repositories (13 done 2026-10-01; `regex-rector` and `regex-psalm`, added after, not created yet; `regex-parser` only once step 2 is done: `gh repo create php-regex/regex-parser --public --disable-issues --disable-wiki`), empty (no README, no
   license, no .gitignore), each with issues and pull requests pointing to
   `php-regex/php-regex`:
   `regex-parser`, `regex-explain`, `regex-optimizer`, `regex-generator`,
   `regex-automata`, `regex-redos`, `regex-transpiler`, `regex-linter`,
   `regex-toolkit`, `regex-cli`, `regex-language-server`, `regex-phpstan`,
   `regex-psalm`, `regex-rector`, `regex-symfony`, `regex-laravel`.

   **`regex-rector` comes first, before its branch is merged into `2.x`**:
   the repository `php-regex/regex-rector`, its access in the `SPLIT_TOKEN`
   token (step 4) and its Packagist entry (step 6) must all exist when
   `src/Rector` reaches `2.x`. From that merge on, `bin/split` lists
   `src/Rector:regex-rector`, cannot read a repository that does not exist,
   counts it as failed, and the split job fails on every push to `2.x` (the
   other packages are still pushed).

   **`regex-psalm` likewise, before its branch is merged into `2.x`**: the
   repository `php-regex/regex-psalm`, its access in the `SPLIT_TOKEN` token
   (step 4) and its Packagist entry (step 6, type `psalm-plugin`) must all
   exist when `src/Psalm` reaches `2.x`, for the same reason: from that merge
   on, `bin/split` lists `src/Psalm:regex-psalm`.
3b. **GitHub, `php-regex/regex-parser`, once Packagist has switched** — every
   `composer.lock` of 1.x points at
   `api.github.com/repos/php-regex/regex-parser/zipball/<commit>` (~13k
   installs a month): the new repository must hold those commits, under refs
   Composer does not read (a `1.x` branch or `v1.*` tags there would be
   imported as `php-regex/regex-parser` 1.x). Checked on a scratch repository:
   GitHub keeps such refs and serves the zipball of a commit only they reach.
   ```bash
   gh repo create php-regex/regex-parser --public --disable-issues --disable-wiki \
     --homepage https://github.com/php-regex/php-regex \
     --description "[READ-ONLY] The PCRE2 regex parser. Split of php-regex/php-regex."
   git fetch origin 1.x
   git push git@github.com:php-regex/regex-parser.git \
     refs/remotes/origin/1.x:refs/archive/1.x \
     b14ef028:refs/archive/2.x-before-split
   ```
   Then add `regex-parser` to the `SPLIT_TOKEN` token: the next push to `2.x`
   splits it (`bin/split` no longer skips it once it is a repository of its
   own).
4. ~~**GitHub** — create a fine-grained token~~ (done 2026-10-01; add `regex-rector` and `regex-psalm` to it before their branches are merged, and `regex-parser` once that repository exists) with *Contents: read and write* on
   those 16 repositories only, and store it as the secret `SPLIT_TOKEN` of
   `php-regex/php-regex` (Settings → Secrets and variables → Actions).
5. ~~**Split**~~ (enabled 2026-10-01: 13 repositories split by CI; `regex-parser` is skipped until it exists) — first run `bin/split --dry-run` locally to read the plan, then
   set the repository variable `SPLIT_ENABLED` to `true` (same page, tab
   *Variables*): `.github/workflows/split.yml` then splits on every push to
   `2.x` and every `v2.*` tag. Push once (or run `bin/split` locally with
   splitsh-lite installed) and check each repository received `2.x`.
6. **Packagist** — submit the 16 packages (`php-regex/regex-*`), from their
   repositories, and `php-regex/php-regex` from the monorepo if the whole
   library should be installable in one package.
7. **Packagist** — enable updates on push for each package: the GitHub
   integration on the Packagist account, or a webhook per repository
   (`https://packagist.org/api/github?username=...`).
8. **Packagist, on the 2.0.0 release** — mark `yoeunes/regex-parser` as
   abandoned in favour of `php-regex/regex-toolkit`.
9. **README badges** — the Packagist and CI badge URLs change with the names
   (done with the README rewrite).

## Before 2.0.0: known defects to fix

Found while auditing the lint output over the corpus and while reworking the
ReDoS proof and the lexer (October 2026). Each one is confirmed against
`preg_match()` or measured, and predates the fixes made then. 2.0.0 freezes
the public API, so they go before the tag.

### Next steps

- Merge the `before-2-0-defects` branch (frozen surfaces, then parser
  errors) into `2.x` and push.
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

### Other findings

- With `possessive` on, the optimizer suggests `.*+` under `/x` where an
  inline or start-of-pattern option makes the rewrite change the matches
  (`/(*CR)[0-9][0-9]a.*\n/x`): the `/x` path skips the atomicity check, and
  the rewriter ignores the newline convention.
- `regex.lint.anchor.impossible.end` reads `$`, `\Z` and `^` under `/m` as if
  the newline were always `\n`: six false warnings under `(*CR)`, `(*CRLF)`,
  `(*NUL)`, `(*ANY)` and `(*ANYCRLF)`, and `/(*CR)a$\n/` (impossible) is
  missed.
- A PHP file holding one byte of invalid UTF-8 is re-encoded from Latin-1 as a
  whole before extraction, which double-encodes its UTF-8 patterns. It also
  shifts `column` and `file_offset` and changes `pattern` in the JSON report
  for that file.
- A lookahead before a loop yields a ReDoS witness with an empty suffix, which
  then matches: `/^(?=)(?:é|\W)*$/` gives `["", "éé", ""]`. The verdict is
  right (with the suffix `a` the engine goes 95, 1,535, 24,575 steps), only
  the witness is wrong.
- The inline `n` state does not reach the body of an alphabetic assertion
  (`(*pla:…)`, `(*atomic:…)`, …), as it reaches `(?=…)`:
  `/(?-n)(*atomic:(a))/n` captures group 1 on the engine, and the library
  numbers no group, so the capture shape misses key `1` (also `(?^)`,
  `(?-n:…)`, and every alphabetic group name).
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
- The text of a comment reaches `explain()`, the highlighters and the Mermaid
  output as raw bytes: a byte-mode comment that is not valid UTF-8
  (`/a(?#\xE1)b/`, or `"/(*ANY)a#\u{5140}b/x"`, whose comment ends at the
  0x85 byte inside the character) gives an explanation `json_encode()`
  refuses, and the language server then drops its hover reply without a
  word. The Mermaid output also cuts a comment at 20 bytes, mid-character.
- Printer round trips that change the meaning:
  - `PatternPrinter` drops the wrapper of a `(?#...)` comment whose text
    starts with `#` under `/x`: `/a(?##c)b/x` prints as `/a#cb/x`, which
    turns `b` into comment;
  - the preserving printer drops `\Q` before a quoted NEL under `(*UTF)` and
    `x`, so the NEL becomes whitespace;
  - pretty mode rewrites `(?#x\ny)` as `#` lines in a pattern without `x`
    (`/a(?#x\ny)b/` then no longer matches `"ab"`), and puts newlines before
    `|` in a pattern without `x`.
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
- `regex redos` with `--jit`, `--backtrack-limit`, `--recursion-limit` or
  `--time-limit` stops on a fatal error where `ini_set()` is in
  `disable_functions`.
- A railroad label spells quoted text as text, so `{2}` after an atom
  reads back as a quantifier (`a\Q{2}\E` shows as `a{2}`); a bare `\x`
  (PCRE2 10.44 and older) before `{` is not respelled either.
- PCRE's limit of 250 nested parentheses is not applied to a group left
  open: 300 `(` give `regex.group.unclosed` at the end, where PCRE says
  "parentheses are too deeply nested" at 251.
- `/(?<*+a)/` is a missing group name at 3; PCRE reports the quantifier
  that follows nothing at 5.
- Error order, still off PCRE's (each a library offset vs PCRE2 10.49):
  an error in a class left open at the end is lost to the unclosed class
  (`/[a(?-1)/` at 7, PCRE "range out of order" at 6); `/(?((*foo:/` is
  `regex.verb.invalid` at 8 (PCRE "subpattern name expected" at 3);
  `/\g-1+/` at 5 (PCRE 4); `/a{3,2}(?#c)+/` at 10 (PCRE 5).
- `/(a)\g-1+{2}/` is accepted; PCRE refuses the stacked quantifier at 11
  (the `\g` token takes the `+`).
- More error order off PCRE's (library vs PCRE2 10.49): 251 closed nested
  groups then `[` give the unclosed class at the end (PCRE "parentheses are
  too deeply nested" at 251); a callout number above 255 in a condition,
  `/(?(?C256)a)/`, is the missing assertion at 9 (PCRE 8); `/(?<n>a)(?<n/`
  is a duplicate name at 12 (PCRE unterminated name at 11); `/\g{-1/` is
  `regex.backref.invalid_syntax` at 5 (PCRE "non-existent subpattern" at 2).
- `regex.lint.flag.redundant` reads `(?^` as turning every option off, where `J`
  and `U` stay on: `/(?^U)a+/U` gets no redundant-flag warning
  (`InlineFlagsRule`).
- A lookbehind length past `PHP_INT_MAX` (64 levels of doubling calls)
  overflows to a float: the "too long" verdict and offset are right, but
  the message prints `length=0` or a negative number.
- Lookbehind validation (each against PCRE2 10.49):
  - the "lookbehind assertion is too complicated" budget is not PCRE's:
    PCRE counts past 2000 across the whole compile, with or without a branch
    reset; the library caps at 1000 per lookbehind and only with a branch
    reset, so `/(?<=(?1))…(?|x)/` with ten levels of doubling calls is
    refused (PCRE accepts it) and 2002 `(?<=a)` are accepted (PCRE refuses);
  - with a branch reset, measuring a lookbehind through doubling calls is
    still exponential (266 bytes take 19 s): the budget is checked only
    after a whole branch;
  - a back reference in a lookbehind of a pattern with a branch reset is
    measured, where PCRE refuses it as not limited (`/(a)(?|b|c)(?<=\1)/`
    at 10);
  - `\C` in a lookbehind under `(*UTF)` without `/u` is accepted
    (`/(*UTF)(?<=b\C)/`, PCRE refuses it at 6);
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
- Name readers outside group definitions do not stop where PCRE stops:
  `\k<aé>` without `/u` (and `\k'`, `\k{`, `\g{`, `\g<`, `\g'`) is a
  missing named group at 10 (PCRE: syntax error in the name at 11);
  `(?&aé)` likewise; `\k{a b}`, `\k{ a` one byte early; `(?&a(?:z)`
  and `(?P>a(?:z)` an unclosed group (PCRE: "expected capture group number
  or name"); the message for `\g{٣a}` quotes `\k{٣a}`.
- `/[z-abcd/` is the unclosed class at 7; PCRE reports the reversed range
  at 4.
- `\1000` (octal `\100` then `0`) followed by a comment, `\E` or an `x`
  blank and a quantifier repeats both characters, where PCRE repeats the
  `0` only: `/^\1000(?#c)+$/` matches `"@0@0"` once printed, and PCRE does
  not.
- A relative condition reference before any whole item is reported after
  the next error: `/(?(-1)(/` is the unclosed group at 7, PCRE "reference
  to non-existent subpattern" at 5.
- The caret under a lint snippet is placed by bytes, so each multi-byte
  character before the fault moves it one column right.
- In the JSON, Checkstyle and JUnit reports, a stray byte written `\xHH`
  reads the same as the four characters `\xHH` already in a pattern.
- The printer rewrites `(?P=אABC)`, a reference by a non-ASCII name, as
  `\k<אABC>`, where an ASCII name keeps its spelling.
- `regex.lint.escape.suspicious` warns on `/\N{U+41}/u`, which is valid and
  matches `"A"`.
- The Symfony security extractor never reads a block-style list (`- ROLE_ADMIN`
  on the lines under `roles:`, `methods:` or `ips:`): the dash lines are taken
  before the list is looked at, so they produce bogus rules, and the parent
  rule loses its roles, methods and addresses.
- The language server's flag completion is off by one (the occurrence starts
  at the opening quote): with the cursor right after `/abc/`, no flag is
  offered.
- The JavaScript transpiler refuses `\k'n'`, the same backreference as
  `\k<n>`.

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

## The `ecosystem-roadmap` branch: what is left

The branch carries ten pieces of work from the October 2026 ecosystem review
(public API scope, capture shapes for PHPStan and Psalm, the JSON contract,
the docs drift, `Regex::info()` / `Regex::compatibility()` and the PHP range
in the linter, the Rector and Psalm packages, the ReDoS search cost). It is
not rebased on `2.x` yet; merging it is a manual step.

### Rebase onto `2.x` (blocked on one decision)

`2.x` gained a48c101d and 8c03d93d after the branch started. A rebase onto
8c03d93d resolves cleanly up to "Settle the capture shape before 2.0" and
"Keep J and U on across a caret" (the same fix landed on both sides), then
stops at "Write down the JSON every command prints": both lines of work
redefined the same public contract.

- **Baseline file.** `2.x` writes `{"version": 1, "issues": [...]}` (an
  issue still matches when its line moved, `./src` and `src` share a
  baseline, 1.x lists are read with a note, an unusable file is a
  configuration error); the branch writes a flat list of `{file, line,
  column, issue_id, message, severity}` matched on file, line and id (a file
  that is not a list is a usage error). Each side has tests pinning its form.
- **Lint JSON keys.** The branch renames them to snake_case (`severity`,
  `issue_id`, `file_offset`); the new `2.x` tests read `type`, `issueId`,
  `fileOffset`.

Recommended: keep `2.x`'s versioned baseline (sturdier, and the
compatibility page already promises that every 2.x reads a 2.0 baseline),
written with the branch's snake_case keys, and move the `2.x` tests that
read camelCase lint keys to the snake_case contract. Then resume the rebase
(eight more commits after that one touch the same areas), run the full suite
and check coverage.

### Before merging into `2.x`

- Create the GitHub repositories `php-regex/regex-rector` and
  `php-regex/regex-psalm`, give `SPLIT_TOKEN` access to both, and add both
  packages on Packagist: `bin/split` lists them, so every split fails until
  they exist.
- The ReDoS latency check (`php tests/Tools/redos-verdict-gate.php`) is above
  its 5.0 ms p99 limit on `2.x` already (5.25 ms measured); the branch adds
  about 0.15 ms. Raise the limit or speed up the per-attempt proof.

### Still to build

- Sonar parity lints (designed, not started): `regex.lint.quantifier.emptyRepeat`
  (S5842), `regex.lint.anchor.alternationPrecedence` (S5850, silent on the
  `^\s+|\s+$` trim idiom), `regex.lint.quantifier.possessiveImpossible`
  (S5994), `regex.lint.anchor.impossible.boundary` (S5996, `\b`/`\B`),
  `regex.lint.lookaround.impossible` (S6002), the wider
  `regex.lint.quantifier.lazyEnd` (S6019), the PHPStan identifier
  `regex.replacement.undefinedGroup` (S6328: `$10` with fewer groups,
  `${name}` never substituted), `regex.lint.group.empty` (S6331, `(?:)` only),
  and, off by default, `regex.lint.charclass.single` (S6397),
  `regex.lint.literal.multipleSpaces` (S6326) and
  `regex.lint.quantifier.lazyToClass` (S5857); plus `docs/reference/sonar.md`
  mapping every Sonar regex rule on PHP. No new rule fails CI (warnings and
  style only).

### ReDoS search cost: two false positives

- When every alternative ends with the same character (`/a+b|cb/`,
  `/\s+=|x=/`), the reported attack lacks that character, which PCRE2 checks
  before any attempt: the reported subject is linear (with `"!b"` appended it
  is quadratic, 8 / 31 / 124 ms for n = 5k / 10k / 20k without the JIT).
  `requiredCodeUnit()` reads only the mandatory runs. Some such patterns have
  no attack at all (`/(.*)(?:ab)+|b/`).
- A class covering every character under `/u` (`[\x00-\x{10FFFF}]*`) is not
  read as PCRE2's any-character: `/[\x00-\x{10FFFF}]*\d$/u` is reported
  quadratic, but the engine anchors it and stays linear.

Both stay hidden by the default `high` threshold (the search cost is
`medium`).

### Smaller findings, each confirmed against the engine

- The language server never runs the validator (only the parser), so it
  misses every validation error and the PHP range check.
- The linter validates each pattern at every PHP version of the range; a
  pattern that reads no version-dependent rule could skip the extra runs (the
  flag must travel with the cached tree, not in a side channel).
- `\g{+65534}` with two groups: the "group number too big" offset is 16
  where PCRE2 10.49 says 8 (`\g{+65535}`: 13 vs 5).
- The parser accepts `/(?:(?:a{1000}){1000}){1000}/`, which PCRE2 refuses
  ("regular expression is too large").
- At `php_version` 8.1 the parser accepts a raw NUL in a pattern; PHP 8.1
  refuses it.
- Under `/J`, a name's offset type follows the first group bearing it:
  `/(?J)(?<n>z)?(?<n>a)/` with offsets types `n`'s offset `int<-1, max>`
  although group 2 is always set.
- `/(?<n3>a)(?|(?<n1>x)|(?<n3>y))/J` compiles on PCRE2 10.49 but is refused
  for every release from 10.44 (right for 10.44); the release that relaxed it
  is unknown.
- The search cost gives no verdict when `m` is set inline (`/(?m)^\s+x/`).
- `--disable-rule=regex.lint.<id>` with the full id is ignored for the
  pattern lint rules (the short id works).
- Capture case facts (`lowercase-string` / `uppercase-string` and caseless
  values) were left out: Turkish casing, the Kelvin sign and the long s as
  sources, locale tables.

### Upstream, the maintainer's call

- Psalm 6.19's type combiner turns `numeric-string|'a'` into `numeric-string`
  (order-dependent); the Psalm plugin emits no `numeric-string` because of it.
- Psalm's `preg_match_all` stub types `MARK` wrongly under
  `PREG_OFFSET_CAPTURE`.
