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

Found while auditing the lint output over the corpus (October 2026). Each one
is confirmed against `preg_match()` and predates the fixes made then. 2.0.0
freezes the public API, so they go before the tag.

### First: false "safe (proven)" ReDoS verdicts

A proven verdict must never be wrong (see
[backward-compatibility.md](docs/reference/backward-compatibility.md)). Two
gaps in the backtracking prover give one:

- **An inline option carried into the following alternatives.** PCRE applies
  an option set inside one alternative to the alternatives after it, and the
  prover does not: `/x(?i)|(?:a+A)+$/` and `/x(?s)|(?:.*\n.*\n)+x/` are
  "safe (proven)" but exponential. The lint rules already keep their warning
  for these patterns; `Regex::redos()` and `regex analyze` do not.
- **Inline `r` (caseless restrict) toggles.** `(?i-r:…)`, `(?-r:…)` and a
  `(?^…)` reset under `/r` are read as if `r` stayed on:
  `/(?i-r:(?:k+\x{212A})+)$/ur` is "safe (proven)" but exponential, and
  `/(?ir)(?:k+\x{212A})+$/u` is "exponential (proven)" but linear.

Raise `RedosAnalyzer::ANALYSIS_VERSION` with the fix.

### The character-set analysis

- Sets cover bytes 0x00-0x7F only: a dot or a negated class never meets a
  byte above it. The nested-loop rules now refuse to decide in that case, but
  `regex.lint.quantifier.concatenation` still suggests rewrites that change
  the matches (`/^b+[é]+\z/u`, `/^\h+[\t ]+\z/` on a no-break space).
- Case-insensitivity and lookarounds are ignored: `/(?:a|A)+$/i` (exponential)
  gets no overlap warning, `(?:,a*(?:(?!z)a)*)+$` no nested warning, and
  `/^A?[^a]*\z/i` a concatenation hint that loses `"a"`.
- `(*UCP)` without `/u` is not seen: `\w` and `\d` stay ASCII.
- A `(?^)` reset keeps `r` where PCRE clears it.

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
- The static ReDoS verdict depends on the caller's `pcre.backtrack_limit`: a
  tiny limit turns "safe (proven)" into "no risk found (heuristic)".

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
