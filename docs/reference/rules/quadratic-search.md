---
description: "An unanchored pattern whose every attempt is linear still costs n² on a run: the regex.lint.redos.search verdict, and how to anchor or bound the run."
head_title: "Quadratic search in PHP: an unanchored regex"
parent: /reference/rules/
rule_category: "Security (ReDoS)"
rule_order: 2
rule_ids:
  - regex.lint.redos.search
---
# Quadratic Search

**Identifier:** `regex.lint.redos.search`; `regex.redos.search` in PHPStan, with the message `Quadratic search (ReDoS): <pattern>`

**When it triggers:** One match attempt is proven linear, but the search is not anchored: `preg_match()` may start an attempt at each later position of the subject, and on a run of characters each attempt reads to the end of the run before it fails. On the run repeated n times then a breaking character, PCRE2's interpreter takes a number of steps quadratic in n; `preg_match_all()`, `preg_replace()` and `preg_split()` retry the same way. An anchored alternative does not protect the others: the trim regex `/^\s+|\s+$/` is quadratic on `"!" . " " x n . "!"`. Nor does a match after the run, which the search tries last: `/\s*$/` is quadratic on `" " x n . "!"` and `/(?:\w+\.)?x/` on `"0" x n . "x"`, though `preg_match()` returns 1. `pcre.backtrack_limit` does not stop it: the limit counts each attempt apart, and trips only when one attempt exceeds it. The JIT may avoid it for some patterns, not for all (see the [ReDoS guide](../../guides/redos.md#the-cost-of-an-unanchored-search)).

It also triggers on a pattern whose `preg_match()` search stops at an empty match inside a run, when the call is one that looks for every match. Those calls are `preg_match_all()`, `preg_replace()`, `preg_filter()`, `preg_replace_callback()`, `preg_replace_callback_array()` and `preg_split()`, the `Safe\`, composer/pcre, nette/utils, spatie/regex and Laravel `Str` forms of these, and a `RegexIterator` in the `ALL_MATCHES`, `SPLIT` or `REPLACE` mode, when no literal limit bounds the call ([the list](../../guides/redos.md#the-calls-that-run-the-loop)). After an empty match PHP tries the same offset again for a non-empty one, and on the run each such retry reads to its end: `/(?:a+\d)?/` on `"a" x n`. The message then starts with `Quadratic search after an empty match` and names the function. The JIT does not avoid it, as PHP runs that retry in PCRE2's interpreter whatever `pcre.jit` (see the [ReDoS guide](../../guides/redos.md#the-search-of-the-functions-that-look-for-every-match)). A pattern whose `preg_match()` search stops at a short match inside the run, found after a path that reads to the end of the run failed, triggers it the same way at those calls: `/a*b|a/` on `"a" x n`, where each call of the loop starts where the last match ended. The message then starts with `Quadratic search over short matches`, and the JIT may avoid it for some patterns. At a `preg_match()` call the same patterns are linear, and nothing is reported.

It runs under the ReDoS check, with no switch of its own. Its severity is that of a proven quadratic attempt, `medium`, so the default `high` threshold hides it: `--redos-threshold=medium` shows it. It is a warning in every mode, and `--disable-rule=regex.lint.redos.search` or `"redos.search": false` in the `checks.lint.rules` of `regex.json` turns it off.

**Example:**
```php
// Quadratic search: on " " x n . "!" each attempt reads the rest of the run
preg_match('/\s+$/', $input);

// Linear, and the same answer: the characters \s matches without /u
rtrim($input, " \t\n\v\f\r") !== $input;
```

The two agree on the characters `\s` matches without `/u` in the default C locale. PHP builds PCRE2's character tables from `LC_CTYPE` when a script sets another locale, which may change what `\s` matches; under `/u`, `\s` also matches Unicode spaces such as U+00A0 and U+2028, which this `rtrim()` keeps.

**Fix:** Anchor the pattern when every match starts at a known place (`^`, `\A`, `\G` or the `A` modifier), or bound the length of the run, or do the work without a regex (`rtrim()` for trailing whitespace). After an empty match, make every match read at least one character, or bound the run; after a short match, bound the run. Anchoring helps there only with `^` or `\A`, since each search of the loop starts where the last match ended.

---

**Try it.** [Open `/\s+$/` in the playground](/#pattern=%2F%5Cs%2B%24%2F&subject=%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20%20): the verdict is reported from the medium threshold, and the playground runs at the default one.
