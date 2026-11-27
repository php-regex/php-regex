---
description: "A variable quantifier inside another, as in (a+)+, lets a PHP pattern backtrack catastrophically: when it is reported, when it is safe, and the fixes."
head_title: "Nested quantifiers in PHP regex: (a+)+ ReDoS"
parent: /reference/rules/
quick_fix: "Use an atomic group or a possessive quantifier, and check the rewrite matches the same text"
quick_fix_order: 12
rule_category: "Quantifiers"
rule_order: 1
rule_ids:
  - regex.lint.quantifier.nested
---
# Nested Quantifiers (ReDoS Risk)

**Identifier:** `regex.lint.quantifier.nested`

**When it triggers:** A variable quantifier wraps another variable quantifier, creating catastrophic backtracking potential. A count fixed above zero hides neither the loop after it nor the one inside it: `/^(?:\d{4}|[a-z]+)+$/` and `/^(?:(?:a+){2})+$/` are reported. Nor does a first loop that may match nothing hide the loop behind it: in `/^(?:b?c+)+$/` the runs of `c` of two iterations meet where `b?` matches nothing, and the pattern is reported. Every loop of the iteration is read against what follows it, the iteration read as a ring whose last item comes before its first: in `/^\w+(?:\s+\w+\s*)*$/` the `\s*` that ends one iteration meets the `\s+` that starts the next, and in `/^(?:\s*;\s*\w+\s*\w+)*$/` the two runs of letters meet where `\s*` matches nothing; both are reported.

The message names the outer loop and quotes it as written, on one line without the layout and the comments, thirty columns at most, so two findings on one pattern read apart: `in capturing group 1 "(a+)"` for `/(a+)+b/`, `in named group "(?<run>a+)"` for `/(?<run>a+)+b/`, and for a loop that captures nothing its offset in bytes into the pattern, `in "(?:a+)" at offset 1` for `/x(?:a+)+b/`.

It is not reported when the nesting cannot blow up:

- **Nothing at all follows the outer loop**: `/(a+)+/` and `/x(\d+\.?)+/` end the pattern, so the first way the engine finds is the match. This holds when the outer minimum is 0 or 1, and when no subroutine call runs the loop again. Anything after the loop, an anchor or an assertion included, keeps the issue: `/(a+)+$/` and `/(?:a+)+(?=b)/` are reported.
- **A short run before the end**: an outer bound of at most 2 around a greedy run of one literal character, followed only by `$`, `\z` or `\Z`, without `/m`, as `/(a+){1,2}$/`. Any other shape keeps the issue: a bound of 3 or more (`/(a+){0,3}$/`), a class (`/([ab]+){1,2}$/`), a lazy run (`/(a+?){1,2}$/`), `/m`, or a lookahead after the loop.
- **A separator splits each iteration**: the inner loop is followed right away by an item it cannot match, and every other item of the iteration matches one way only. In the unrolled loop `/"[^"\\]*(?:\\.[^"\\]*)*"/`, each iteration ends with `[^"\\]*`, and the `\\` that opens the next iteration is what `[^"\\]` refuses, so there is one way to split the input. The separator may stand behind items that may match nothing and take none of the loop's characters: the `,` behind `\s*` keeps the runs of `/^(?:\s*\d+\s*,)*\s*\d+\s*$/` apart. Under `/i` the two sides are compared case-folded, so `/(?:a+,)+$/i` is still exempt, but a loop unrolled this way is never exempt: `/"[^"\\]*(?:\\.[^"\\]*)*"/i` is reported. Without `/u`, under another locale's tables than PCRE2's own, the two sides are compared every byte as those tables give them, the other case of an ASCII letter and the word characters above ASCII included, unless the pattern reads a character caselessly where the engine's paths read some case otherwise (macOS's `tr_TR.ISO8859-9` reads `i`, `I` and `\xDD` so): under glibc's `tr_TR.ISO-8859-9`, which pairs `i` with `\xDD` and `I` with `\xFD`, `/(?:i+I)+$/i` is exempt and `/(?:i+\xDD)+$/i` is reported, and so is `"/(?:\\w+\xDD)+\$/"`, with the byte written as it is, `\w` taking `0xDD` there. Under `/u` an ASCII letter written alone, `i`, `\x69`, `[i]` or `[^i]`, takes the cases those tables give it, where a class of several characters takes Unicode's: under glibc's `tr_TR.UTF-8`, which gives `i` and `I` no other case, `/(?:i+I)+$/iu` is exempt, and `/(?:[ij]+I)+$/iu` and `/(?:[^i]+I)+$/iu`, whose classes take `I` there, are reported.
- **The inner loop is in a lookaround**: a lookaround holds without being tried again once it has matched, so a loop inside it splits no run of the loop around: `/^(?:(?=\w+)\w)+$/` and `/^(?:(?<=\s{1,3})\w)+$/` are not reported. A non-atomic lookahead, `(*napla:...)`, is backtracked into: `/^(?:(*napla:a+)a)+$/` is reported.
- **A small counted pattern**: every repeat of the pattern is bounded, and the engine can read the whole pattern in few ways, so one attempt costs a number of steps the pattern alone sets and no subject can grow. The ways are counted from the pattern: items in a row multiply theirs, branches add theirs (or count as the widest where no two start alike), and a repeat whose next character tells it to stop or go on counts one way a run, while one that may stop anywhere counts once for each count it may stop at. Items that end the pattern and cannot fail, such as optional groups with nothing after them, are tried once, and their ways add to those of what comes before them. The whole pattern is counted because what comes before a loop decides how often it is entered and what comes after it how often it is tried again: `/^(a?){0,10}(a?){0,10}$/` and `/^\w*(a?){0,10}$/` are reported, the first running past PHP's default `pcre.backtrack_limit` on seven `a` then `!`. Where every attempt starts at one place (the pattern opens with `^` without `m`, `\A` or `\G` in every branch, or has the `A` modifier), 2,048 ways are allowed: `/^(a{0,4}){0,4}$/` (781 ways, 438 steps at most on PCRE2 10.49) and `/^(a?){0,10}$/` (2,047 ways, 2,559 steps) are not reported, nor are the syllable patterns of a shaping library that nest `?`, `{0,2}`, `{0,3}` and `{0,4}` (73 steps at most), while `/^(a?){0,12}$/` (8,191 ways, 10,239 steps), `/^(a{0,6}){0,6}$/` (56,024 steps) and `/^(a{0,16}){0,16}$/`, which runs past the limit on seven `a` then `!`, are. A search that may start anywhere pays the attempt at every offset, and the limit counts each start apart, so only 7 ways are allowed there: `/(a?){0,2}(?:b|c)/` (14 steps a start, about 20 ms on 100,000 `a` without the JIT) is not reported, `/(a{0,2}){0,2}(?:b|c)/` (13 ways, about 30 ms) and `/(a?){0,10}(?:b|c)/` (about 10 s) are. The steps are the smallest `pcre.backtrack_limit` under which `preg_match()` finishes with `pcre.jit=0`, on the costliest subject a search finds: of 426 random patterns the rule leaves out this way, none whose attempts start at one place takes more than 5,117, and none that a search may start anywhere in more than 26 a start. An unbounded repeat, a reference, a subroutine call or a conditional anywhere in the pattern keeps the issue.
- **The loop ends an atomic group**, or the body of a possessive loop, with nothing after it there that can fail: `/(?>(?:a+)+)$/` and `/(?:(?:a+)+)++$/` never retry the loop. A subroutine call that runs a group between the loop and the atomic group runs the loop without it, so `/^(?:(?>x((?:a+)+))|y(?1)$)/` is reported.

With the ReDoS analysis on (`--redos`, or `checks.redos.enabled` in `regex.json`), a pattern the analysis proves linear drops the issue: the proof outranks the heuristic. That holds for a pattern with inline options too, an option setting such as `(?s)` or a scoped group such as `(?i-r:…)`, which the proof reads as PCRE does. The issue is also left out for a pattern listed in the ignored patterns or found trivially safe.

**Visual Explanation:**
```
Pattern: /(a+)+b/
Input: "aaaaa!"
Inner (a+) can match 1..n and the outer + repeats 1..n
Result: many backtracking paths
```

**Example:**
```php
// VULNERABLE: Nested quantifiers can cause ReDoS
preg_match('/(a+)+b/', $input);

// SAFER: Use atomic groups
preg_match('/(?>a+)+b/', $input);

// SAFER: Use possessive quantifier
preg_match('/(a++)+b/', $input);
```

**Fix:** Refactor to be deterministic, or use atomic groups/possessive quantifiers — but verify the rewrite still matches everything you need: when the inner part is ambiguous (`(?:ab|a)+b`), an atomic inner group removes the backtracking between iterations and can change the language.

**Read more:**
- [OWASP: ReDoS](https://community.owasp.org/attacks/Regular_expression_Denial_of_Service_-_ReDoS)

---

**Try it.** [Open `/(a+)+b/` in the playground](/#pattern=%2F%28a%2B%29%2Bb%2F&subject=aaaaaaaaaa%21): the finding is in the Diagnostics tab.
