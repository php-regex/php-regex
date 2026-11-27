---
description: "The quick fix of each common PHP regex error and lint finding: one line for the moment, and the page that explains it."
head_title: "PHP regex errors: the quick fix of each one"
---
# Diagnostics Cheat Sheet

<div class="page-filter">
  <label class="visually-hidden" for="fix-filter-input">Filter the quick fixes</label>
  <input type="search" id="fix-filter-input" data-items=".fix-index > li" data-unit="fixes" placeholder="Filter by error, rule or fix — e.g. delimiter, backreference, unicode" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>No fix matches that filter — try a shorter fragment, or press Escape to see them all again.</p>

The fix of each error and each finding a reader meets often: one line for the moment, and the page that explains it. [The errors PHP prints](diagnostics.md#the-errors-php-prints) holds every message of the engine; [the rule reference](rules.md), every rule of the linter.

<ul class="fix-index">
{%- assign quick_fixes = site.pages | where_exp: "p", "p.quick_fix" | sort: "quick_fix_order" %}
{%- for p in quick_fixes -%}
<li><a href="{{ p.url }}">{{ p.title }}</a> — {{ p.quick_fix }}.</li>
{%- endfor -%}
</ul>

A finding that is none of these is in [the rule reference](rules.md), by area. A pattern PHPRegex itself stops reading, for its length or its nesting, is answered in the [troubleshooting guide](../troubleshooting.md#pattern-exceeds-maximum-length).
