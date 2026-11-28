---
layout: landing
title: "OskarStark/doctor-rst: 1 regex finding"
description: "PHPRegex on OskarStark/doctor-rst: 0 failures and 1 warning in its regular expressions, judged for PHP 8.5 and PCRE2 10.44."
permalink: /findings/OskarStark/doctor-rst/
findings_repo: OskarStark/doctor-rst
findings_slug: OskarStark__doctor-rst
findings_count: 1
findings_fail: 0
findings_warn: 1
findings_php: "8.5"
findings_pcre: "10.44"
findings_committed: 2026-11-25
findings_rank: 159
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "/[\\\\]{1}/"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/OskarStark\"><img src=\"/assets/findings/avatars/OskarStark.png\" alt=\"OskarStark on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">OskarStark/doctor-rst</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/OskarStark/doctor-rst/blob/5b30bd9bfa348ebd5108ee45024d3c915290391b/src/Helper/PhpHelper.php#L32-L38\">View on GitHub at 5b30bd9</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 8.5</span><span class=\"pg-finding-engine\">PCRE2 10.44</span><span class=\"pg-finding-asof\">composer.json require.php · code as of 2026-11-25</span></p><p class=\"pg-snippet-file\">src/Helper/PhpHelper.php:35 · preg_match()</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">32</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">33</span>    public static function containsBackslash(string $string): bool</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">34</span>    &#123;</span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">35</span>        return (bool) preg_match(<span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span><span class=\"pg-snippet-pattern pg-tok-meta\">/[</span><span class=\"pg-snippet-pattern pg-tok-literal\">\\\\\\\\</span><span class=\"pg-snippet-pattern pg-tok-meta\">]</span><span class=\"pg-snippet-pattern pg-tok-quantifier\">&#123;1&#125;</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span>, $string);</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">36</span>    &#125;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">37</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">38</span>    public static function isFirstLineOfMultilineComment(Line $line): bool</span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Quantifier is redundant; it matches exactly once.</span><span class=\"pg-finding-hint\">Remove the &#123;1&#125; quantifier; it does not change the match.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/useless-quantifier/\">regex.lint.quantifier.useless</a></li></ul><div class=\"pg-rewrite\"><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"><span class=\"pg-fix-del\">- /[\\\\]&#123;1&#125;/</span>\n<span class=\"pg-fix-add\">+ /[\\\\]/</span>\n<span class=\"pg-rewrite-change\">Removed a quantifier that repeats once.</span></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">1 finding</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-428c554ac3"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">src/Helper/PhpHelper.php:35</code><a class="finding-row-replay" href="#f-428c554ac3">Replay</a></p><code class="finding-row-pattern">/[\\]&#123;1&#125;/</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-quantifier/">regex.lint.quantifier.useless</a> <span class="finding-row-message">Quantifier is redundant; it matches exactly once.</span></li></ul></li></ol></section>
