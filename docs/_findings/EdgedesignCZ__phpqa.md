---
layout: landing
title: "EdgedesignCZ/phpqa: 1 regex finding"
description: "PHPRegex on EdgedesignCZ/phpqa: 0 failures and 1 warning in its regular expressions, judged for PHP 7.3 and PCRE2 10.32."
permalink: /findings/EdgedesignCZ/phpqa/
findings_repo: EdgedesignCZ/phpqa
findings_slug: EdgedesignCZ__phpqa
findings_count: 1
findings_fail: 0
findings_warn: 1
findings_php: "7.3"
findings_pcre: "10.32"
findings_committed: 2026-11-20
findings_rank: 103
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "/\\.0$/s"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/EdgedesignCZ\"><img src=\"/assets/findings/avatars/EdgedesignCZ.png\" alt=\"EdgedesignCZ on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">EdgedesignCZ/phpqa</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/EdgedesignCZ/phpqa/blob/37021e7a2803720588d2673ea4b29acc7879a7bf/src/Tools/GetVersions.php#L170-L176\">View on GitHub at 37021e7</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 7.3</span><span class=\"pg-finding-engine\">PCRE2 10.32</span><span class=\"pg-finding-asof\">composer.json require.php · code as of 2026-11-20</span></p><p class=\"pg-snippet-file\">src/Tools/GetVersions.php:173 · preg_replace()</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">170</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">171</span>    public static function normalizeSemver($semver)</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">172</span>    &#123;</span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">173</span>        return preg_replace(<span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-tok-literal\">\\.0</span><span class=\"pg-snippet-pattern pg-tok-anchor\">$</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-tok-flag\">s</span><span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span>, &#039;&#039;, $semver);</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">174</span>    &#125;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">175</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">176</span>    public static function compareVersions($toolVersion, $operator, $version)</span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Flag &#039;s&#039; is useless: the pattern contains no unescaped dot outside a character class.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/useless-flag-s-dotall/\">regex.lint.flag.useless.s</a></li></ul><div class=\"pg-rewrite\"><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"><span class=\"pg-fix-del\">- /\\.0$/s</span>\n<span class=\"pg-fix-add\">+ /\\.0$/</span>\n<span class=\"pg-rewrite-change\">Removed a flag nothing in the pattern reads.</span></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">1 finding</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-aedab6225a"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">src/Tools/GetVersions.php:173</code><a class="finding-row-replay" href="#f-aedab6225a">Replay</a></p><code class="finding-row-pattern">/\.0$/s</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-flag-s-dotall/">regex.lint.flag.useless.s</a> <span class="finding-row-message">Flag &#039;s&#039; is useless: the pattern contains no unescaped dot outside a character class.</span></li></ul></li></ol></section>
