---
layout: landing
title: "spiral/framework: 1 regex finding"
description: "PHPRegex on spiral/framework: 0 failures and 1 warning in its regular expressions, judged for PHP 8.2 and PCRE2 10.40."
permalink: /findings/spiral/framework/
findings_repo: spiral/framework
findings_slug: spiral__framework
findings_count: 1
findings_fail: 0
findings_warn: 1
findings_php: "8.2"
findings_pcre: "10.40"
findings_committed: 2026-11-16
findings_rank: 197
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "~<(style|script|textarea|title)\\b[^>]*+>(?>[^<]++|<(?!/\\1\\s*>))*+</\\1\\s*>(*SKIP)(*FAIL)|(?<!\\]>)<!--(?!\\[if\\b|<!|>)(?>[^-]++|-(?!->))*+-->~is"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/spiral\"><img src=\"/assets/findings/avatars/spiral.png\" alt=\"spiral on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">spiral/framework</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/spiral/framework/blob/9bf2503f1ae7ec9cecd4ab350431b4b46635f635/src/SendIt/src/Renderer/ViewRenderer.php#L52-L58\">View on GitHub at 9bf2503</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 8.2</span><span class=\"pg-finding-engine\">PCRE2 10.40</span><span class=\"pg-finding-asof\">composer.json require.php · code as of 2026-11-16</span></p><p class=\"pg-snippet-file\">src/SendIt/src/Renderer/ViewRenderer.php:55 · preg_replace()</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">52</span>    public static function stripHtmlComments(string $html): string</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">53</span>    &#123;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">54</span>        return \\preg_replace(</span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">55</span>            &#039;~&lt;(style|script|textarea|title)\\b[^&gt;]*+&gt;(?&gt;[^&lt;]++|&lt;(?!/\\1\\s*&gt;))*+&lt;/\\1\\s*&gt;(*SKIP)(*FAIL)&#039;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">56</span>            . &#039;|(?&lt;!\\]&gt;)&lt;!--(?!\\[if\\b|&lt;!|&gt;)(?&gt;[^-]++|-(?!-&gt;))*+--&gt;~is&#039;,</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">57</span>            &#039;&#039;,</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">58</span>            $html,</span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Flag &#039;s&#039; is useless: the pattern contains no unescaped dot outside a character class.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/#useless-flag-s-dotall\">regex.lint.flag.useless.s</a></li></ul><div class=\"pg-rewrite\" hidden><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">1 finding</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-2539da1eab"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">src/SendIt/src/Renderer/ViewRenderer.php:55</code><a class="finding-row-replay" href="#f-2539da1eab">Replay</a></p><code class="finding-row-pattern">~&lt;(style|script|textarea|title)\b[^&gt;]*+&gt;(?&gt;[^&lt;]++|&lt;(?!/\1\s*&gt;))*+&lt;/\1\s*&gt;(*SKIP)(*FAIL)|(?&lt;!\]&gt;)&lt;!--(?!\[if\b|&lt;!|&gt;)(?&gt;[^-]++|-(?!-&gt;))*+--&gt;~is</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-flag-s-dotall/">regex.lint.flag.useless.s</a> <span class="finding-row-message">Flag &#039;s&#039; is useless: the pattern contains no unescaped dot outside a character class.</span></li></ul></li></ol></section>
