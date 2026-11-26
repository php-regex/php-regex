---
description: "A variable quantifier inside another, as in (a+)+, lets a PHP pattern backtrack catastrophically: when it is reported, when it is safe, and the fixes."
head_title: "Nested quantifiers in PHP regex: (a+)+ ReDoS"
parent: /reference/rules/
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
- **A separator splits each iteration**: the inner loop is followed right away by an item it cannot match, and every other item of the iteration matches one way only. In the unrolled loop `/"[^"\\]*(?:\\.[^"\\]*)*"/`, each iteration ends with `[^"\\]*`, and the `\\` that opens the next iteration is what `[^"\\]` refuses, so there is one way to split the input. The separator may stand behind items that may match nothing and take none of the loop's characters: the `,` behind `\s*` keeps the runs of `/^(?:\s*\d+\s*,)*\s*\d+\s*$/` apart. Under `/i` the two sides are compared case-folded, so `/(?:a+,)+$/i` is still exempt, but a loop unrolled this way is never exempt: `/"[^"\\]*(?:\\.[^"\\]*)*"/i` is reported. Without `/u`, under another locale's tables than PCRE2's own, the two sides are compared every byte as those tables give them, the other case of an ASCII letter and the word characters above ASCII included, unless the pattern reads a character caselessly where the engine's paths read some case otherwise (macOS's `tr_TR.ISO8859-9` reads `i`, `I` and `\xDD` so): under glibc's `tr_TR.ISO-8859-9`, which pairs `i` with `\xDD` and `I` with `\xFD`, `/(?:i+I)+$/i` is exempt and `/(?:i+\xDD)+$/i` is reported, and so is `"/(?:\\w+\xDD)+\$/"`, with the byte written as it is, `\w` taking `0xDD` there.
- **The inner loop is in a lookaround**: a lookaround holds without being tried again once it has matched, so a loop inside it splits no run of the loop around: `/^(?:(?=\w+)\w)+$/` and `/^(?:(?<=\s{1,3})\w)+$/` are not reported. A non-atomic lookahead, `(*napla:...)`, is backtracked into: `/^(?:(*napla:a+)a)+$/` is reported.
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
