---
layout: landing
title: "Static analysis, linter & logic solver for PHP regular expressions"
description: "PHPRegex parses every PCRE pattern into an AST and answers what it means, whether it is safe, and how to make it shorter or provably equivalent."
---

<div class="hero">
  <div class="hero-inner">
    <div class="hero-copy">
      <div class="hero-head">
      <svg class="wordmark" viewBox="0 -60 384 82" width="380" height="81" role="img" focusable="false">
      <title>PHPRegex</title>
      <path class="wordmark-php" d="M3 0V-56.7H27.2Q33.9 -56.7 38.9 -54.3Q43.8 -51.9 46.5 -47.5Q49.2 -43 49.2 -36.9Q49.2 -30.9 46.5 -26.4Q43.8 -22 38.9 -19.6Q33.9 -17.1 27.2 -17.1H18.9V0ZM18.9 -30.2H26Q29.6 -30.2 31.6 -32Q33.7 -33.8 33.7 -36.9Q33.7 -40.1 31.6 -41.9Q29.6 -43.7 26 -43.7H18.9ZM53.1 0V-56.7H68.9V-35.5H87.8V-56.7H103.7V0H87.8V-22.1H68.9V0ZM109.3 0V-56.7H133.4Q140.2 -56.7 145.1 -54.3Q150.1 -51.9 152.7 -47.5Q155.4 -43 155.4 -36.9Q155.4 -30.9 152.7 -26.4Q150.1 -22 145.1 -19.6Q140.2 -17.1 133.4 -17.1H125.1V0ZM125.1 -30.2H132.2Q135.8 -30.2 137.9 -32Q140 -33.8 140 -36.9Q140 -40.1 137.9 -41.9Q135.8 -43.7 132.2 -43.7H125.1Z"/>
      <path class="wordmark-regex" transform="translate(156.3 0)" d="M4.2 0V-56.7H26.9Q33.3 -56.7 38 -54.5Q42.7 -52.2 45.2 -48Q47.7 -43.8 47.7 -38.1Q47.7 -32.5 45 -28.4Q42.4 -24.3 37.6 -22.1L49 0H36.4L26.4 -19.8Q26.3 -19.8 26.2 -19.8H16V0ZM16 -29.2H26.2Q30.9 -29.2 33.6 -31.7Q36.3 -34.1 36.3 -38.1Q36.3 -42.2 33.6 -44.6Q30.8 -47 26.2 -47H16ZM72.6 0.9Q66.5 0.9 61.9 -1.8Q57.4 -4.5 54.9 -9.2Q52.4 -14 52.4 -20.1Q52.4 -26.2 55 -31Q57.5 -35.7 62 -38.4Q66.4 -41.1 72.2 -41.1Q78.1 -41.1 82.6 -38.5Q87 -35.8 89.5 -31.1Q92 -26.4 92 -20.3V-17.3H63.4Q63.5 -12.8 66 -10.2Q68.5 -7.5 72.9 -7.5Q76.2 -7.5 78.4 -8.9Q80.6 -10.3 81.3 -12.6H91.6Q90.8 -8.6 88.2 -5.6Q85.5 -2.6 81.5 -0.8Q77.4 0.9 72.6 0.9ZM63.5 -24.5H81.4Q81 -28.2 78.6 -30.4Q76.2 -32.6 72.4 -32.6Q68.6 -32.6 66.3 -30.4Q63.9 -28.2 63.5 -24.5ZM116.5 16.5Q107.9 16.5 103.3 12.9Q98.6 9.3 97.7 3.4H108.5Q109.2 5.7 111.2 7Q113.3 8.2 116.5 8.2Q120.6 8.2 122.8 5.9Q125.1 3.7 125.1 -0.6V-6.8H125Q123.2 -3.3 120.2 -1.6Q117.1 0 113.2 0Q107.9 0 104 -2.6Q100.1 -5.2 97.9 -9.8Q95.7 -14.4 95.7 -20.4Q95.7 -26.5 97.9 -31.1Q100.1 -35.8 104 -38.4Q107.9 -41.1 113.1 -41.1Q117 -41.1 120 -39.4Q123.1 -37.7 125 -34.4H125.1V-40.2H136.2V-1.6Q136.2 4.8 133.7 8.8Q131.2 12.8 126.8 14.6Q122.3 16.5 116.5 16.5ZM116 -9Q120.3 -9 122.9 -12.1Q125.5 -15.3 125.5 -20.6Q125.5 -25.8 122.9 -29Q120.3 -32.1 116 -32.1Q112 -32.1 109.5 -29.1Q107.1 -26.1 107.1 -20.6Q107.1 -15 109.5 -12Q112 -9 116 -9ZM162 0.9Q155.9 0.9 151.4 -1.8Q146.9 -4.5 144.4 -9.2Q141.9 -14 141.9 -20.1Q141.9 -26.2 144.4 -31Q146.9 -35.7 151.4 -38.4Q155.8 -41.1 161.6 -41.1Q167.5 -41.1 172 -38.5Q176.4 -35.8 178.9 -31.1Q181.4 -26.4 181.4 -20.3V-17.3H152.8Q153 -12.8 155.4 -10.2Q157.9 -7.5 162.3 -7.5Q165.6 -7.5 167.8 -8.9Q170 -10.3 170.7 -12.6H181Q180.3 -8.6 177.6 -5.6Q174.9 -2.6 170.9 -0.8Q166.9 0.9 162 0.9ZM152.9 -24.5H170.8Q170.4 -28.2 168 -30.4Q165.6 -32.6 161.8 -32.6Q158.1 -32.6 155.7 -30.4Q153.3 -28.2 152.9 -24.5ZM183.4 0 197.8 -20.5 184.4 -40.2H196.7L200.2 -34.6Q201.2 -32.8 202.2 -31.2Q203.2 -29.6 204.1 -27.9Q205.1 -29.6 206 -31.2Q207 -32.8 208.1 -34.6L211.7 -40.2H223.8L210.2 -20.8L224.4 0H212.1L208.1 -6.2Q207 -8 206 -9.7Q205 -11.4 204 -13.1Q203 -11.4 201.9 -9.7Q200.9 -8 199.8 -6.2L195.7 0Z"/>
    </svg>
    <h1 class="hero-claim">Static analysis, linter &amp; logic solver for PHP regular expressions.</h1>
    <p class="hero-sub">PHPRegex reads the regexes already living in your code — every preg_* pattern, every route constraint — and tells you what they really mean, whether they are safe, and how to make them shorter, faster, or provably equivalent.</p>
    <div class="hero-ctas">
      <a class="button button-primary" href="/quick-start/">Get started</a>
      <a class="hero-link" href="/docs/">Read the docs →</a>
    </div>
    <div class="install"><pre><code>composer require php-regex/php-regex:2.x-dev</code></pre></div>
    </div>
    <div class="hero-foot">
      <p class="hero-sub">New to regex? There is a <a href="/tutorial/">ten-chapter tutorial</a>.</p>
      <p class="hero-sub">PHP 8.2+ · PCRE2 10.49 · MIT — parsed, linted and proven against a corpus of 170+ real-world codebases: Laravel, Symfony, WordPress, PHPUnit…</p>
    </div>
    </div>
    <div class="terminal">
      <div class="terminal-bar">regex analyze</div>
      <pre class="terminal-body"><span class="command">$ vendor/bin/regex analyze '/^(?:a+)+$/'</span>
PHPRegex 2.0.0-DEV by Younes ENNAJI

Runtime   : PHP 8.4.26
Command   : analyze
PCRE      : 10.49 2026-09-28
PCRE JIT  : 1
Backtrack : 1000000
Recursion : 100000

  [1/4] Parsing pattern
  Pattern
      → /^(?:a+)+$/
  Parse : OK

  [2/4] Validation
  Status : OK

  [3/4] ReDoS analysis
  Status     : <span class="t-key">Exponential backtracking (proven)</span>
  Severity   : CRITICAL (score 10)
  Mode       : THEORETICAL
  Confidence : MEDIUM
  Attack: <span class="t-str">"a" x n . "!"</span>
  Hotspot:   4-6

  [4/4] Explanation
Regex matches
  Anchor: the beginning of a line
  Start Quantified Group (one or more times)
    Non-capturing group
            'a' (one or more times)
    End group
  End Quantified Group
  Anchor: the end of a line
</pre>
    </div>
    <p class="terminal-more">See the full walkthrough in the <a href="/quick-start/">Quick Start</a>.</p>
  </div>
</div>

<section class="facts">
  <ul class="facts-list" aria-label="PHPRegex at a glance">
    <li class="fact"><strong>170+</strong><span>real-world codebases tested</span></li>
    <li class="fact"><strong>PCRE2</strong><span><a href="/reference/pcre2-conformance/">conformance-tested</a></span></li>
    <li class="fact"><strong>PHP 8.2+</strong><span>supported</span></li>
    <li class="fact"><strong>MIT</strong><span>licensed</span></li>
  </ul>
</section>

<section class="pipeline">
  <h2 class="section-title">One pipeline, every question</h2>
  <p>From a pattern literal to an answer, the same three stages every time — no regex is treated as an opaque string.</p>
  <ol class="pipeline-steps">
    <li class="step">
      <span class="step-mark" aria-hidden="true"></span>
      <h3>Lexed</h3>
      <p>The pattern literal is split from its flags and tokenized the way PCRE2 reads it.</p>
    </li>
    <li class="step">
      <span class="step-mark" aria-hidden="true"></span>
      <h3>Parsed</h3>
      <p>An immutable AST — twenty-nine node types, one per construct.</p>
    </li>
    <li class="step">
      <span class="step-mark" aria-hidden="true"></span>
      <h3>Walked</h3>
      <p>Visitors validate, explain, diagram, rewrite, or prove properties of the tree.</p>
    </li>
  </ol>
  <figure class="pipeline-figure">
    <img src="/assets/railroad.svg" alt="Railroad diagram drawn from a parsed pattern: start and end terminals joined by tracks through grouped branches and quantified loops" loading="lazy" width="1886" height="486">
    <figcaption>Drawn from the AST, not hand-drawn — <a href="/guides/cli/">diagram any pattern with the CLI</a>.</figcaption>
  </figure>
</section>

<section class="constellation">
  <h2 class="section-title">Sixteen components, one language</h2>
  <p>Every analysis PHPRegex ships is a small library with one job — compose them, or use the toolkit.</p>
  <h3>Understand</h3>
  <div class="component-grid">
    <article class="component">
      <h4 class="component-name">Parser</h4>
      <p class="component-desc">The PCRE2 regex parser: lexer, immutable AST, a validator that answers as PHP's engine would.</p>
      <a href="/architecture/">Architecture →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Explain</h4>
      <p class="component-desc">Explains, highlights and draws regex ASTs — plain text, HTML, Mermaid, railroad diagrams.</p>
      <a href="/guides/cli/#command-examples">Command examples →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Generator</h4>
      <p class="component-desc">Generates sample strings and test cases a regex matches or rejects.</p>
      <a href="/quick-start/">Quick Start →</a>
    </article>
  </div>
  <h3>Prove</h3>
  <div class="component-grid">
    <article class="component">
      <h4 class="component-name">Automata</h4>
      <p class="component-desc">Compiles the regular subset of PCRE to automata: equivalence, intersection, subset, examples.</p>
      <a href="/reference/logic-solver/">Logic solver →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Redos</h4>
      <p class="component-desc">Finds the patterns that backtrack catastrophically — with the exact input that proves it.</p>
      <a href="/guides/redos/">ReDoS guide →</a>
    </article>
  </div>
  <h3>Fix</h3>
  <div class="component-grid">
    <article class="component">
      <h4 class="component-name">Optimizer</h4>
      <p class="component-desc">Rewrites patterns into shorter equivalents and modernizes old syntax — equivalence provable, opt-in.</p>
      <a href="/reference/rules/">Lint rules →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Rector</h4>
      <p class="component-desc">Rewrites a preg_* call into the string function that does the same, only when the automata prove it.</p>
      <a href="/guides/rector/">Rector guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Transpiler</h4>
      <p class="component-desc">Transpiles PCRE patterns to JavaScript and Python, with the losses reported.</p>
      <a href="/guides/cli/#command-overview">CLI overview →</a>
    </article>
  </div>
  <h3>Integrate</h3>
  <div class="component-grid">
    <article class="component">
      <h4 class="component-name">PHPStan</h4>
      <p class="component-desc">Reports the regex patterns your target PHP refuses — and, opt-in, lint, ReDoS and optimization findings.</p>
      <a href="/guides/phpstan/">PHPStan guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Psalm</h4>
      <p class="component-desc">Types $matches from the pattern, and reports the patterns your target PHP refuses.</p>
      <a href="/guides/psalm/">Psalm guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">LSP</h4>
      <p class="component-desc">Diagnostics, hovers, completions and code actions for the patterns of PHP files, in any LSP editor.</p>
      <a href="/guides/lsp/">LSP guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Laravel</h4>
      <p class="component-desc">The Regex service and facade, with artisan lint, routes, explain, compare and transpile commands.</p>
      <a href="/guides/laravel/">Laravel guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Symfony</h4>
      <p class="component-desc">The Regex service, with console lint, routes, security, analyze, compare and transpile commands.</p>
      <a href="/guides/symfony/">Symfony guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">CLI</h4>
      <p class="component-desc">Sixteen subcommands to parse, explain, validate, lint, hunt ReDoS, transpile and diagram — also a self-updating PHAR.</p>
      <a href="/guides/cli/">CLI guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Linter</h4>
      <p class="component-desc">Lints the regexes of a whole code base — validity, lint rules and ReDoS — with console, JSON and CI reports.</p>
      <a href="/guides/cli/#7-lint-your-codebase">Lint your codebase →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Toolkit</h4>
      <p class="component-desc">One entry point to every analysis: parse, validate, explain, check ReDoS, optimize, generate, transpile and lint.</p>
      <a href="/reference/api/">API reference →</a>
    </article>
  </div>
</section>

<section class="proof">
  <h2 class="section-title">Measured, not promised</h2>
  <div class="proof-grid">
    <article class="proof-card">
      <h3 class="proof-card-title"><a href="/reference/correctness-contracts/">Correctness contracts</a></h3>
      <p>Every guarantee is written down: what is sound, what is heuristic, per feature.</p>
    </article>
    <article class="proof-card">
      <h3 class="proof-card-title"><a href="/reference/pcre2-conformance/">Conformance</a></h3>
      <p>validate() verdicts measured against PHP's own PCRE2 on the official test suite.</p>
    </article>
  </div>
  <ul>
    <li><a href="/reference/feature-support-matrix/">Feature support matrix</a> — what each PCRE2 construct parses into, feature by feature.</li>
    <li><a href="/reference/capture-shapes/">Capture shapes</a> — the capture groups a pattern produces, derived from the AST.</li>
  </ul>
</section>

<section class="constellation">
  <h2 class="section-title">Wire it into your stack</h2>
  <p>Three packages turn the analysis into findings where you already work. Until the 2.0.0 tag, the monorepo install covers all three — see the <a href="/quick-start/">Quick Start</a>.</p>
  <div class="component-grid">
    <article class="component">
      <h4 class="component-name">PHPStan</h4>
      <p class="component-desc">Reports the regex patterns your target PHP refuses — lint, ReDoS and optimization findings on demand.</p>
      <pre><code class="language-bash">composer require --dev php-regex/regex-phpstan</code></pre>
      <pre><code class="language-neon">includes:
    - vendor/php-regex/regex-phpstan/extension.neon</code></pre>
      <a href="/guides/phpstan/">PHPStan guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Psalm</h4>
      <p class="component-desc">Types $matches from the pattern, and reports the patterns your target PHP refuses.</p>
      <pre><code class="language-bash">composer require --dev php-regex/regex-psalm
vendor/bin/psalm-plugin enable php-regex/regex-psalm</code></pre>
      <a href="/guides/psalm/">Psalm guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Rector</h4>
      <p class="component-desc">Rewrites a preg_* call into the string function that does the same, only when the automata prove it.</p>
      <pre><code class="language-bash">composer require --dev php-regex/regex-rector</code></pre>
      <pre><code class="language-php">// inside RectorConfig::configure()
-&gt;withSets([RegexSetList::STRING_FUNCTIONS]);</code></pre>

      <a href="/guides/rector/">Rector guide →</a>
    </article>
  </div>
  <p>Every framework and editor integration — Laravel, Symfony, the language server, the CLI — has its card on the <a href="/guides/">guides index</a>.</p>
</section>
