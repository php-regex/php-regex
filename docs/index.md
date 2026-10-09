---
layout: landing
title: "Static analysis, linter & logic solver for PHP regular expressions"
description: "PHPRegex parses every PCRE pattern into an AST and answers what it means, whether it is safe, and how to make it shorter or provably equivalent."
---

<div class="hero">
  <div class="hero-inner">
    <svg class="wordmark" viewBox="0 -60 384 82" width="560" height="120" role="img" focusable="false">
      <title>PHPRegex</title>
      <path class="wordmark-php" d="M3.046875 0.0V-56.748046875H27.1552734375Q33.9345703125 -56.748046875 38.8857421875 -54.32958984375Q43.8369140625 -51.9111328125 46.52197265625 -47.47412109375Q49.20703125 -43.037109375 49.20703125 -36.943359375Q49.20703125 -30.8876953125 46.52197265625 -26.431640625Q43.8369140625 -21.9755859375 38.8857421875 -19.55712890625Q33.9345703125 -17.138671875 27.1552734375 -17.138671875H18.890625V0.0ZM18.890625 -30.1640625H25.974609375Q29.5546875 -30.1640625 31.6494140625 -31.9921875Q33.744140625 -33.8203125 33.744140625 -36.943359375Q33.744140625 -40.06640625 31.6494140625 -41.89453125Q29.5546875 -43.72265625 25.974609375 -43.72265625H18.890625ZM53.091796875 0.0V-56.748046875H68.935546875V-35.5341796875H87.826171875V-56.748046875H103.669921875V0.0H87.826171875V-22.1279296875H68.935546875V0.0ZM109.2685546875 0.0V-56.748046875H133.376953125Q140.15625 -56.748046875 145.107421875 -54.32958984375Q150.05859375 -51.9111328125 152.74365234375 -47.47412109375Q155.4287109375 -43.037109375 155.4287109375 -36.943359375Q155.4287109375 -30.8876953125 152.74365234375 -26.431640625Q150.05859375 -21.9755859375 145.107421875 -19.55712890625Q140.15625 -17.138671875 133.376953125 -17.138671875H125.1123046875V0.0ZM125.1123046875 -30.1640625H132.1962890625Q135.7763671875 -30.1640625 137.87109375 -31.9921875Q139.9658203125 -33.8203125 139.9658203125 -36.943359375Q139.9658203125 -40.06640625 137.87109375 -41.89453125Q135.7763671875 -43.72265625 132.1962890625 -43.72265625H125.1123046875Z"/>
      <path class="wordmark-regex" transform="translate(156.2666015625 0)" d="M4.2275390625 0.0V-56.748046875H26.9267578125Q33.3251953125 -56.748046875 37.99072265625 -54.48193359375Q42.65625 -52.2158203125 45.18896484375 -48.0263671875Q47.7216796875 -43.8369140625 47.7216796875 -38.1240234375Q47.7216796875 -32.4873046875 45.03662109375 -28.3740234375Q42.3515625 -24.2607421875 37.5908203125 -22.0517578125L48.978515625 0.0H36.41015625L26.35546875 -19.8427734375Q26.3173828125 -19.8427734375 26.2412109375 -19.8427734375H15.9580078125V0.0ZM15.9580078125 -29.25H26.2412109375Q30.8876953125 -29.25 33.57275390625 -31.66845703125Q36.2578125 -34.0869140625 36.2578125 -38.1240234375Q36.2578125 -42.19921875 33.5537109375 -44.5986328125Q30.849609375 -46.998046875 26.203125 -46.998046875H15.9580078125ZM72.5537109375 0.9140625Q66.4599609375 0.9140625 61.94677734375 -1.7900390625Q57.43359375 -4.494140625 54.93896484375 -9.23583984375Q52.4443359375 -13.9775390625 52.4443359375 -20.0712890625Q52.4443359375 -26.203125 54.97705078125 -30.9638671875Q57.509765625 -35.724609375 61.9658203125 -38.4287109375Q66.421875 -41.1328125 72.1728515625 -41.1328125Q78.1142578125 -41.1328125 82.55126953125 -38.48583984375Q86.98828125 -35.8388671875 89.48291015625 -31.13525390625Q91.9775390625 -26.431640625 91.9775390625 -20.2998046875V-17.291015625H63.375Q63.52734375 -12.8349609375 66.0029296875 -10.1689453125Q68.478515625 -7.5029296875 72.896484375 -7.5029296875Q76.171875 -7.5029296875 78.36181640625 -8.912109375Q80.5517578125 -10.3212890625 81.3134765625 -12.64453125H91.55859375Q90.8349609375 -8.6455078125 88.1689453125 -5.5986328125Q85.5029296875 -2.5517578125 81.4658203125 -0.81884765625Q77.4287109375 0.9140625 72.5537109375 0.9140625ZM63.451171875 -24.451171875H81.3896484375Q80.970703125 -28.2216796875 78.59033203125 -30.41162109375Q76.2099609375 -32.6015625 72.4013671875 -32.6015625Q68.630859375 -32.6015625 66.26953125 -30.41162109375Q63.908203125 -28.2216796875 63.451171875 -24.451171875ZM116.5048828125 16.453125Q107.935546875 16.453125 103.2509765625 12.873046875Q98.56640625 9.29296875 97.728515625 3.427734375H108.544921875Q109.154296875 5.712890625 111.2490234375 6.95068359375Q113.34375 8.1884765625 116.5048828125 8.1884765625Q120.6181640625 8.1884765625 122.84619140625 5.94140625Q125.07421875 3.6943359375 125.07421875 -0.6474609375V-6.8173828125H125.0361328125Q123.24609375 -3.275390625 120.18017578125 -1.61865234375Q117.1142578125 0.0380859375 113.1533203125 0.0380859375Q107.8974609375 0.0380859375 103.974609375 -2.58984375Q100.0517578125 -5.2177734375 97.89990234375 -9.80712890625Q95.748046875 -14.396484375 95.748046875 -20.4140625Q95.748046875 -26.4697265625 97.93798828125 -31.1162109375Q100.1279296875 -35.7626953125 104.03173828125 -38.40966796875Q107.935546875 -41.056640625 113.0771484375 -41.056640625Q116.9619140625 -41.056640625 120.02783203125 -39.39990234375Q123.09375 -37.7431640625 125.0361328125 -34.3916015625H125.07421875V-40.21875H136.1572265625V-1.5615234375Q136.1572265625 4.798828125 133.66259765625 8.77880859375Q131.16796875 12.7587890625 126.75 14.60595703125Q122.33203125 16.453125 116.5048828125 16.453125ZM116.0478515625 -8.9501953125Q120.3134765625 -8.9501953125 122.9033203125 -12.111328125Q125.4931640625 -15.2724609375 125.4931640625 -20.56640625Q125.4931640625 -25.822265625 122.9033203125 -28.9833984375Q120.3134765625 -32.14453125 116.0478515625 -32.14453125Q111.97265625 -32.14453125 109.51611328125 -29.11669921875Q107.0595703125 -26.0888671875 107.0595703125 -20.56640625Q107.0595703125 -15.005859375 109.51611328125 -11.97802734375Q111.97265625 -8.9501953125 116.0478515625 -8.9501953125ZM161.9794921875 0.9140625Q155.8857421875 0.9140625 151.37255859375 -1.7900390625Q146.859375 -4.494140625 144.36474609375 -9.23583984375Q141.8701171875 -13.9775390625 141.8701171875 -20.0712890625Q141.8701171875 -26.203125 144.40283203125 -30.9638671875Q146.935546875 -35.724609375 151.3916015625 -38.4287109375Q155.84765625 -41.1328125 161.5986328125 -41.1328125Q167.5400390625 -41.1328125 171.97705078125 -38.48583984375Q176.4140625 -35.8388671875 178.90869140625 -31.13525390625Q181.4033203125 -26.431640625 181.4033203125 -20.2998046875V-17.291015625H152.80078125Q152.953125 -12.8349609375 155.4287109375 -10.1689453125Q157.904296875 -7.5029296875 162.322265625 -7.5029296875Q165.59765625 -7.5029296875 167.78759765625 -8.912109375Q169.9775390625 -10.3212890625 170.7392578125 -12.64453125H180.984375Q180.2607421875 -8.6455078125 177.5947265625 -5.5986328125Q174.9287109375 -2.5517578125 170.8916015625 -0.81884765625Q166.8544921875 0.9140625 161.9794921875 0.9140625ZM152.876953125 -24.451171875H170.8154296875Q170.396484375 -28.2216796875 168.01611328125 -30.41162109375Q165.6357421875 -32.6015625 161.8271484375 -32.6015625Q158.056640625 -32.6015625 155.6953125 -30.41162109375Q153.333984375 -28.2216796875 152.876953125 -24.451171875ZM183.421875 0.0 197.818359375 -20.490234375 184.412109375 -40.21875H196.67578125L200.2177734375 -34.58203125Q201.24609375 -32.830078125 202.1982421875 -31.1923828125Q203.150390625 -29.5546875 204.1025390625 -27.9169921875Q205.0546875 -29.5546875 206.02587890625 -31.1923828125Q206.9970703125 -32.830078125 208.0634765625 -34.58203125L211.681640625 -40.21875H223.7548828125L210.1962890625 -20.7568359375L224.40234375 0.0H212.1005859375L208.0634765625 -6.169921875Q206.958984375 -7.998046875 205.96875 -9.69287109375Q204.978515625 -11.3876953125 203.98828125 -13.0634765625Q202.9599609375 -11.3876953125 201.931640625 -9.69287109375Q200.9033203125 -7.998046875 199.798828125 -6.169921875L195.685546875 0.0Z"/>
    </svg>
    <h1 class="hero-claim">Static analysis, linter &amp; logic solver for PHP regular expressions.</h1>
    <p class="hero-sub">PHPRegex reads the regexes already living in your code — every preg_* pattern, every route constraint — and tells you what they really mean, whether they are safe, and how to make them shorter, faster, or provably equivalent.</p>
    <div class="hero-ctas">
      <a class="button" href="/docs/">Read the docs</a>
      <a class="button" href="/quick-start/">Get started</a>
      <div class="install"><pre><code>composer require php-regex/php-regex:2.x-dev</code></pre></div>
    </div>
    <p class="hero-sub">New to regex? There is a <a href="/tutorial/">ten-chapter tutorial</a>.</p>
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
  </div>
</div>

<section class="facts">
  <ul class="facts-list">
    <li class="fact"><strong>16</strong><span>components</span></li>
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
</section>

<section class="constellation">
  <h2 class="section-title">Sixteen components, one language</h2>
  <p>Every analysis PHPRegex ships is a small library with one job — compose them, or use the toolkit.</p>
  <h3>Understand</h3>
  <div class="component-grid">
    <article class="component">
      <h4 class="component-name">Parser</h4>
      <p class="component-desc">The PCRE2 regex parser: lexer, immutable AST, a validator that answers as PHP's engine would.</p>
      <a href="/architecture/">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Explain</h4>
      <p class="component-desc">Explains, highlights and draws regex ASTs — plain text, HTML, Mermaid, railroad diagrams.</p>
      <a href="/guides/cli/#command-examples">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Generator</h4>
      <p class="component-desc">Generates sample strings and test cases a regex matches or rejects.</p>
      <a href="/quick-start/">Read more →</a>
    </article>
  </div>
  <h3>Prove</h3>
  <div class="component-grid">
    <article class="component">
      <h4 class="component-name">Automata</h4>
      <p class="component-desc">Compiles the regular subset of PCRE to automata: equivalence, intersection, subset, examples.</p>
      <a href="/reference/logic-solver/">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Redos</h4>
      <p class="component-desc">Finds the patterns that backtrack catastrophically — with the exact input that proves it.</p>
      <a href="/guides/redos/">Read more →</a>
    </article>
  </div>
  <h3>Fix</h3>
  <div class="component-grid">
    <article class="component">
      <h4 class="component-name">Optimizer</h4>
      <p class="component-desc">Rewrites patterns into shorter equivalents and modernizes old syntax — equivalence provable, opt-in.</p>
      <a href="/reference/rules/">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Rector</h4>
      <p class="component-desc">Rewrites a preg_* call into the string function that does the same, only when the automata prove it.</p>
      <a href="/guides/rector/">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Transpiler</h4>
      <p class="component-desc">Transpiles PCRE patterns to JavaScript and Python, with the losses reported.</p>
      <a href="/guides/cli/#command-overview">Read more →</a>
    </article>
  </div>
  <h3>Integrate</h3>
  <div class="component-grid">
    <article class="component">
      <h4 class="component-name">PHPStan</h4>
      <p class="component-desc">Reports the regex patterns your target PHP refuses — and, opt-in, lint, ReDoS and optimization findings.</p>
      <a href="/guides/phpstan/">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Psalm</h4>
      <p class="component-desc">Types $matches from the pattern, and reports the patterns your target PHP refuses.</p>
      <a href="/guides/psalm/">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">LSP</h4>
      <p class="component-desc">Diagnostics, hovers, completions and code actions for the patterns of PHP files, in any LSP editor.</p>
      <a href="/guides/lsp/">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Laravel</h4>
      <p class="component-desc">The Regex service and facade, with artisan lint, routes, explain, compare and transpile commands.</p>
      <a href="/guides/laravel/">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Symfony</h4>
      <p class="component-desc">The Regex service, with console lint, routes, security, analyze, compare and transpile commands.</p>
      <a href="/guides/symfony/">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">CLI</h4>
      <p class="component-desc">Sixteen subcommands to parse, explain, validate, lint, hunt ReDoS, transpile and diagram — also a self-updating PHAR.</p>
      <a href="/guides/cli/">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Linter</h4>
      <p class="component-desc">Lints the regexes of a whole code base — validity, lint rules and ReDoS — with console, JSON and CI reports.</p>
      <a href="/guides/cli/#7-lint-your-codebase">Read more →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Toolkit</h4>
      <p class="component-desc">One entry point to every analysis: parse, validate, explain, check ReDoS, optimize, generate, transpile and lint.</p>
      <a href="/reference/api/">Read more →</a>
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
      <a href="/guides/phpstan/">Read the guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Psalm</h4>
      <p class="component-desc">Types $matches from the pattern, and reports the patterns your target PHP refuses.</p>
      <pre><code class="language-bash">composer require --dev php-regex/regex-psalm
vendor/bin/psalm-plugin enable php-regex/regex-psalm</code></pre>
      <a href="/guides/psalm/">Read the guide →</a>
    </article>
    <article class="component">
      <h4 class="component-name">Rector</h4>
      <p class="component-desc">Rewrites a preg_* call into the string function that does the same, only when the automata prove it.</p>
      <pre><code class="language-bash">composer require --dev php-regex/regex-rector</code></pre>
      <pre><code class="language-php">// inside RectorConfig::configure()
-&gt;withSets([RegexSetList::STRING_FUNCTIONS]);</code></pre>

      <a href="/guides/rector/">Read the guide →</a>
    </article>
  </div>
  <p>Every framework and editor integration — Laravel, Symfony, the language server, the CLI — has its card on the <a href="/guides/">guides index</a>.</p>
</section>
