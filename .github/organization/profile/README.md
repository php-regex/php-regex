<p align="center">
    <img src="org-icon.svg?v=1" alt="PHP Regex" width="140">
</p>

<h1 align="center">PHP Regex</h1>

<h3 align="center">Static analysis, linter & logic solver for PHP regular expressions</h3>

Every package is a read-only split of the [php-regex monorepo](https://github.com/php-regex/php-regex) — please open issues and pull requests there.

## The entry point

| Package | Description |
| --- | --- |
| [regex-toolkit](https://github.com/php-regex/regex-toolkit) | One entry point to every PHPRegex library: parse, validate, explain, check ReDoS, optimize, generate, transpile and lint. |

## Libraries

| Package | Description |
| --- | --- |
| [regex-parser](https://github.com/php-regex/regex-parser) | Lexer, immutable AST, printers and validators for PCRE-style patterns. |
| [regex-linter](https://github.com/php-regex/regex-linter) | Lints the regex patterns of a PHP code base — reports in console, JSON, GitHub, Checkstyle and JUnit. |
| [regex-redos](https://github.com/php-regex/regex-redos) | Finds the patterns that backtrack catastrophically (ReDoS), and can confirm a finding against the engine. |
| [regex-automata](https://github.com/php-regex/regex-automata) | Compiles the regular subset of PCRE to automata to compare languages: equivalence, intersection, subset. |
| [regex-optimizer](https://github.com/php-regex/regex-optimizer) | Rewrites regex patterns into shorter equivalents, checked by language equivalence. |
| [regex-explain](https://github.com/php-regex/regex-explain) | Explains, highlights and draws regex ASTs — text and HTML, ASCII trees, Mermaid and railroad diagrams. |
| [regex-generator](https://github.com/php-regex/regex-generator) | Generates sample strings and test cases that a regex matches or rejects. |
| [regex-transpiler](https://github.com/php-regex/regex-transpiler) | Transpiles PCRE patterns to JavaScript and Python, with the losses reported. |

## Tools

| Package | Description |
| --- | --- |
| [regex-cli](https://github.com/php-regex/regex-cli) | The `regex` command: validate, explain, analyze, lint and compare patterns from the terminal. |
| [regex-phpstan](https://github.com/php-regex/regex-phpstan) | PHPStan extension that reports the regex patterns your target PHP refuses. |
| [regex-language-server](https://github.com/php-regex/regex-language-server) | A Language Server for the regex patterns of PHP files — diagnostics, hovers and code actions. |
| [regex-psalm](https://github.com/php-regex/regex-psalm) | Psalm plugin that types the matches of `preg_match()` and `preg_match_all()` from the pattern, and reports invalid patterns. |
| [regex-rector](https://github.com/php-regex/regex-rector) | Rector rules that rewrite `preg_*` calls into the string functions the automata prove they match. |

## Integrations

| Package | Description |
| --- | --- |
| [regex-symfony](https://github.com/php-regex/regex-symfony) | Symfony bundle: the Regex service and the regex:lint, regex:routes and regex:explain commands. |
| [regex-laravel](https://github.com/php-regex/regex-laravel) | Laravel integration: the Regex facade and the regex:* artisan commands.
