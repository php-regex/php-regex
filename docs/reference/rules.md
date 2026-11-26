---
description: "Every PHPRegex lint rule — when each fires, its message, its severity and its fix — from flags and anchors to ReDoS and Unicode byte traps."
head_title: "PHPRegex lint rules: every rule and its fix"
---

# PHPRegex Rule Reference

This reference documents every lint rule that PHPRegex produces, and the other issues it reports under an identifier of their own. Each has a page of its own, listed here by area with the identifiers it is reported under; the [Quick Reference Table](#quick-reference-table) gives the severity and the fix of each at a glance. Validation error codes live in [Diagnostics: Error Codes](diagnostics.md#error-codes), and the optimizer's rewrite suggestions in [the API reference](api.md).

<div class="page-filter">
  <label class="visually-hidden" for="rules-filter-input">Filter the rule reference</label>
  <input type="search" id="rules-filter-input" data-items=".rule-index > li" data-unit="rules" placeholder="Filter by rule, message or identifier — e.g. useless flag, redos, backreference" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>No rule matches that filter — try a shorter fragment, or press Escape to see all of them again.</p>

## Table of Contents
{: .filter-toc }

| Section                                      | Description                       |
|----------------------------------------------|-----------------------------------|
| [Validation Layers](#validation-layers)      | How PHPRegex analyzes patterns    |
| [Flags](#flags)                              | Flag-related diagnostics          |
| [Anchors](#anchors)                          | Anchor positioning issues         |
| [Quantifiers](#quantifiers)                  | Quantifier-related patterns       |
| [Groups](#groups)                            | Group-related diagnostics         |
| [Lookarounds](#lookarounds)                  | Lookahead contradictions          |
| [Alternation](#alternation)                  | Alternation patterns              |
| [Backreferences](#backreferences)            | Reference diagnostics             |
| [Character Classes](#character-classes)      | Character class issues            |
| [Escapes](#escapes)                          | Escape sequence problems          |
| [Literals](#literals)                        | Literal text that reads badly     |
| [Bytes Without /u](#bytes-without-u)        | Multibyte text read as bytes      |
| [Inline Flags](#inline-flags)                | Inline flag diagnostics           |
| [Pattern Complexity](#pattern-complexity)    | When a pattern is too complex     |
| [Sources](#sources)                          | What the lint could not read      |
| [ReDoS Security](#security-redos)            | Catastrophic backtracking         |
| [Advanced Syntax](#advanced-syntax)          | Pointers to the tutorial          |
| [Compatibility](#compatibility--limitations) | PHP and PCRE2 support             |
| [Diagnostics Catalog](#diagnostics-catalog)  | Error code reference              |
| [Quick Reference Table](#quick-reference-table) | One line per rule              |

---

## Validation Layers

PHPRegex validates patterns through four layers — parse, semantic, runtime (optional), lint — each catching different types of issues. The diagram, one example per layer and the category each refused pattern carries live in [Diagnostics: Validation Layers](diagnostics.md#validation-layers). The layers below this one are the last of the four: the lint rules this page documents.

### PCRE2 Compatibility Contract

PHPRegex targets **PHP's PCRE2 engine** (`preg_*`). Key behaviors that may surprise users:

| Behavior           | Description               | Example                         |
|--------------------|---------------------------|---------------------------------|
| Forward references | Backreference before capture compiles, but the group is unset and the match will fail until it has captured | `/\1(a)/` compiles, but `\1` fails |
| Branch reset       | `(?\|...)` changes capture numbering | `\2` can be invalid in branches |
| `\g{0}`            | Invalid in PHP            | Use `\g<0>` or `(?R)`           |
| Lookbehind         | Must have bounded length  | `(?<=a+)` is invalid            |

---

## Flags

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Flags" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Anchors

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Anchors" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Quantifiers

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Quantifiers" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Groups

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Groups" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Lookarounds

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Lookarounds" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Alternation

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Alternation" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Backreferences

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Backreferences" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Character Classes

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Character Classes" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Escapes

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Escapes" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Literals

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Literals" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Bytes Without /u

Without `/u`, PCRE reads the pattern and the subject as bytes. A character written in
UTF-8 that takes several bytes is several items to it, and three places get it wrong: a
multibyte character in a class, a quantifier after one, and a Unicode property such as
`\p{L}` (`regex.lint.unicode.propertyWithoutU`), which then covers only the first 256
code points.

The three rules report at error severity: the pattern compiles, but does not do what it
says, so `regex lint` exits with 1. Turn one off under `checks.lint.rules` in `regex.json`
(`"unicode.multibyteInClassWithoutU": false`) when bytes are really meant. Two further
rules watch byte mode: the braced hex escape PCRE2 refuses outright, and — off by
default — the ASCII-only shorthands.

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Bytes Without /u" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Inline Flags

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Inline Flags" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Pattern Complexity

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Pattern Complexity" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Sources

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Sources" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Security (ReDoS)

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Security (ReDoS)" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Compatibility & Limitations

### Supported PHP Versions

- **PHP 8.1** and above
- Uses readonly properties and enum features
- Needs the `mbstring` extension. `intl` is optional: it only lets a lint message name the character a `\N{name}` escape spells, an escape PCRE refuses anyway; every verdict and every other lint result is the same without it
- Reads digits, letters and spaces in ASCII, as PCRE does: the locale of the process changes no verdict

### Target Engine

- **PCRE2** via PHP's `preg_*` functions, judged for PHP 7.3 and later, the
  PHP versions on PCRE2; PHP 7.2 and earlier ship PCRE1, which the lint
  never judges: a `composer.json` range that also allows 7.3 or later is
  judged from there, with a note, and any other target below 7.3 is refused

### Known Differences from PCRE1

| Feature           | PCRE2        | Note                  |
|-------------------|--------------|-----------------------|
| `\p{...}` Unicode | Supported    |                       |
| Branch reset `(?\|...)` | Supported    | |

The lint reports one issue of its own in this area:

<ul class="rule-index">
{%- assign rule_pages = site.pages | where: "rule_category", "Compatibility & Limitations" | sort: "rule_order" -%}
{%- for p in rule_pages -%}
<li id="{{ p.path | split: '/' | last | remove: '.md' }}"><a href="{{ p.url }}">{{ p.title }}</a> <span class="rule-index-ids">{%- for id in p.rule_ids -%}<code>{{ id }}</code>{%- endfor -%}</span>{{ p.description }}</li>
{%- endfor -%}
</ul>

---

## Advanced Syntax

The fixes the pages offer lean on three constructs this reference does not teach from scratch:
possessive quantifiers (`a++`), atomic groups (`(?>a+)`) and lookarounds
(`(?=…)`, `(?<=…)`). The tutorial covers each in depth —
[Quantifiers and Greediness](../tutorial/04-quantifiers.md) and
[Performance and ReDoS](../tutorial/08-performance-redos.md) for the first two,
[Lookarounds and Assertions](../tutorial/06-lookarounds.md) for the third, and
[Backreferences, Subroutines, and Recursion](../tutorial/07-backreferences-recursion.md)
for the recursive `(?R)` calls some fixes keep working.

---

## Diagnostics Catalog

Every code an exception or a failed validation carries is listed, with its meaning, in
[Diagnostics: Error Codes](diagnostics.md#error-codes). The lint rule ids below
are a separate vocabulary: they name advice, not a refused pattern.

---

## Quick Reference Table

Every lint rule reports at warning severity unless the table says otherwise. The rules
SonarPHP users know are mapped to these ids in [SonarPHP Regex Rules](sonar.md). A rule of
error severity fails `regex lint` (exit code 1); a warning is printed and leaves the code
at 0; an info, `style` included, is printed under an `INFO` badge and leaves the code at 0.

| Category    | Rule ID                                                                                   | Severity | Quick Fix                         |
|-------------|-------------------------------------------------------------------------------------------|----------|-----------------------------------|
| Flags       | `regex.lint.flag.useless.s`, `.m`, `.i`, `.D`, `.x`                                       | warning  | Remove the unused flag            |
| Anchors     | `regex.lint.anchor.impossible.start`, `.end`, `.boundary`                                 | warning  | Move the anchor                   |
| Anchors     | `regex.lint.anchor.alternationPrecedence`                                                 | warning  | Group the alternatives            |
| Anchors     | `regex.lint.anchor.dollarNewline` (off by default)                                        | info     | Write `\z` or add `D`             |
| Quantifiers | `regex.lint.quantifier.nested`, `regex.lint.dotstar.nested`                               | warning  | Use atomic groups                 |
| Quantifiers | `regex.lint.quantifier.useless`, `.zero`, `.concatenation`, `.lazyEnd`, `.assertion`      | warning  | Simplify the quantifier           |
| Quantifiers | `regex.lint.quantifier.emptyRepeat`, `.possessiveImpossible`                              | warning  | Fix the repeated item             |
| Quantifiers | `regex.lint.quantifier.lazyToClass` (off by default)                                      | perf     | Use a negated class               |
| Quantifiers | `regex.lint.quantifier.uselessLazy` (off by default)                                      | style    | Drop the `?`                      |
| Quantifiers | `regex.lint.lookaround.edgeQuantifier` (off by default)                                   | perf     | Keep the minimum count            |
| Groups      | `regex.lint.group.redundant`, `.empty`                                                    | warning  | Remove the group                  |
| Groups      | `regex.lint.group.alwaysEmptyCapture`                                                     | warning  | Narrow what precedes the group    |
| Lookarounds | `regex.lint.lookaround.impossible`                                                        | warning  | Fix the lookahead                 |
| Lookarounds | `regex.lint.lookaround.redundant` (off by default)                                        | style    | Write the inner body inline       |
| Groups      | `regex.lint.group.quantifiedCapture`                                                      | info for an unnamed group, warning for a named one | Repeat a non-capturing group |
| Alternation | `regex.lint.alternation.duplicateDisjunction`, `.empty`, `.overlap`, `.dotNewline`        | warning  | Simplify or use atomic            |
| Alternation | `regex.lint.overlap.charset`                                                              | warning  | Use atomic or merge the sets      |
| Backrefs    | `regex.lint.backref.useless`, `.undefined`                                                | warning  | Move or remove the backreference  |
| Character   | `regex.lint.charclass.redundant`, `.duplicateChars`, `.suspiciousRange`, `.suspiciousPipe`, `.literalMetachar`, `.backrefAsOctal` | warning | Clean up the class |
| Character   | `regex.lint.charclass.single` (off by default)                                            | style    | Write the character alone         |
| Ranges      | `regex.lint.range.useless`                                                                | warning  | Write the character alone         |
| Escapes     | `regex.lint.escape.suspicious`                                                            | warning  | Fix the escape sequence           |
| Literals    | `regex.lint.literal.multipleSpaces` (off by default)                                      | style    | Count the spaces                  |
| Literals    | `regex.lint.literal.invisible`                                                            | warning  | Write the escape                  |
| Unicode     | `regex.lint.unicode.multibyteInClassWithoutU`, `.quantifiedMultibyteWithoutU`, `.propertyWithoutU`, `.bracedHexWithoutU` | error | Add the `/u` flag |
| Unicode     | `regex.lint.unicode.shorthandWithoutU` (off by default)                                   | style    | Add the `/u` flag                 |
| Inline      | `regex.lint.flag.redundant`, `.override`                                                  | warning  | Remove or scope the inline flag   |
| Complexity  | `regex.lint.complexity`                                                                   | warning  | Split the pattern                 |
| ReDoS       | `regex.lint.redos` (`regex.redos` in PHPStan)                                             | warning; error when `--redos-mode=confirmed` reproduces a verdict at `high` or above or proves one it cannot replay | Use possessive quantifiers |
| Targets     | `regex.lint.compat.meaningChanges`                                                        | warning  | Write it alike for every PHP      |
| ReDoS       | `regex.lint.redos.search` (`regex.redos.search` in PHPStan)                               | warning in every mode; ReDoS severity `medium`, shown from `--redos-threshold=medium` | Anchor the pattern or bound the run |
| Sources     | `regex.lint.source.unreadable`                                                       | error    | A source file could not be read, so its patterns were not linted — fix what the message names: raise `memory_limit`, or exclude the file |
| Sources     | `regex.lint.source.dynamic` (under `--report-dynamic`)                               | info     | The pattern comes from elsewhere: validate it where it enters the program |
| Sources     | `regex.lint.source.dynamicPart` (under `--report-dynamic`)                           | info     | Quote a text with `preg_quote()`, or validate the pattern where it is built |
| Sources     | `regex.lint.source.quotedPart` (under `--report-dynamic`)                            | info     | Nothing to fix: the pattern holds a text known at run time only |

<script>
  // A report printed before the rules had a page of their own links here
  // with the anchor of a heading, and the playground names a rule by its
  // identifier: both open the page of the rule. Without the script the
  // entry stands under its anchor, a click away from its page.
  (function () {
    var key;
    try { key = decodeURIComponent(window.location.hash.slice(1)); } catch (error) { return; }
    if (!key) { return; }
    var entry = document.getElementById(key);
    var listed = entry && entry.parentNode.classList.contains('rule-index');
    var row = listed ? entry : Array.prototype.filter.call(document.querySelectorAll('.rule-index > li'), function (li) {
      return li.textContent.indexOf(key) !== -1;
    })[0];
    var link = row && row.querySelector('a');
    if (link && window.location.href !== link.href) { window.location.replace(link.href); }
  })();
</script>
