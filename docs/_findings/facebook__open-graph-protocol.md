---
layout: landing
title: "facebook/open-graph-protocol: 4 regex findings"
description: "PHPRegex on facebook/open-graph-protocol: 0 failures and 4 warnings in its regular expressions, judged for PHP 8.1 and PCRE2 10.39."
permalink: /findings/facebook/open-graph-protocol/
findings_repo: facebook/open-graph-protocol
findings_slug: facebook__open-graph-protocol
findings_count: 4
findings_fail: 0
findings_warn: 4
findings_php: "8.1"
findings_pcre: "10.39"
findings_committed: 2025-06-25
findings_rank: 113
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "{^\\xEF\\xBB\\xBF|\\x1A}"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/facebook\"><img src=\"/assets/findings/avatars/facebook.png\" alt=\"facebook on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">facebook/open-graph-protocol</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/facebook/open-graph-protocol/blob/718f79b0806ec0270d19d6943a01fa733fe9b898/lib/Michelf/Markdown.php#L223-L229\">View on GitHub at 718f79b</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 8.1</span><span class=\"pg-finding-engine\">PCRE2 10.39</span><span class=\"pg-finding-asof\">code as of 2025-06-25</span></p><p class=\"pg-snippet-file\">lib/Michelf/Markdown.php:226 · preg_replace()</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">223</span>\t\t$this-&gt;setup();</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">224</span>\t</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">225</span>\t\t# Remove UTF-8 BOM and marker character in input, if present.</span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">226</span>\t\t$text = preg_replace(<span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span><span class=\"pg-snippet-pattern pg-tok-meta\">&#123;</span><span class=\"pg-snippet-pattern pg-tok-anchor\">^</span><span class=\"pg-snippet-pattern pg-tok-escape\">\\xEF\\xBB\\xBF</span><span class=\"pg-snippet-pattern pg-tok-meta\">|</span><span class=\"pg-snippet-pattern pg-tok-escape\">\\x1A</span><span class=\"pg-snippet-pattern pg-tok-meta\">&#125;</span><span class=\"pg-snippet-pattern pg-snippet-quote\">&#039;</span>, &#039;&#039;, $text);</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">227</span></span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">228</span>\t\t# Standardize line endings:</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">229</span>\t\t#   DOS to Unix and Mac to Unix</span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">An anchor holds for its own alternative only: &quot;\\x1A&quot; matches anywhere in the subject.</span><span class=\"pg-finding-hint\">If the anchors are meant for every alternative, group them: &quot;^(?:\\xEF\\xBB\\xBF|\\x1A)&quot;.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/#anchor-precedence-in-alternation\">regex.lint.anchor.alternationPrecedence</a></li></ul><div class=\"pg-rewrite\" hidden><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">4 findings</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-2a25a6b87c"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">lib/Michelf/Markdown.php:226</code><a class="finding-row-replay" href="#f-2a25a6b87c">Replay</a></p><code class="finding-row-pattern">&#123;^\xEF\xBB\xBF|\x1A&#125;</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/anchor-precedence-in-alternation/">regex.lint.anchor.alternationPrecedence</a> <span class="finding-row-message">An anchor holds for its own alternative only: &quot;\x1A&quot; matches anywhere in the subject.</span></li></ul></li><li class="finding-row is-warn" id="finding-f-32641a791e"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">lib/Michelf/Markdown.php:705</code><a class="finding-row-replay" href="#f-32641a791e">Replay</a></p><code class="finding-row-pattern">&#123;
			(					# wrap whole match in $1
			  \[
				([^\[\]]+)		# link text = $2; can&#039;t contain [ or ]
			  \]
			)
			&#125;xs</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-flag-s-dotall/">regex.lint.flag.useless.s</a> <span class="finding-row-message">Flag &#039;s&#039; is useless: the pattern contains no unescaped dot outside a character class.</span></li></ul></li><li class="finding-row is-warn" id="finding-f-1a7c403c5e"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">lib/Michelf/Markdown.php:1458</code><a class="finding-row-replay" href="#f-1a7c403c5e">Replay</a></p><code class="finding-row-pattern">&#123;(\s*&lt;pre&gt;.+?&lt;/pre&gt;)&#125;sx</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-flag-x-extended/">regex.lint.flag.useless.x</a> <span class="finding-row-message">Flag &#039;x&#039; is useless: the pattern contains no whitespace and no # comment.</span></li></ul></li><li class="finding-row is-warn" id="finding-f-0aae512485"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">lib/Michelf/Markdown.php:1618</code><a class="finding-row-replay" href="#f-0aae512485">Replay</a></p><code class="finding-row-pattern">&#123;
			&lt;
			(?:mailto:)?
			(
				(?:
					[-!#$%&amp;&#039;*+/=?^_`.&#123;|&#125;~\w\x80-\xFF]+
				|
					&quot;.*?&quot;
				)
				\@
				(?:
					[-a-z0-9\x80-\xFF]+(\.[-a-z0-9\x80-\xFF]+)*\.[a-z]+
				|
					\[[\d.a-fA-F:]+\]	# IPv4 &amp; IPv6
				)
			)
			&gt;
			&#125;xi</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/duplicate-character-class-elements/">regex.lint.charclass.duplicateChars</a> <span class="finding-row-message">Character class contains duplicate elements that do not add new matches.</span></li></ul></li></ol></section>
