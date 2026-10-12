# PHPRegex demo applications

Everything needed to see the library working, in one place: one topic per
folder, one complete example per file. Every script runs on its own, from the
repository root:

```bash
php demo/validate/validate.php
```

## The library, script by script

- **[parser/](parser/)** — the AST and the tokens
  - `php demo/parser/parse.php` — parse a pattern, read the root node, the flags, the delimiter, the tokens
  - `php demo/parser/capture-shape.php` — the `$matches` layout `preg_match()` and `preg_match_all()` will produce
  - `php demo/parser/version-targets.php` — judge the same pattern for a pinned PHP and PCRE2 target
- **[validate/](validate/)** — is the pattern correct?
  - `php demo/validate/validate.php` — validation with the error, the hint and the complexity score
  - `php demo/validate/complexity.php` — the complexity score and the full analysis report in one call
- **[explain/](explain/)** — patterns in plain English
  - `php demo/explain/explain.php` — a URL pattern explained
  - `php demo/explain/formats.php` — text, HTML and terminal highlighting
- **[redos/](redos/)** — catastrophic backtracking
  - `php demo/redos/analysis.php` — theoretical versus confirmed analysis, three safe rewrites
  - `php demo/redos/confirmed.php` — confirming the attack: witness samples and timings
- **[automata/](automata/)** — patterns as machines
  - `php demo/automata/build-dfa.php` — compile a pattern to a DFA and query prefixes
  - `php demo/automata/compare-patterns.php` — equivalence and sublanguage proofs, with witnesses
- **[diagrams/](diagrams/)** — a pattern, drawn
  - `php demo/diagrams/railroad.php` — the pattern as an SVG railroad diagram
- **[visitors/](visitors/)** — the visitor pattern on the AST
  - `php demo/visitors/custom-visitor.php` — count nodes with a custom traversing visitor
- **[generate/](generate/)** — strings that match
  - `php demo/generate/generate.php` — samples proven against the real engine
- **[optimize/](optimize/)** — better patterns
  - `php demo/optimize/optimize.php` — the rewrites the optimizer applies
- **[transpile/](transpile/)** — other dialects
  - `php demo/transpile/transpile.php` — the pattern rewritten for JavaScript and Python
- **[real-world/](real-world/)** — complete use cases
  - `php demo/real-world/email.php` — email validation with error handling
  - `php demo/real-world/route-requirements.php` — checking the compiled requirements of application routes

## Complete applications

- **[symfony/](symfony/)** — a complete Symfony application (created from
  `symfony/skeleton`) with the PHPRegex bundle enabled: a demo endpoint, a
  route whose requirement is itself a regex, and a test suite that boots the
  real kernel and registers the `regex:*` console commands.

- **[laravel/](laravel/)** — a complete Laravel application with the package
  auto-discovered by Composer: demo routes behind the facade, artisan
  commands, and a feature test suite.

Both applications consume this repository through a Composer path repository
(the `repositories` entry of their `composer.json` points at the repository
root), so what you run is always the code from this checkout — never a
released version.

The Symfony application tracks the latest stable release resolved when it was
created; the Laravel application is pinned to the major release the Laravel
bridge supports.

## Running the applications

```bash
# Symfony (PHP 8.4+)
composer install -d demo/symfony
php demo/symfony/bin/console list --raw | grep 'regex:'   # the six bridge commands
(cd demo/symfony && bin/phpunit)

# Laravel (PHP 8.2+)
composer install -d demo/laravel
cp -n demo/laravel/.env.example demo/laravel/.env
php demo/laravel/artisan key:generate
(cd demo/laravel && vendor/bin/phpunit)
```

The scripts are executed by the documentation suite, so they cannot rot; both
suites also run in CI on every change that touches the bridges or the
applications themselves, and once a week to catch drift in the frameworks.
