---
layout: landing
title: "tijsverkoyen/CssToInlineStyles: 1 regex finding"
description: "PHPRegex on tijsverkoyen/CssToInlineStyles: 0 failures and 1 warning in its regular expressions, judged for PHP 7.4 and PCRE2 10.33."
permalink: /findings/tijsverkoyen/CssToInlineStyles/
findings_repo: tijsverkoyen/CssToInlineStyles
findings_slug: tijsverkoyen__CssToInlineStyles
findings_count: 1
findings_fail: 0
findings_warn: 1
findings_php: "7.4"
findings_pcre: "10.33"
findings_committed: 2026-01-06
findings_rank: 215
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "/  \\#/ix"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/tijsverkoyen\"><img src=\"/assets/findings/avatars/tijsverkoyen.png\" alt=\"tijsverkoyen on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">tijsverkoyen/CssToInlineStyles</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/tijsverkoyen/CssToInlineStyles/blob/a0aef6022b3d84b8c72855ded8581458d82d5013/src/Css/Rule/Processor.php#L88-L94\">View on GitHub at a0aef60</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 7.4</span><span class=\"pg-finding-engine\">PCRE2 10.33</span><span class=\"pg-finding-asof\">composer.json require.php · code as of 2026-01-06</span></p><p class=\"pg-snippet-file\">src/Css/Rule/Processor.php:91 · preg_match_all()</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">88</span>     */</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">89</span>    public function calculateSpecificityBasedOnASelector($selector)</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">90</span>    &#123;</span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">91</span>        $idSelectorCount = preg_match_all(<span class=\"pg-snippet-pattern pg-snippet-quote\">&quot;</span><span class=\"pg-snippet-pattern pg-tok-meta\">/  </span><span class=\"pg-snippet-pattern pg-tok-literal\">\\#</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-tok-flag\">ix</span><span class=\"pg-snippet-pattern pg-snippet-quote\">&quot;</span>, $selector, $matches);</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">92</span>        $classAttributesPseudoClassesSelectorsPattern = &quot;  (\\.[\\w]+)                     # classes</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">93</span>                        |</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">94</span>                        \\[(\\w+)                       # attributes</span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Flag &#039;i&#039; is useless: the pattern contains no case-sensitive characters.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/#useless-flag-i-caseless\">regex.lint.flag.useless.i</a></li></ul><div class=\"pg-rewrite\" hidden><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">1 finding</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-faf8531bb8"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">src/Css/Rule/Processor.php:91</code><a class="finding-row-replay" href="#f-faf8531bb8">Replay</a></p><code class="finding-row-pattern">/  \#/ix</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-flag-i-caseless/">regex.lint.flag.useless.i</a> <span class="finding-row-message">Flag &#039;i&#039; is useless: the pattern contains no case-sensitive characters.</span></li></ul></li></ol></section>
