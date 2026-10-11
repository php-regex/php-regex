---
layout: landing
title: "See a PHP regex as a tree"
description: "PHPRegex lexes and parses PCRE patterns into an immutable AST, then explains, validates, and transforms the tree."
---

<div class="hero">
  <div class="hero-inner">
    <div class="hero-copy">
      <div class="hero-head">
        <h1 class="hero-claim">See a PHP regex as a tree.</h1>
        <p class="hero-sub">PHPRegex turns a pattern literal into an immutable AST. Explanations, validation, capture shapes, ReDoS findings, and rewrites all read that same tree.</p>
        <div class="hero-ctas">
          <a class="button button-primary" href="/quick-start/">Start in five minutes</a>
          <a class="hero-link" href="/docs/">Browse the docs →</a>
        </div>
        <div class="install"><pre><code>composer config minimum-stability dev
composer config prefer-stable true
composer require --dev php-regex/regex-cli:{{ site.data.product.release }}-dev</code></pre></div>
      </div>
      <div class="hero-foot">
        <p class="hero-sub">New to regex? Start with the <a href="/tutorial/">ten-chapter tutorial</a>.</p>
        <p class="hero-sub">PHP {{ site.data.product.php_floor }}+ · {{ site.data.product.license }} license · {{ site.data.product.corpus_codebases }} real-world codebases in the analysis corpus.</p>
      </div>
    </div>
    <div class="terminal">
      <div class="terminal-bar">AST</div>
      <pre class="terminal-body"><span class="command">{{ site.data.hero_ast.command | escape }}</span>
{{ site.data.hero_ast.output | escape }}</pre>
      <p class="terminal-more">Learn the tree behind every verdict in <a href="/concepts/ast/">What is an AST?</a>.</p>
    </div>
  </div>
</div>

<section class="facts">
  <ul class="facts-list" aria-label="PHPRegex at a glance">
    <li class="fact"><strong>{{ site.data.product.corpus_codebases }}</strong><span>real-world codebases tested</span></li>
    <li class="fact"><strong>PCRE2</strong><span><a href="/reference/pcre2-conformance/">conformance-tested</a></span></li>
    <li class="fact"><strong>PHP {{ site.data.product.php_floor }}+</strong><span>supported</span></li>
    <li class="fact"><strong>{{ site.data.product.license }}</strong><span>licensed</span></li>
  </ul>
</section>

<section class="constellation">
  <h2 class="section-title">Four questions, one pipeline</h2>
  <p>A regex is source code. PHPRegex gives it a parser, an AST, and analyses that say what they can — and cannot — prove.</p>
  <div class="component-grid">
    <article class="component">
      <h3 class="component-name">Understand</h3>
      <p class="component-desc">Explain a pattern in plain language, print its AST, generate samples, or draw a railroad diagram from the parsed tree.</p>
      <a href="/reference/api/">Read the API →</a>
    </article>
    <article class="component">
      <h3 class="component-name">Validate</h3>
      <p class="component-desc">Judge syntax and compatibility for a PHP and PCRE2 pair, with byte offsets and structured diagnostics.</p>
      <a href="/reference/diagnostics/">Read diagnostics →</a>
    </article>
    <article class="component">
      <h3 class="component-name">Secure</h3>
      <p class="component-desc">Prove backtracking complexity for the modeled PCRE subset, and label heuristic findings as heuristics.</p>
      <a href="/guides/redos/">Read the ReDoS guide →</a>
    </article>
    <article class="component">
      <h3 class="component-name">Improve</h3>
      <p class="component-desc">Shorten patterns, modernize syntax, transpile to other engines, or rewrite only when equivalence is proved.</p>
      <a href="/reference/logic-solver/">See the logic solver →</a>
    </article>
  </div>
</section>

<section class="proof">
  <h2 class="section-title">Measured, not promised</h2>
  <p>Every guarantee names its scope. The modeled subset is proved; unsupported constructs and budget exhaustion are reported as heuristic or unknown.</p>
  <div class="proof-grid">
    <article class="proof-card">
      <h3 class="proof-card-title"><a href="/reference/correctness-contracts/">Correctness contracts</a></h3>
      <p>Soundness, completeness, fallbacks, and unknowns are written down feature by feature.</p>
    </article>
    <article class="proof-card">
      <h3 class="proof-card-title"><a href="/reference/pcre2-conformance/">PCRE2 conformance</a></h3>
      <p>Validation verdicts are compared with PHP's own PCRE2 engine on the official test suite.</p>
    </article>
  </div>
  <ul>
    <li><a href="/reference/feature-support-matrix/">Feature support matrix</a> — what each construct parses into, component by component.</li>
    <li><a href="/reference/capture-shapes/">Capture shapes</a> — what <code>preg_match()</code> writes into <code>$matches</code>.</li>
  </ul>
</section>

<section class="constellation">
  <h2 class="section-title">Put the findings where you work</h2>
  <p>Every integration has its own Composer package. Pick the surface that fits your workflow.</p>
  <div class="component-grid">
    <article class="component">
      <h3 class="component-name">CLI</h3>
      <p class="component-desc">Explain, validate, diagram, lint, analyze ReDoS, compare patterns, and transpile from one binary.</p>
      <pre><code class="language-bash">vendor/bin/regex lint src/</code></pre>
      <a href="/guides/cli/">CLI guide →</a>
    </article>
    <article class="component">
      <h3 class="component-name">PHPStan</h3>
      <p class="component-desc">Reports invalid patterns and optional lint, ReDoS, and optimization findings during static analysis.</p>
      <a href="/guides/phpstan/">PHPStan guide →</a>
    </article>
    <article class="component">
      <h3 class="component-name">LSP</h3>
      <p class="component-desc">Adds diagnostics, hover explanations, completion, and quick fixes to any Language Server Protocol editor.</p>
      <a href="/guides/lsp/">LSP guide →</a>
    </article>
  </div>
  <p>Psalm, Rector, Laravel, and Symfony have dedicated guides on the <a href="/guides/">integrations index</a>.</p>
</section>
