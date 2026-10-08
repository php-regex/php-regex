<p align="center">
    <img src="org-icon.svg?v=1" alt="PHP Regex" width="140">
</p>

<h1 align="center">PHP Regex</h1>

<h3 align="center">Static analysis, linter & logic solver for PHP regular expressions</h3>

Every package is a read-only split of the [php-regex monorepo](https://github.com/php-regex/php-regex) — please open issues and pull requests there.

## 🧰 Start here

- **[`regex-toolkit`](https://github.com/php-regex/regex-toolkit)** — one entry point to every PHPRegex library: parse, validate, explain, check ReDoS, optimize, generate, transpile and lint.

## 📚 Libraries

- [`regex-parser`](https://github.com/php-regex/regex-parser) — lexer, immutable AST, printers and validators for PCRE-style patterns.
- [`regex-linter`](https://github.com/php-regex/regex-linter) — lints the regex patterns of a PHP code base; console, JSON, GitHub, Checkstyle and JUnit reports.
- [`regex-redos`](https://github.com/php-regex/regex-redos) — finds the patterns that backtrack catastrophically (ReDoS), confirmed against the engine.
- [`regex-automata`](https://github.com/php-regex/regex-automata) — compares pattern languages through automata: equivalence, intersection, subset.
- [`regex-optimizer`](https://github.com/php-regex/regex-optimizer) — rewrites patterns into shorter equivalents, checked by language equivalence.
- [`regex-explain`](https://github.com/php-regex/regex-explain) — explains, highlights and draws regex ASTs: text, HTML, ASCII trees, Mermaid and railroad diagrams.
- [`regex-generator`](https://github.com/php-regex/regex-generator) — generates sample strings and test cases a pattern matches or rejects.
- [`regex-transpiler`](https://github.com/php-regex/regex-transpiler) — transpiles PCRE patterns to JavaScript and Python, with the losses reported.

## 🛠️ Tooling

- [`regex-cli`](https://github.com/php-regex/regex-cli) — the `regex` command: validate, explain, analyze, lint and compare patterns from the terminal.
- [`regex-phpstan`](https://github.com/php-regex/regex-phpstan) — PHPStan extension that reports the regex patterns your target PHP refuses.
- [`regex-psalm`](https://github.com/php-regex/regex-psalm) — Psalm plugin that types the matches of `preg_match()` and `preg_match_all()`, and reports invalid patterns.
- [`regex-rector`](https://github.com/php-regex/regex-rector) — Rector rules that rewrite `preg_*` calls into the string functions the automata prove they match.
- [`regex-language-server`](https://github.com/php-regex/regex-language-server) — a Language Server for the regex patterns of PHP files: diagnostics, hovers and code actions.

## 🧩 Integrations

- [`regex-symfony`](https://github.com/php-regex/regex-symfony) — Symfony bundle: the Regex service and the regex:* commands.
- [`regex-laravel`](https://github.com/php-regex/regex-laravel) — Laravel integration: the Regex facade and the regex:* artisan commands.
