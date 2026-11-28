---
layout: landing
title: "Sylius/Sylius: 1 regex finding"
description: "PHPRegex on Sylius/Sylius: 0 failures and 1 warning in its regular expressions, judged for PHP 8.2 and PCRE2 10.40."
permalink: /findings/Sylius/Sylius/
findings_repo: Sylius/Sylius
findings_slug: Sylius__Sylius
findings_count: 1
findings_fail: 0
findings_warn: 1
findings_php: "8.2"
findings_pcre: "10.40"
findings_committed: 2026-11-23
findings_rank: 201
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "/\\n+\\s+/"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/Sylius\"><img src=\"/assets/findings/avatars/Sylius.png\" alt=\"Sylius on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">Sylius/Sylius</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/Sylius/Sylius/blob/099e93e951988b2de8019ba3f87d3730ae298222/src/Sylius/Behat/Service/Checker/EmailChecker.php#L44-L50\">View on GitHub at 099e93e</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 8.2</span><span class=\"pg-finding-engine\">PCRE2 10.40</span><span class=\"pg-finding-asof\">composer.json require.php · code as of 2026-11-23</span></p><p class=\"pg-snippet-file\">src/Sylius/Behat/Service/Checker/EmailChecker.php:47 · preg_replace()</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">44</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">45</span>        foreach ($messages as $email) &#123;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">46</span>            if ($this-&gt;isMessageTo($email, $recipient)) &#123;</span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">47</span>                $emailTextContent = trim(preg_replace(<span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-tok-literal\">\\n</span><span class=\"pg-snippet-pattern pg-tok-quantifier\">+</span><span class=\"pg-snippet-pattern pg-tok-escape\">\\s</span><span class=\"pg-snippet-pattern pg-tok-quantifier\">+</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span>, &#039; &#039;, strip_tags($email-&gt;getHtmlBody())));</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">48</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">49</span>                if (str_contains($emailTextContent, $message)) &#123;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">50</span>                    return true;</span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Concatenated quantifiers can be optimized when one character set is a subset of the other.</span><span class=\"pg-finding-hint\">Consider tightening the first quantifier to its minimum.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/optimal-quantifier-concatenation/\">regex.lint.quantifier.concatenation</a></li></ul><div class=\"pg-rewrite\" hidden><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">1 finding</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-7c3e508f00"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">src/Sylius/Behat/Service/Checker/EmailChecker.php:47</code><a class="finding-row-replay" href="#f-7c3e508f00">Replay</a></p><code class="finding-row-pattern">/\n+\s+/</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/optimal-quantifier-concatenation/">regex.lint.quantifier.concatenation</a> <span class="finding-row-message">Concatenated quantifiers can be optimized when one character set is a subset of the other.</span></li></ul></li></ol></section>
