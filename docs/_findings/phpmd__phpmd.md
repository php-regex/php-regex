---
layout: landing
title: "phpmd/phpmd: 1 regex finding"
description: "PHPRegex on phpmd/phpmd: 0 failures and 1 warning in its regular expressions, judged for PHP 8.1 and PCRE2 10.39."
permalink: /findings/phpmd/phpmd/
findings_repo: phpmd/phpmd
findings_slug: phpmd__phpmd
findings_count: 1
findings_fail: 0
findings_warn: 1
findings_php: "8.1"
findings_pcre: "10.39"
findings_committed: 2026-11-10
findings_rank: 175
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "(\\s+)s"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/phpmd\"><img src=\"/assets/findings/avatars/phpmd.png\" alt=\"phpmd on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">phpmd/phpmd</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/phpmd/phpmd/blob/9fd3a1d6b608ab92f973bc6ada9212e501bf9282/public/scripts/update-rules.php#L67-L73\">View on GitHub at 9fd3a1d</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 8.1</span><span class=\"pg-finding-engine\">PCRE2 10.39</span><span class=\"pg-finding-asof\">composer.json require.php · code as of 2026-11-10</span></p><p class=\"pg-snippet-file\">public/scripts/update-rules.php:70 · preg_replace()</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">67</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">68</span>function normalize($elem)</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">69</span>&#123;</span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">70</span>    return preg_replace(<span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span><span class=\"pg-snippet-pattern pg-tok-meta\">(</span><span class=\"pg-snippet-pattern pg-tok-escape\">\\s</span><span class=\"pg-snippet-pattern pg-tok-quantifier\">+</span><span class=\"pg-snippet-pattern pg-tok-meta\">)</span><span class=\"pg-snippet-pattern pg-tok-flag\">s</span><span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span>, &#039; &#039;, trim((string) $elem));</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">71</span>&#125;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">72</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">73</span>function generate_index(array $sets)</span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Flag &#039;s&#039; is useless: the pattern contains no unescaped dot outside a character class.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/useless-flag-s-dotall/\">regex.lint.flag.useless.s</a></li></ul><div class=\"pg-rewrite\"><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"><span class=\"pg-fix-del\">- (\\s+)s</span>\n<span class=\"pg-fix-add\">+ (\\s+)</span>\n<span class=\"pg-rewrite-change\">Removed a flag nothing in the pattern reads.</span></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">1 finding</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-e7b9a34cfa"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">public/scripts/update-rules.php:70</code><a class="finding-row-replay" href="#f-e7b9a34cfa">Replay</a></p><code class="finding-row-pattern">(\s+)s</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-flag-s-dotall/">regex.lint.flag.useless.s</a> <span class="finding-row-message">Flag &#039;s&#039; is useless: the pattern contains no unescaped dot outside a character class.</span></li></ul></li></ol></section>
