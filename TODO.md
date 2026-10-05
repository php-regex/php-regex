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
3. **GitHub** — create the 14 read-only repositories (13 done 2026-10-01; `regex-parser` only once step 2 is done: `gh repo create php-regex/regex-parser --public --disable-issues --disable-wiki`), empty (no README, no
   license, no .gitignore), each with issues and pull requests pointing to
   `php-regex/php-regex`:
   `regex-parser`, `regex-explain`, `regex-optimizer`, `regex-generator`,
   `regex-automata`, `regex-redos`, `regex-transpiler`, `regex-linter`,
   `regex-toolkit`, `regex-cli`, `regex-language-server`, `regex-phpstan`,
   `regex-symfony`, `regex-laravel`.
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
4. ~~**GitHub** — create a fine-grained token~~ (done 2026-10-01; add `regex-parser` to it once that repository exists) with *Contents: read and write* on
   those 14 repositories only, and store it as the secret `SPLIT_TOKEN` of
   `php-regex/php-regex` (Settings → Secrets and variables → Actions).
5. ~~**Split**~~ (enabled 2026-10-01: 13 repositories split by CI; `regex-parser` is skipped until it exists) — first run `bin/split --dry-run` locally to read the plan, then
   set the repository variable `SPLIT_ENABLED` to `true` (same page, tab
   *Variables*): `.github/workflows/split.yml` then splits on every push to
   `2.x` and every `v2.*` tag. Push once (or run `bin/split` locally with
   splitsh-lite installed) and check each repository received `2.x`.
6. **Packagist** — submit the 14 packages (`php-regex/regex-*`), from their
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
- A pattern shown under `/x` does not read back as itself: raw whitespace and
  `#` comments change meaning once spelled `\n`.
- `regex lint --baseline` drops `column` and `fileOffset` from every result.
- A PHP file holding one byte of invalid UTF-8 is re-encoded from Latin-1 as a
  whole before extraction, which double-encodes its UTF-8 patterns.
- `/\Q\x\E/` parses as a code point instead of the text `\x`.
- C1 controls (U+0080-U+009F) and bidi overrides are shown raw in reports.
- The message for `\P{L}` without `/u` names `\p{L}`.
- The Laravel extractor's own `regex:` pattern backtracks exponentially on a
  run of backslashes with no closing quote (700, then 85,972, then 10,573,735
  steps); past about 25 backslashes `preg_match_all` gives up and every rule
  in that file is skipped silently.
- A lookahead before a loop yields a ReDoS witness with an empty suffix, which
  then matches: `/^(?=)(?:é|\W)*$/` gives `["", "éé", ""]`. The verdict is
  right (with the suffix `a` the engine goes 95, 1,535, 24,575 steps), only
  the witness is wrong.
- The persistent DFA cache is keyed on the target PCRE version, but the
  character sets inside come from the running engine. To check whether two
  runtimes can share one entry.
- Error offsets and messages inside an alphabetic assertion body differ from
  PCRE: `/(?*[a)/` says "Invalid group modifier syntax" at 3 (PCRE: "missing
  terminating ]" at 6); `(?*a\` reports 4 (PCRE: 5); an unclosed class or a
  trailing `\` in a body reads "Missing closing parenthesis for (*pla:".
  More of the same family: an unclosed `(?*` body is always "Invalid group
  modifier syntax" at 3 (`/(?*a/`, `/(?*[])/`, and `/(*CR)(?*b#c\n))/x`
  at 8; PCRE reports the end of the pattern); an error inside a body is not
  found ahead of a later one, as `/(*pla:[[::])])/` (PCRE: "unknown POSIX
  class name" at 11; the library "Unmatched closing parenthesis" at 14).
  And a `\p{` left open in a body with a `}` past its
  `)` is reported there as "Missing closing parenthesis for (*pla:" at 17
  (`/(*pla:\p{a)b\p{L}/`; PCRE: "malformed \P or \p sequence" at 15).
- The body of an alphabetic assertion (`(*pla:…)`, `(?*…)`) is not read in
  the state around it, as `(?=…)` is:
  - `xx` reaches the body as `x`, and the spaces before the first member of a
    class are not skipped there: `/(?xx)(*pla:[ a])./` keeps the space in
    the tree, and the Python transpiler gives `(?xx)(?=[ a]).`, which
    matches `" "` where PCRE does not;
  - group names are checked per body: `/(?<n>a)(*pla:(?<n>b))/`,
    `/(?|(?<n>a)|(*pla:(?<m>b)))/` and `/(*pla:(?J)(?<n>a))(?<n>b)/` are
    accepted, where PCRE refuses all three.
- `\Q…\E` inside a class is read one character per regex call: a 48 KB
  quote takes about 5 seconds to validate.
- An unclosed `(?C` callout is read again to the end of the pattern at each
  attempt: 20,000 of them take about a second, four times as long for twice
  as many.
- A quoted `[:` in a class is read as the start of a POSIX class:
  `/[\Qc[:(\E:]/` and `/[[:^digit:]\Qc[:(\E:]/` are refused with
  `Invalid POSIX class`, where PCRE accepts both.
- Without `/u`, a quantifier stacked on a multi-byte character is accepted:
  U+2029 or U+2028 written as raw bytes, then `+{2}` or `{2}{2}` (PCRE:
  "quantifier does not follow a repeatable item"); `/a+{2}/` and the `/u`
  forms are refused as they should.
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
- `~(*CR)(**\Q…~x` is accepted; PCRE refuses it at offset 7.
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
- With `ini_get()` or `ini_set()` in `disable_functions`, the engine throws an
  `\Error` when it runs a pattern under explicit limits (ReDoS confirmation)
  or turns the JIT off for one that cannot take `(*NO_JIT)`. Without
  `ini_get()` it cannot set the caller's value back: decide whether it then
  changes the setting anyway or runs the pattern as is.
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
