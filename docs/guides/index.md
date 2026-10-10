---
title: Guides
description: "One card per PHPRegex integration — PHPStan, Psalm, Rector, Laravel, Symfony, LSP, CLI — each with the command that installs it."
---

# Guides

PHPRegex plugs into the tools you already run. Every integration installs one package, registers itself, and starts reporting. The commands below are the target form; until the 2.0.0 tag, one install covers them all:

```bash
composer require --dev php-regex/php-regex:2.x-dev
```

See the [Quick Start](/quick-start/) for what that install provides.

## Static analysis

### [PHPStan](phpstan.md)

Reports the regex patterns your target PHP refuses — and, on demand, lint, ReDoS and optimization findings inside static analysis.

```bash
composer require --dev php-regex/regex-phpstan
```

### [Psalm](psalm.md)

Types `$matches` from the pattern — the shape of the captures, not `array<array-key, string>` — and reports the patterns your target PHP refuses.

```bash
composer require --dev php-regex/regex-psalm
```

### [Rector](rector.md)

Rewrites a `preg_*` call into the string function that does the same, only when the automata prove the two answer alike on every subject.

```bash
composer require --dev php-regex/regex-rector
```

## Frameworks

### [Laravel](laravel.md)

The `Regex` service and facade, with the `php artisan regex:lint`, `regex:routes`, `regex:explain`, `regex:compare` and `regex:transpile` commands.

```bash
composer require --dev php-regex/regex-laravel
```

### [Symfony](symfony.md)

The `php_regex.regex` service, with the `bin/console regex:lint`, `regex:routes`, `regex:security`, `regex:analyze`, `regex:compare` and `regex:transpile` commands.

```bash
composer require --dev php-regex/regex-symfony
```

## Editors

### [LSP](lsp.md)

Diagnostics, hovers, completions and code actions for the patterns of PHP files, in any editor that speaks the Language Server Protocol.

```bash
composer require --dev php-regex/regex-language-server
```

## The CLI

### [CLI](cli.md)

Sixteen subcommands to parse, explain, validate, lint, hunt ReDoS, transpile and diagram — also a self-contained PHAR.

```bash
composer require --dev php-regex/regex-cli
```

## Deep dives

### [ReDoS guide](redos.md)

What PHPRegex proves about a pattern — the verdicts, the guarantees, confirmed mode, and how to fix a vulnerable pattern. The `redos()` API ships in the toolkit.

```bash
composer require --dev php-regex/regex-toolkit
```

### [Regex in PHP](regex-in-php.md)

How PCRE behaves inside `preg_*` — delimiters, flags, the functions and their return values — no install required, just PHP.
