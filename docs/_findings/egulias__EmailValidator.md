---
layout: landing
title: "egulias/EmailValidator: 1 regex finding"
description: "PHPRegex on egulias/EmailValidator: 0 failures and 1 warning in its regular expressions, judged for PHP 8.1 and PCRE2 10.39."
permalink: /findings/egulias/EmailValidator/
findings_repo: egulias/EmailValidator
findings_slug: egulias__EmailValidator
findings_count: 1
findings_fail: 0
findings_warn: 1
findings_php: "8.1"
findings_pcre: "10.39"
findings_committed: 2026-11-22
findings_rank: 104
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "/[^\\p{S}\\p{C}\\p{Cc}]+/iu"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/egulias\"><img src=\"/assets/findings/avatars/egulias.png\" alt=\"egulias on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">egulias/EmailValidator</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/egulias/EmailValidator/blob/9bef884e12278a7b89b64adbfb9379c021d3c102/src/EmailLexer.php#L109-L115\">View on GitHub at 9bef884</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 8.1</span><span class=\"pg-finding-engine\">PCRE2 10.39</span><span class=\"pg-finding-asof\">composer.json require.php · code as of 2026-11-22</span></p><p class=\"pg-snippet-file\">src/EmailLexer.php:112 · preg_match() · via self::INVALID_CHARS_REGEX</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">109</span>        &#039;¡&#039;    =&gt; self::INVERT_EXCLAMATION,</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">110</span>    ];</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">111</span></span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">112</span>    public const INVALID_CHARS_REGEX = <span class=\"pg-snippet-pattern pg-snippet-quote\">&quot;</span><span class=\"pg-snippet-pattern pg-tok-meta\">/[^</span><span class=\"pg-snippet-pattern pg-tok-escape\">\\p&#123;S&#125;\\p&#123;C&#125;\\p&#123;Cc&#125;</span><span class=\"pg-snippet-pattern pg-tok-meta\">]</span><span class=\"pg-snippet-pattern pg-tok-quantifier\">+</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-tok-flag\">iu</span><span class=\"pg-snippet-pattern pg-snippet-quote\">&quot;</span>;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">113</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">114</span>    public const VALID_UTF8_REGEX = &#039;/\\p&#123;Cc&#125;+/u&#039;;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">115</span></span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Flag &#039;i&#039; is useless: the pattern contains no case-sensitive characters.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/useless-flag-i-caseless/\">regex.lint.flag.useless.i</a></li></ul><div class=\"pg-rewrite\" hidden><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">1 finding</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-b213acd9f7"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">src/EmailLexer.php:112</code><a class="finding-row-replay" href="#f-b213acd9f7">Replay</a></p><code class="finding-row-pattern">/[^\p&#123;S&#125;\p&#123;C&#125;\p&#123;Cc&#125;]+/iu</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-flag-i-caseless/">regex.lint.flag.useless.i</a> <span class="finding-row-message">Flag &#039;i&#039; is useless: the pattern contains no case-sensitive characters.</span></li></ul></li></ol></section>
