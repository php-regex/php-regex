---
layout: landing
title: "larastan/larastan: 2 regex findings"
description: "PHPRegex on larastan/larastan: 0 failures and 2 warnings in its regular expressions, judged for PHP 8.2 and PCRE2 10.40."
permalink: /findings/larastan/larastan/
findings_repo: larastan/larastan
findings_slug: larastan__larastan
findings_count: 2
findings_fail: 0
findings_warn: 2
findings_php: "8.2"
findings_pcre: "10.40"
findings_committed: 2026-11-22
findings_rank: 134
image: /assets/img/findings-social.png
image_alt: "PHPRegex findings: what the linter reports on real open-source PHP."
finding_pattern: "/\n    (\n        (\n            (?<!\\w)\n            (trans|trans_choice|Lang::get|Lang::choice|Lang::trans|Lang::transChoice|__|\\$t)\n        )\n        |\n        (@lang|@choice)\n    )\n    \\(\n    (?P<quote>['\"])\n    (?P<string>(\\\\.|(?!(?P=quote))[^\\\\\\\\])*?)\n    (?P=quote)\n    [),]\n/mix"
finding_card: "<div class=\"pg-finding-head\"><a class=\"pg-finding-avatar\" href=\"https://github.com/larastan\"><img src=\"/assets/findings/avatars/larastan.png\" alt=\"larastan on GitHub\" width=\"24\" height=\"24\"></a><span class=\"pg-finding-repo\">larastan/larastan</span><a class=\"pg-snippet-permalink\" href=\"https://github.com/larastan/larastan/blob/3b2e33e8f43bf29c2d2c2214e9494c07d3483a47/src/Collectors/UsedTranslationViewCollector.php#L26-L32\">View on GitHub at 3b2e33e</a></div><p class=\"pg-finding-target\"><span class=\"visually-hidden\">Judged for </span><span class=\"pg-finding-engine\">PHP 8.2</span><span class=\"pg-finding-engine\">PCRE2 10.40</span><span class=\"pg-finding-asof\">composer.json require.php · code as of 2026-11-22</span></p><p class=\"pg-snippet-file\">src/Collectors/UsedTranslationViewCollector.php:29 · preg_match_all() · via self::TRANSLATION_REGEX</p><pre class=\"pg-snippet\" aria-label=\"The code at the clone's commit\"><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">26</span>final class UsedTranslationViewCollector</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">27</span>&#123;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">28</span>    /** @see https://regex101.com/r/xFN4fv/1 */</span><span class=\"pg-snippet-line is-focus\"><span class=\"pg-snippet-number\">29</span>    private const TRANSLATION_REGEX = &lt;&lt;&lt;&#039;REGEXP&#039;</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">30</span>    /</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">31</span>        (</span><span class=\"pg-snippet-line\"><span class=\"pg-snippet-number\">32</span>            (</span></pre><ul class=\"pg-finding-issues\"><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Flag &#039;m&#039; is useless: the pattern contains no ^ or $ anchor.</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/useless-flag-m-multiline/\">regex.lint.flag.useless.m</a></li><li class=\"pg-finding-issue-row is-warn\"><span class=\"pg-finding-level\">WARN</span><span class=\"pg-finding-message\">Redundant elements detected in character class.</span><span class=\"pg-finding-hint\">Redundant elements: &#039;\\\\&#039; (duplicate)</span><a class=\"pg-finding-rule\" href=\"https://php-regex.com/reference/rules/redundant-character-class-elements/\">regex.lint.charclass.redundant</a></li></ul><div class=\"pg-rewrite\"><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"><span class=\"pg-fix-del\">- /\n    (\n        (\n            (?&lt;!\\w)\n            (trans|trans_choice|Lang::get|Lang::choice|Lang::trans|Lang::transChoice|__|\\$t)\n        )\n        |\n        (@lang|@choice)\n    )\n    \\(\n    (?P&lt;quote&gt;[&#039;&quot;])\n    (?P&lt;string&gt;(\\\\.|(?!(?P=quote))[^\\\\\\\\])*?)\n    (?P=quote)\n    [),]\n/mix</span>\n<span class=\"pg-fix-add\">+ /\n    (\n        (\n            (?&lt;!\\w)\n            (trans|trans_choice|Lang::get|Lang::choice|Lang::trans|Lang::transChoice|__|\\$t)\n        )\n        |\n        (@lang|@choice)\n    )\n    \\(\n    (?P&lt;quote&gt;[&#039;&quot;])\n    (?P&lt;string&gt;(\\\\.|(?!(?P=quote))[^\\\\\\\\])*?)\n    (?P=quote)\n    [),]\n/ix</span>\n<span class=\"pg-rewrite-change\">Removed a flag nothing in the pattern reads.</span></pre></div><p class=\"pg-witness\" hidden></p><p class=\"pg-finding-outcome\" hidden></p>"
---

{% include finding.html %}

<section class="findings-list" aria-labelledby="findings-list-title"><h2 id="findings-list-title">2 findings</h2><p class="findings-list-lead">What the linter reports on the code at the commit named above. A finding opens in the tool.</p><ol class="findings-rows"><li class="finding-row is-warn" id="finding-f-8454dab70e"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">src/Collectors/UsedTranslationViewCollector.php:29</code><a class="finding-row-replay" href="#f-8454dab70e">Replay</a></p><code class="finding-row-pattern">/
    (
        (
            (?&lt;!\w)
            (trans|trans_choice|Lang::get|Lang::choice|Lang::trans|Lang::transChoice|__|\$t)
        )
        |
        (@lang|@choice)
    )
    \(
    (?P&lt;quote&gt;[&#039;&quot;])
    (?P&lt;string&gt;(\\.|(?!(?P=quote…</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-flag-m-multiline/">regex.lint.flag.useless.m</a> <span class="finding-row-message">Flag &#039;m&#039; is useless: the pattern contains no ^ or $ anchor.</span></li><li><a class="finding-row-rule" href="/reference/rules/redundant-character-class-elements/">regex.lint.charclass.redundant</a> <span class="finding-row-message">Redundant elements detected in character class.</span></li></ul></li><li class="finding-row is-warn" id="finding-f-63d614d00a"><p class="finding-row-head"><span class="finding-row-level">WARN</span><code class="finding-row-place">src/Collectors/UsedViewInAnotherViewCollector.php:22</code><a class="finding-row-replay" href="#f-63d614d00a">Replay</a></p><code class="finding-row-pattern">/@(extends|include(If|Unless|When|First)?)(\(.*?([&#039;&quot;])(.*?)([&#039;&quot;])([),]))/m</code><ul class="finding-row-issues"><li><a class="finding-row-rule" href="/reference/rules/useless-flag-m-multiline/">regex.lint.flag.useless.m</a> <span class="finding-row-message">Flag &#039;m&#039; is useless: the pattern contains no ^ or $ anchor.</span></li></ul></li></ol></section>
