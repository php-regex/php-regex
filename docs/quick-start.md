---
description: "Install PHPRegex and run your first regex analysis in five minutes with the CLI or the PHP API."
redirect_from:
  - /QUICK_START.html
---
# Quick Start

This page is the shortest path from installation to a useful result. It does not teach regular expressions; the [tutorial](/tutorial/) does that. When this page ends, the linked guide picks up the deeper workflow.

## Installation

{% include install-prerelease.html package="php-regex/regex-cli" dev=true inline=true %}

You need PHP {{ site.data.product.php_floor }} or later with the `mbstring` and `tokenizer` extensions. The first two Composer commands run once: while the focused packages are `{{ site.data.product.release }}-dev`, they allow those prerelease versions while still preferring stable releases for everything else. The CLI package provides the `regex` binary and pulls in the toolkit facade, so this page can use both the terminal and the PHP API. For another surface, [choose the right package](/packages/).

Verify the binary:

```bash
vendor/bin/regex --version
```

## Run your first analysis

Start with a date pattern you can read at a glance:

```bash
vendor/bin/regex validate '/^\d{4}-\d{2}-\d{2}$/'
vendor/bin/regex explain '/^\d{4}-\d{2}-\d{2}$/' --no-visuals
```

`validate()` answers whether the target PHP and its PCRE2 release accept the pattern. `explain()` describes what the AST means instead of guessing from string fragments.

Now try a pattern with catastrophic backtracking:

```bash
vendor/bin/regex analyze '/^(?:a+)+$/' --no-visuals
```

The important distinction is the verdict label:

- **Proven** comes from the modeled backtracking automaton for that pattern.
- **Heuristic** means a construct or budget falls outside the proof model.
- **Unknown** means the analysis refuses to guess.

Read the scope before acting on a security finding: [correctness contracts](/reference/correctness-contracts/).

## Try the PHP API

The `Regex` facade is the fastest way to explore the API:

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

$validation = $regex->validate('/^\d{4}-\d{2}-\d{2}$/');
echo $validation->isValid ? "valid\n" : $validation->error."\n";

$redos = $regex->redos('/^(?:a+)+$/');
echo $redos->headline(), "\n";
```

If `redos()` reports a proven witness, the result also carries the input shape that makes the pattern backtrack. Use that witness in a regression test before you rewrite the pattern.

For lower-level work, call `Regex::parser()` and reuse one configured parser instead of building a new one for every analysis:

```php
$parser = $regex->parser();
$ast = $parser->parse('/(?<year>\d{4})/');
$result = $parser->validate('/(?<year>\d{4})/');
```

## Pick your integration

| You want findings in… | Start with… |
|-----------------------|-------------|
| A terminal or CI job | [CLI and lint guide](/guides/cli/) |
| PHPStan | [PHPStan extension guide](/guides/phpstan/) |
| Psalm | [Psalm plugin guide](/guides/psalm/) |
| Your editor | [Language server guide](/guides/lsp/) |
| Laravel | [Laravel integration guide](/guides/laravel/) |
| Symfony | [Symfony bundle guide](/guides/symfony/) |

For a codebase-wide first run:

```bash
vendor/bin/regex lint src/ --redos
```

Start without `--redos` on a large legacy project, then enable it once the ordinary validation and lint findings are under control. Use `--format=github` in GitHub Actions or `--format=json` when another tool consumes the report.

## Where next?

- Learn regex from zero: [tutorial](/tutorial/).
- Understand a warning: [diagnostics cheat sheet](/reference/diagnostics-cheatsheet/).
- Read the PHP surface: [API reference](/reference/api/).
- Check a safety claim: [ReDoS guide](/guides/redos/).
- See what is proved: [correctness contracts](/reference/correctness-contracts/).
