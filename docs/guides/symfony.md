---
description: "Register the PHPRegex bundle in Symfony 6.4, 7.4 or 8.x: the php_regex.regex service, regex:* console commands, route and firewall analysis, the 1.x upgrade."
---

# Symfony Bundle Guide

The bundle registers a `Regex` service for your application and the
`bin/console regex:*` commands. Its configuration lives under the
`php_regex` key.

Requires PHP 8.1 or later and Symfony 6.4 LTS, 7.4 or 8.x.
The bundle ships no Symfony Flex recipe: register it by hand in
`config/bundles.php`.

{% include install-prerelease.html package="php-regex/regex-symfony" %}

```php
// config/bundles.php
return [
    // ...
    PHPRegex\Symfony\PHPRegexBundle::class => ['all' => true],
];
```

Register it for every environment as soon as your code injects the `Regex`
service: the service is then a runtime dependency, and prod needs it too. If
you only want the `regex:*` commands, a `['dev' => true, 'test' => true]`
registration works, with two consequences: the configuration below moves
under `when@dev:` (and `when@test:`), since prod has no extension to load a
`php_regex` key, and no `Regex` service exists in prod.

```yaml
# config/packages/php_regex.yaml, with a dev-only registration
when@dev:
    php_regex:
        paths: [src]
```

## Configuration

Every key is optional; the values below are the defaults.

```yaml
# config/packages/php_regex.yaml
php_regex:
    max_pattern_length: 100000
    max_lookbehind_length: 255
    # Whether the php_regex.regex service also compiles every pattern
    # with the running PHP. regex:lint never does.
    runtime_pcre_validation: false
    # The PHP version, PCRE2 release and LC_CTYPE locale regex:lint
    # judges patterns for.
    php_version: null      # "8.2", "8.2.4" or 80200
    pcre_version: null     # "10.42"
    locale: null           # "tr_TR.UTF-8"; the locale of the PHP running the command
    cache:
        pool: null         # a PSR-6 pool service id, used before "directory"
        directory: '%kernel.cache_dir%/php_regex'
        prefix: regex_
    extractor_service: null
    # The calls regex:lint reads patterns from, as "extraction" of regex.json.
    extraction:
        interop: [composer-pcre, nette-utils, spatie-regex, illuminate-support, phpunit, webmozart-assert, beberlei-assert, symfony-validator]
        functions: []      # your helpers: 'App\Support\Str::matches#1'
    redos:
        enabled: false
        threshold: high    # low, medium, high or critical, in any case
        ignored_patterns: []
    analysis:
        complexity: false  # regex:lint reports complex patterns
        warning_threshold: 50
    automata:
        minimization_algorithm: hopcroft         # hopcroft or moore
        determinization_algorithm: subset-indexed # subset or subset-indexed
    optimizations:
        digits: true
        word: true
        ranges: true
        canonicalize_char_classes: true
        possessive: false
        factorize: false
        min_quantifier_count: 4
    paths: [src]       # read from %kernel.project_dir%
    exclude: [vendor]  # a directory of the project, wherever it lies under the paths
    report_unmatched_ignores: true  # report the @regex-ignore markers that silence nothing
    ide: '%env(default::SYMFONY_IDE)%'
```

`paths` and `exclude` are read from `%kernel.project_dir%`: `regex:lint`
lints the same files whatever directory it runs from. The paths given to
`regex:lint` on the command line are read from the working directory, as
`lint:yaml` reads them. A path that does not exist stops `regex:lint` with
exit code 2, as it stops `regex lint`: `Path not found: src/Legacy`, stage
`usage` in the JSON envelope for a path given on the command line, `config`
for one of `php_regex.paths`, which the message names.

A value the bundle cannot read stops the container compile with the key
named: an unknown ReDoS threshold, a `php_version` that is no version, a
`pcre_version` that is no release. `safe` and `unknown` are not thresholds:
they are the verdicts a pattern gets.

`redos.ignored_patterns` lists patterns, fragments of patterns or whole
regexes the risk analysis skips, such as the requirement constants of
Symfony routes. A fragment, `(a+)+`, is matched against the body of each
pattern, with or without a `^` before it and a `$` after it, whatever its
delimiters and modifiers: it skips `/(a+)+$/`, `/(a+)+$/i`, `{(a+)+$}` and
`#(a+)+$#u` alike.

`extraction` takes the presets and the helpers `regex lint` takes under
`extraction` in `regex.json` (see
[the CLI guide](cli.md#patterns-behind-a-wrapper)); the bundle does not read
`regex.json`, and a custom `extractor_service` reads its own calls, leaving
`extraction` unused. The default is every preset, and `interop: ~` says the
same, as `null` does in the Laravel config and the PHPStan extension; a list
reads exactly the presets it names, `[]` the native calls only. Where several files configure
the bundle (`config/packages/php_regex.yaml`, then
`config/packages/dev/php_regex.yaml`), a later file's `interop` or
`functions` list replaces the earlier one, while `paths` and `exclude` add up.
A name that is no preset is a configuration error, raised as the container is
built, which names the preset it most likely means:

```
Invalid configuration for path "php_regex.extraction.interop": Unknown extraction preset "laravel-str": did you mean "illuminate-support"?
```

An entry of either list that is no string, a `false` say, is ignored, and
`regex:lint` says so under its banner, or on stderr with `--format=json` and
the other machine formats, the warning naming its type:

```
Warning: Extraction interop entry ignored: expected a string, got bool.
```

A `functions` spec the lint cannot read, `x#a` or `x#1$` say, is ignored
with `Malformed pattern function "x#a", ignored.`

One name written without a list, `interop: nette-utils`, is a list of that
name.

`regex:lint` reads the `Regex` constraints of the validator mapping files
FrameworkBundle hands the validator: `config/validator/*.yaml` and `*.xml`,
the files and directories of `framework.validation.mapping.paths`, and the
`validation.yaml` or `validation.xml` of each bundle, those under an
`exclude` directory (`vendor/`) left out. Each file is read with the
validator's own loader, nested constraints (`All`, `Collection`,
`Sequentially`) included, and each pattern is reported at the file and the
line that write it, its source naming the class and the member,
`validator:App\Entity\User::$name`:

```yaml
# config/validator/user.yaml
App\Entity\User:
    properties:
        name:
            - Regex: { pattern: '/[a-z/' }   # reported at config/validator/user.yaml:4
```

A pattern written over several lines, which no line spells whole, is
reported on the line of its class. A mapping file the validator cannot read,
or one that maps a property its class lacks, is reported as
`regex.lint.source.unreadable` with the validator's message. A constraint
written as an attribute, `#[Assert\Regex]`, is read from the PHP file (the
`symfony-validator` preset), once. `--no-validators` leaves the mapping files
out.

## Using the service

The container registers `php_regex.regex`, autowirable as
`PHPRegex\Toolkit\Regex` — inject it like any other service:

```php
use PHPRegex\Toolkit\Regex;

final class PatternSupport
{
    public function __construct(private readonly Regex $regex) {}

    public function check(string $pattern): bool
    {
        return $this->regex->validate($pattern)->isValid;
    }
}
```

`validate()` returns the object [the Laravel facade returns](laravel.md#using-the-service): `$result->isValid`, `$result->error`,
`$result->caretSnippet`. [The API reference](../reference/api.md) lists every
method of the service — `parse`, `explain`, `redos`, `optimize`, `transpile`
and the rest.

## Validating a pattern a user submits

The `ValidRegex` constraint accepts a value that PCRE compiles, written as
`preg_match()` takes it: delimiters and flags included. It needs
`symfony/validator`. The verdict is the one `regex validate` gives, judged by
the bundle's `Regex` service with its limits, so a pattern longer than
`max_pattern_length` is refused too:

```php
use PHPRegex\Symfony\Validator\Constraints\ValidRegex;

final class SearchFilter
{
    #[ValidRegex]
    public string $pattern = '';

    #[ValidRegex(redos: true)]
    public string $routePattern = '';
}
```

- `message` (default `This value is not a valid regular expression: {{ error }}`)
  takes `{{ value }}`, `{{ error }}` (the library's message, `Unmatched closing
  parenthesis at position 4.` for `/abc)/`) and `{{ hint }}` (how to fix it,
  or an empty string). The code is `ValidRegex::INVALID_ERROR`.
- `redos: true` also refuses a pattern whose [ReDoS](redos.md) severity reaches
  `redosThreshold`: `low`, `medium`, `high` (the default) or `critical`, as a
  string or a `RedosSeverity`. `/x*x*x*$/` (polynomial, `high`) is refused,
  `/a*a*$/` (`medium`) passes. `redosMessage` takes `{{ value }}` and
  `{{ severity }}`; the code is `ValidRegex::REDOS_ERROR`. An invalid pattern
  is never analysed for ReDoS.
- Null and the empty string are valid, as for every Symfony constraint: add
  `NotBlank` to refuse them.

## How route requirements and security patterns are read

Each one is read as the pattern Symfony runs:

- A route requirement is a fragment of the compiled route. Its leading `^`
  or `\A` and its trailing `$` or `\z` are stripped once, as
  `Route::sanitizeRequirement()` does (`^^a` keeps one `^`), its groups
  are made non-capturing, and it is matched in a group named after the
  parameter with `{^...$}sD`, plus `i` for a host parameter and `u` when
  the route sets its `utf8` option: `\d+` on `{id}` is linted as
  `{^(?P<id>\d+)$}sD`. A requirement that starts with `/` or `#` is no
  delimited regex there either. A requirement that compiles alone but
  breaks the route is invalid: `[{]` leaves the router's braces
  unbalanced, `a\$` loses its `$` and escapes the group's `)`. What the
  router adds is not the requirement's to change: a useless `s` or `D`, a
  rewrite that only drops a flag, and a repeated unnamed group (the router
  makes it non-capturing) are not reported.
- A security `path` (in `access_control` or as a firewall `pattern`) is
  matched with `{...}s`, and a `host` with `{...}i`, without anchors:
  `path: /api` matches `/v1/api` too.

## The service and the lint judge for different targets

The `php_regex.regex` service (autowired as `PHPRegex\Toolkit\Regex`) runs in
your application: it judges patterns for the PHP running it, under the
locale it runs under, and `php_version` / `pcre_version` / `locale` do not
change that: a request may run under another `LC_CTYPE` than the console.

`regex:lint` judges the patterns of your code for the PHP your project
supports. It picks the target in this order:

1. `php_regex.php_version` and `php_regex.pcre_version`;
2. `composer.json` in `%kernel.project_dir%`: `config.platform.php` if set,
   else the lowest version `require.php` allows, and then every pattern is also validated on
   the later PHP versions the constraint allows, as
   [the standalone command does](cli.md#several-php-versions);
3. the PHP running the command.

Each version is chosen on its own: without `pcre_version`, the lint uses the
PCRE2 release the target PHP bundles (10.40 for PHP 8.2, 10.42 for 8.3, 10.44
for 8.4 and 8.5). The locale is `php_regex.locale`, else the one of the PHP
running the command (see
[Which locale judges a pattern](../concepts/pcre.md#which-locale-judges-a-pattern)):
its character tables decide the size of a few classes, and one the machine
cannot set is a configuration error. A `regex.json` in the project is the
configuration of the standalone `vendor/bin/regex` command, not of the
bundle.

The console report shows the target in its header. The JSON report carries it
as a top-level `target` object:

```json
{
    "target": {"php": "8.2", "pcre": "10.40", "locale": "C", "source": "php_regex.php_version", "range": [{"php": "8.2", "pcre": "10.40"}]},
    "stats": {"errors": 0, "warnings": 0, "optimizations": 0, "redos_errors": 0, "infos": 0, "lint_errors": 0, "parser_fallbacks": 0, "not_analyzed": 0},
    "results": []
}
```

`source` names where each version came from: `php_regex.php_version`,
`composer.json require.php`, `composer.json config.platform.php` or
`running PHP`, followed by `php_regex.pcre_version` when the PCRE2 release
came from there and `php_regex.locale` when the locale did.

The report is the one `vendor/bin/regex lint --format=json` prints, and
`regex:transpile --format=json` prints the same document as
`vendor/bin/regex transpile`; the [JSON output reference](../reference/json-output.md)
lists every key.

`runtime_pcre_validation` compiles with the PHP that runs, which cannot tell
whether an older PHP accepts a pattern: the lint never uses it, whatever the
bundle says.

## Commands

| Command          | Description                                          |
|------------------|------------------------------------------------------|
| `regex:lint`     | Lint the regex patterns of your PHP code             |
| `regex:compare`  | Compare two patterns with automata                   |
| `regex:explain`  | Explain a pattern in plain English                   |
| `regex:routes`   | Detect route conflicts and overlaps                  |
| `regex:security` | Analyze access control ordering and firewall regexes |
| `regex:analyze`  | Run the routes and security analyzers                |
| `regex:transpile`| Translate a pattern for another regex engine         |

```bash
bin/console regex:lint
bin/console regex:lint src/ --format=json
bin/console regex:analyze --redos-threshold=medium
```

`--redos-threshold` takes the same values as `redos.threshold`, in any case.

`regex:lint` writes its banner and its report on stdout. Its steps, their
progress bars and an error that stops the run (`Error: Path not found:
src/Legacy`) go to stderr, as `regex lint` writes them, so
`bin/console regex:lint > lint.txt` keeps the report alone. The Symfony bundle and
the Laravel package run the same lint, and print it the same way. Under
`-v` the title names the author and the report ends on a request for a
GitHub star, as in `regex:routes`, `regex:security` and `regex:analyze`.

Every command is listed by `bin/console list`. They take the options of the
same command in the CLI and in the Laravel package, under the same names and
short forms (`-j` for `--jobs`, `-t` for `--target`): `regex:compare
--method` takes `intersection` (the default), `subset` or `equivalence`, and
`regex:explain` takes `--format=console|html` and `--highlight`, and prints
the optimization, the ReDoS risk, the lint issues and a sample beside the
explanation. What stays the bundle's own: `regex:routes`, `regex:security`
and `regex:analyze` read Symfony's router and security configuration, where
Laravel's `regex:routes` lists and validates route constraints.

`regex:routes` reads the routes in the order they are declared, as the router
matches them: the first route that matches a path wins it. A route is
**shadowed** when an earlier route takes every path it matches, so it can
never be reached. Two routes that share some paths but not all are an
**overlap**, listed with `--show-overlaps`: `/blog/{slug}` (`[a-z]+`) declared
before `/blog/{id}` (`\d+|new`) takes `/blog/new`. A narrower route declared
before a wider one, `/users/list` before `/users/{id}`, is the order the
router expects and is not reported.

`regex:security` and `regex:analyze` read the security configuration the
application runs with, as `bin/console debug:config security` shows it:
`config/packages/security.yaml`, `security.php` or any other file the kernel
loads, in any YAML shape (block or flow lists, anchors and aliases,
`when@<env>` blocks), with its `%parameters%` and `%env()%` values resolved.
Each finding names the file and the line its value is written on, or
`security configuration` when no `security.*` file under `config/` writes it.
`--config=<file>` analyzes that YAML file instead, read with `symfony/yaml`
(`composer require symfony/yaml`), its `%parameters%` taken from its own
`parameters` and then from the application's.

A security configuration either command cannot read stops it with exit code 2:
a `--config` file that is missing, is not YAML or does not parse, a parameter
nothing defines, a configuration the SecurityBundle refuses, `symfony/yaml`
not installed. `regex:analyze --format=json` prints the
[error envelope](../reference/json-output.md#the-error-envelope), stage
`config`. An application without the SecurityBundle has no security
configuration: `regex:security` stops with exit code 2 and says so, and
`regex:analyze` prints it as a warning.

`regex:lint` reads the functions marked `#[RegexPattern]` (or PhpStorm's
`#[Language('RegExp')]`) in the configured `paths`, with `exclude` where they are linted, and
in `%kernel.project_dir%/vendor`, whatever `exclude` says; a project
declaration wins over a copy in `vendor/`. A call to one is linted as a
`preg_*()` call (see [the CLI guide](cli.md#patterns-behind-a-wrapper)); every
rule it can report is catalogued in [Lint rules](../reference/rules.md).

## ReDoS findings

With `redos.enabled: true`, `regex:lint` adds the ReDoS issue,
`regex.lint.redos`, to the lint findings of each pattern at or above
`redos.threshold`, as `vendor/bin/regex lint --redos` does (see
[the CLI guide](cli.md#7-lint-your-codebase)): the verdict's headline, such as
`Exponential backtracking (proven)`, then the severity, the confidence and the
attack.

`regex:security` and `regex:analyze` run the same analysis on the `pattern` of
each firewall. A finding names the verdict and, when it was proven, the attack:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        api:
            pattern: ^/api/(\w+/?)+$
            stateless: true
```

```bash
bin/console regex:security
```

```
 Security Firewall Regex

Firewalls : 1
ReDoS >=  : high
Flagged   : 1

   FAIL  1 firewall regex patterns exceed the ReDoS threshold.

   CRIT  api (config/packages/security.yaml:4)
      ↳ Severity: CRITICAL
      ↳ Verdict: Exponential backtracking (proven)
      ↳ Score: 10
      ↳ Pattern: ^/api/(\w+/?)+$
      ↳ Attack: "/api/" . "0" x n . "!"
```

`"/api/" . "0" x n . "!"` reads as PHP: `"/api/" . str_repeat("0", $n) .
"!"`, a request path that makes `preg_match()` give up from 19 repetitions
under PHP's default limits. `regex:routes` and `regex:security` print the
sections of `regex:analyze`, the same analysis printed the same way, and
the JSON report of `regex:analyze` carries the rows as details:

```json
{
    "kind": "redos",
    "severity": "critical",
    "title": "api (config/packages/security.yaml:4)",
    "details": [
        {"label": "Severity", "value": "CRITICAL", "kind": "text"},
        {"label": "Verdict", "value": "Exponential backtracking (proven)", "kind": "text"},
        {"label": "Score", "value": "10", "kind": "text"},
        {"label": "Pattern", "value": "^/api/(\\w+/?)+$", "kind": "pattern"},
        {"label": "Attack", "value": "\"/api/\" . \"0\" x n . \"!\"", "kind": "text"}
    ],
    "notes": []
}
```

The [ReDoS guide](redos.md) explains the verdicts and the attack, and the
[JSON output reference](../reference/json-output.md#symfony-analyze) lists
every key of the `regex:analyze` report.

Each command exits with 0 when it found nothing wrong, 1 when the patterns or
the files it judged have a problem, and 2 when an option or the configuration
cannot be used (see [the CLI guide](cli.md#exit-codes)).

## Upgrading from 1.x

See [UPGRADE-2.0.md](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md): `exclude_paths` is now `exclude`,
`analysis.ignore_patterns` is merged into `redos.ignored_patterns`, and
`analysis.redos_threshold` is gone. A 1.x key stops the container compile with
Symfony's own unrecognized-option message, naming the key and the valid
options.

`redos.enabled: true` now makes `regex:lint` report ReDoS findings: 1.x read
the setting and never ran the analysis. Expect new warnings on the first run.
