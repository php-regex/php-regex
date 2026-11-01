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
        {% include install.html package="php-regex/regex-cli" dev=true inline=true %}
      </div>
      <div class="hero-foot">
        <p class="hero-sub">New to regex? Start with the <a href="/tutorial/">ten-chapter tutorial</a>. Want the long reads? <a href="/blog/">The articles are measured</a>.</p>
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
    <article class="proof-card">
      <h3 class="proof-card-title"><a href="/reference/feature-support-matrix/">Feature support matrix</a></h3>
      <p>What each construct parses into, component by component — proved, heuristic, or out of model.</p>
    </article>
    <article class="proof-card">
      <h3 class="proof-card-title"><a href="/reference/capture-shapes/">Capture shapes</a></h3>
      <p>What <code>preg_match()</code> writes into <code>$matches</code>, derived from the tree.</p>
    </article>
  </div>
</section>

<section class="constellation">
  <h2 class="section-title">Put the findings where you work</h2>
  <p>Every integration has its own Composer package. Pick the surface that fits your workflow.</p>
  <ul class="landing-rows">
    <li><a class="landing-row" href="/guides/cli/"><span class="landing-row-name">CLI</span><p class="landing-row-desc">Explain, validate, diagram, lint, analyze ReDoS, compare patterns, and transpile from one binary.</p></a></li>
    <li><a class="landing-row" href="/guides/phpstan/"><span class="landing-row-name">PHPStan</span><p class="landing-row-desc">Invalid patterns reported during static analysis; lint, ReDoS, and optimization findings on demand.</p></a></li>
    <li><a class="landing-row" href="/guides/psalm/"><span class="landing-row-name">Psalm</span><p class="landing-row-desc">Types <code>$matches</code> from the pattern, for the PHP version your project targets.</p></a></li>
    <li><a class="landing-row" href="/guides/rector/"><span class="landing-row-name">Rector</span><p class="landing-row-desc">Rewrites a <code>preg_*</code> call into a cheaper string function only when the automata prove them equal.</p></a></li>
    <li><a class="landing-row" href="/guides/phpunit/"><span class="landing-row-name">PHPUnit</span><p class="landing-row-desc">Assert what a pattern matches, capture by capture, with verdicts the parser stands behind.</p></a></li>
    <li><a class="landing-row" href="/guides/laravel/"><span class="landing-row-name">Laravel</span><p class="landing-row-desc">The <code>Regex</code> service and facade, plus Artisan commands for lint, routes, and transpilation.</p></a></li>
    <li><a class="landing-row" href="/guides/symfony/"><span class="landing-row-name">Symfony</span><p class="landing-row-desc">The bundle, the <code>php_regex.regex</code> service, and console commands.</p></a></li>
    <li><a class="landing-row" href="/guides/lsp/"><span class="landing-row-name">Language server</span><p class="landing-row-desc">Diagnostics, hover explanations, completion, and quick fixes in any LSP editor.</p></a></li>
  </ul>
</section>

<section>
  <h2 class="section-title">The measured reads</h2>
  <p>Long-form pieces on PHP regular expressions — every example run against the engine it names, every release claim checked against that release's own sources.</p>
  {% assign articles = site.articles | sort: date | reverse %}
  <ul class="landing-rows">
    {% for article in articles limit:3 %}
    <li><a class="landing-row" href="{{ article.url }}"><span class="landing-row-name">{{ article.title }}</span><p class="landing-row-desc">{{ article.description }}</p><span class="landing-row-meta">{{ article.date | date: '%-d %B %Y' }}</span></a></li>
    {% endfor %}
  </ul>
  <p>All of them live on <a href="/blog/">the articles page</a>.</p>
</section>
