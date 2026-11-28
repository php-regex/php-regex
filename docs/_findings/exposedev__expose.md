---
layout: landing
title: "exposedev/expose: 1 regex finding"
description: "PHPRegex on exposedev/expose: 0 failures and 1 warning in its regular expressions, judged for PHP 8.2 and PCRE2 10.40."
permalink: /findings/exposedev/expose/
findings_repo: exposedev/expose
findings_slug: exposedev__expose
findings_count: 1
findings_fail: 0
findings_warn: 1
findings_php: "8.2"
findings_pcre: "10.40"
findings_committed: 2026-09-17
findings_rank: 109
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "/]8;;(.*)]8;;/m"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/exposedev\"><img src=\"/assets/findings/avatars/exposedev.png\" alt=\"exposedev on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">exposedev/expose</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/exposedev/expose/blob/d364f8d921f7072c20b782f92d9f919b5513adb2/app/Support/ConsoleSectionOutput.php#L131-L137\">View on GitHub at d364f8d</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 8.2</span><span class=\"pg-finding-engine\">PCRE2 10.40</span><span class=\"pg-finding-asof\">composer.json require.php · code as of 2026-09-17</span></p><p class=\"pg-snippet-file\">app/Support/ConsoleSectionOutput.php:134 · preg_replace()</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">131</span>    private function getDisplayLength(string $text): int</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">132</span>    &#123;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">133</span>        $cleanedText = Helper::removeDecoration($this-&gt;getFormatter(), str_replace(&quot;\\t&quot;, &#039;        &#039;, $text));</span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">134</span>        $cleanedText = preg_replace(<span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-tok-literal\">]8;;</span><span class=\"pg-snippet-pattern pg-tok-group\">(</span><span class=\"pg-snippet-pattern pg-tok-meta\">.</span><span class=\"pg-snippet-pattern pg-tok-quantifier\">*</span><span class=\"pg-snippet-pattern pg-tok-group\">)</span><span class=\"pg-snippet-pattern pg-tok-literal\">]8;;</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-tok-flag\">m</span><span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span>, &#039;&#039;, $cleanedText);</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">135</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">136</span>        return Helper::width($cleanedText);</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">137</span>    &#125;</span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Flag &#039;m&#039; is useless: the pattern contains no ^ or $ anchor.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/useless-flag-m-multiline/\">regex.lint.flag.useless.m</a></li></ul><div class=\"pg-rewrite\" hidden><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">1 finding</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-9900b579f0"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">app/Support/ConsoleSectionOutput.php:134</code><a class="finding-row-replay" href="#f-9900b579f0">Replay</a></p><code class="finding-row-pattern">/]8;;(.*)]8;;/m</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-flag-m-multiline/">regex.lint.flag.useless.m</a> <span class="finding-row-message">Flag &#039;m&#039; is useless: the pattern contains no ^ or $ anchor.</span></li></ul></li></ol></section>
