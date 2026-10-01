<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex CLI
============

The regex console: sixteen subcommands to parse, explain, validate, lint, hunt ReDoS, transpile, diagram and debug patterns — also shipped as a self-updating regex.phar.

Features
--------

- Lints a whole code base: patterns extracted from `preg_*` calls and four wrapper libraries, judged by 28 rules.
- ReDoS analysis in two modes — theoretical (structural) and confirmed (witness input) — plus a benchmark command.
- Automata comparison of two patterns: intersection, subset, equivalence, with counter-examples.
- Transpiles PCRE patterns to JavaScript and Python; renders the AST as text or SVG and the NFA as DOT or Mermaid.
- CI-ready reports in five formats with three stable exit codes, and a self-updating `regex.phar`.

Installation
------------

```bash
composer require --dev php-regex/regex-cli
```

The binary is `vendor/bin/regex` and requires PHP 8.2+.

### Standalone phar

```bash
curl -Ls https://github.com/php-regex/php-regex/releases/latest/download/regex.phar -o ~/.local/bin/regex && chmod +x ~/.local/bin/regex
regex self-update
```

Commands
--------

| Command | Description | Command | Description |
|---------|-------------|---------|-------------|
| `parse` | Parse and recompile a pattern | `highlight` | Highlight a regex for display |
| `analyze` | Parse, validate, analyze ReDoS risk | `validate` | Validate a pattern |
| `compare` | Compare two patterns (automata) | `transpile` | Transpile to js or python |
| `explain` | Explain a pattern in plain language | `lint` | Lint patterns in PHP sources |
| `debug` | Deep ReDoS analysis, heatmap | `clear-cache` | Clear the parser cache |
| `redos` | Benchmark patterns for ReDoS | `version` | Display version information |
| `diagram` | AST diagram, text or SVG | `self-update` | Update the phar in place |
| `graph` | NFA graph, DOT or Mermaid | `help` | Display the help message |

Configuration
-------------

Global options:

| Option | Effect |
|--------|--------|
| `--ansi` / `--no-ansi` | Force or disable ANSI output |
| `-q`, `--quiet`, `--silent` | Suppress output |
| `--no-visuals` | Disable banner and section visuals |
| `--php-version <ver>` | Target PHP version for validation |
| `--pcre-version <ver>` | Target PCRE2 release for validation |

`lint` reads defaults from `regex.json` or `regex.dist.json` in the working directory — paths, excludes, extraction interop, which checks run. Command-line options win; `--output <file>` writes the report to a file.

Every command exits `0` when it found nothing wrong, `1` when the patterns or files it judged have a problem, `2` when the command line or configuration cannot be used.

Usage
-----

Validate a pattern — the hello world of the console (outputs below come from `--no-visuals` runs):

```bash
vendor/bin/regex validate '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i' --no-visuals
#   Pattern: /^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i
#   Status: OK

vendor/bin/regex validate '/(?<=a+)b/' --no-visuals  # exits 1
#   Pattern: /(?<=a+)b/
#   Status: INVALID
#   Lookbehind is unbounded. PCRE requires a bounded maximum length.
# Line 1: (?<=a+)b
#         ^
```

`analyze` adds a ReDoS report before explaining the pattern:

```bash
vendor/bin/regex analyze '/(a+)+$/' --no-visuals
#   Pattern: /(a+)+$/
#   Parse: OK
#   Status: OK
#   Status: Potential ReDoS risk (theoretical)
#   Severity: CRITICAL (score 10)
#   Mode: THEORETICAL
#   Confidence: MEDIUM
#   Hotspot:   1-3
```

`lint` walks PHP sources and reports in five formats; with `--format=github` each finding becomes an annotation GitHub renders natively:

```bash
vendor/bin/regex lint src/ --format=github --no-visuals
# Target: PHP 8.2, PCRE2 10.40 (composer.json require.php)
# ::warning file=demo.php,line=3,col=0,title=Lint (regex.lint.quantifier.nested)::Nested quantifiers can cause catastrophic backtracking.%0ASuggestion: Consider using atomic groups (?>...) or possessive quantifiers.
# ::warning file=demo.php,line=3,col=0,title=Lint (regex.lint.group.quantifiedCapture)::Quantified capturing group "(...)" with "+": only the last iteration's capture is retained.%0ASuggestion: Use a non-capturing group (?:...) for the repetition and capture the whole match, or restructure the pattern.
```

`transpile` writes the pattern for another engine:

```bash
vendor/bin/regex transpile '/[a-z]+\d/' --target=js --no-visuals
#   Target: JAVASCRIPT
#   Source: /[a-z]+\d/
#   ...
#   Constructor:
#     new RegExp("[a-z]+\\d", "")
```

Documentation
-------------

- [Quick start](https://github.com/php-regex/php-regex/blob/2.x/docs/QUICK_START.md) — from installation to a first analysis.
- [CLI guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/cli.md) — every subcommand in depth, the lint configuration file, CI recipes.
- [ReDoS guide](https://github.com/php-regex/php-regex/blob/2.x/docs/REDOS_GUIDE.md) — the risky shapes, the two analysis modes, mitigation.
- [Backward compatibility](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md) — the promise that holds across 2.x.

Resources
---------

* [Changelog](CHANGELOG.md)
* [All PHPRegex packages](https://github.com/php-regex/php-regex), released with their siblings under one version number
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).
