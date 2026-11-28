---
layout: landing
title: "theseer/tokenizer: 1 regex finding"
description: "PHPRegex on theseer/tokenizer: 0 failures and 1 warning in its regular expressions, judged for PHP 8.1 and PCRE2 10.39."
permalink: /findings/theseer/tokenizer/
findings_repo: theseer/tokenizer
findings_slug: theseer__tokenizer
findings_count: 1
findings_fail: 0
findings_warn: 1
findings_php: "8.1"
findings_pcre: "10.39"
findings_committed: 2026-02-03
findings_rank: 214
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "/\\R+/Uu"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/theseer\"><img src=\"/assets/findings/avatars/theseer.png\" alt=\"theseer on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">theseer/tokenizer</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/theseer/tokenizer/blob/52a7b89c4aa776e5dfbb1ae05d29495d55dbea6e/src/Tokenizer.php#L69-L75\">View on GitHub at 52a7b89</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 8.1</span><span class=\"pg-finding-engine\">PCRE2 10.39</span><span class=\"pg-finding-asof\">composer.json require.php · code as of 2026-02-03</span></p><p class=\"pg-snippet-file\">src/Tokenizer.php:72 · preg_split()</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">69</span>            &#125;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">70</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">71</span>            $line   = $tok-&gt;line;</span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">72</span>            $values = preg_split(<span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-tok-escape\">\\R</span><span class=\"pg-snippet-pattern pg-tok-quantifier\">+</span><span class=\"pg-snippet-pattern pg-tok-meta\">/</span><span class=\"pg-snippet-pattern pg-tok-flag\">Uu</span><span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span>, $tok-&gt;text);</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">73</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">74</span>            if (!$values) &#123;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">75</span>                $result-&gt;addToken(</span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Quantifier &quot;+&quot; is lazy under the U flag and ends the pattern, so it always matches its minimum.</span><span class=\"pg-finding-hint\">Write the minimum count, make the quantifier greedy, or anchor what must follow it.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/lazy-quantifier-at-the-end/\">regex.lint.quantifier.lazyEnd</a></li></ul><div class=\"pg-rewrite\" hidden><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">1 finding</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-a1632de1ad"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">src/Tokenizer.php:72</code><a class="finding-row-replay" href="#f-a1632de1ad">Replay</a></p><code class="finding-row-pattern">/\R+/Uu</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/lazy-quantifier-at-the-end/">regex.lint.quantifier.lazyEnd</a> <span class="finding-row-message">Quantifier &quot;+&quot; is lazy under the U flag and ends the pattern, so it always matches its minimum.</span></li></ul></li></ol></section>
