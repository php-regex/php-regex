---
description: "Register the PHPRegex bundle in Symfony 6.4, 7.4 or 8.x: the php_regex.regex service, regex:* console commands, route and firewall analysis, the 1.x upgrade."
---

# Symfony Bundle Guide

The bundle registers a `Regex` service for your application and the
`bin/console regex:*` commands. Its configuration lives under the
`php_regex` key.

Requires PHP 8.1 or later and Symfony 6.4 LTS, 7.4 or 8.x.
{% include install.html package="php-regex/regex-symfony" %}

With Symfony Flex, `composer require` registers the bundle in
`config/bundles.php` for every environment (for `dev` and `test` only when it
is installed with `--dev`). Without Flex, add it by hand:

```php
// config/bundles.php
return [
    // ...
    PHPRegex\Symfony\PHPRegexSymfonyBundle::class => ['all' => true],
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

## First Run

The configuration file is optional: every key of
`config/packages/php_regex.yaml` has a default, so you can lint your code as
soon as the bundle is registered:

```bash
bin/console regex:lint src
```

The report is the one `vendor/bin/regex lint` prints: a header naming the PHP
version the patterns are judged for, each pattern with a problem under its
file and line, and a `PASS` or `FAIL` summary ([the CLI guide](cli.md#lint-a-codebase)
shows a sample). The command exits with 0 when it found nothing wrong;
[Commands](#commands) lists every command and its options.

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
    php_version: null      # "8.2", "8.2.4", 80200, or a constraint like ">=8.1"
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
        verify_with_automata: true  # suggest only the rewrites the automata prove
    paths: [src]       # read from %kernel.project_dir%
    exclude: [vendor]  # a directory of the project, wherever it lies under the paths
    report_unmatched_ignores: true  # report the @regex-ignore markers that silence nothing
    ide: null          # phpstorm, vscode or a URL template; null for framework.ide
```

`ide` names the editor the clickable links of the console report open:
`phpstorm`, `vscode`, `sublime`, `textmate`, `emacs`, `atom`, `macvim`, or a
URL template with `%f` (or `%%file%%`) and `%l` (or `%%line%%`). Left `null`,
the links open the editor FrameworkBundle links its own pages to:
`framework.ide`, which defaults to the `SYMFONY_IDE` environment variable
in a debug kernel.

`paths` and `exclude` are read from `%kernel.project_dir%`: `regex:lint`
lints the same files whatever directory it runs from. An `exclude` is a
directory name, left out wherever it lies under the project, never above
it, or the path of a file or a directory, from the project, behind `./` or
absolute, as `regex lint --exclude` reads it. The paths given to
`regex:lint` on the command line are read from the working directory, as
`lint:yaml` reads them. A path that does not exist stops `regex:lint` with
exit code 2, as it stops `regex lint`: `Path not found: src/Legacy`, stage
`usage` in the JSON envelope for a path given on the command line, `config`
for one of `php_regex.paths`, which the message names.

A value the bundle cannot read stops the container compile with the key
named: an unknown ReDoS threshold, a `php_version` that is no version and
no range `regex lint --php-version` takes, a `pcre_version` that is no
release. `safe` and `unknown` are not thresholds:
they are the verdicts a pattern gets.

`redos.ignored_patterns` lists patterns, fragments of patterns or whole
regexes the risk analysis skips, such as the requirement constants of
Symfony routes. A fragment, `(a+)+`, is matched against the body of each
pattern, with or without a `^` before it and a `$` after it, whatever its
delimiters and modifiers: it skips `/(a+)+$/`, `/(a+)+$/i`, `{(a+)+$}` and
`#(a+)+$#u` alike. A delimited pattern without modifiers, `/(a+)+$/`, is
read by its body the same way. One written with modifiers names that pattern
only, the same body under the same modifiers, in any order and between any
delimiters: `/(a+)+$/i` skips `#(a+)+$#i`, not `/(a+)+$/` or `/(a+)+$/iu`.

`extraction` takes the presets and the helpers `regex lint` takes under
`extraction` in `regex.json` (see
[the CLI guide](cli.md#patterns-behind-a-wrapper)); the bundle does not read
`regex.json`, and a custom `extractor_service` reads its own calls, leaving
`extraction` unused. The default is every preset, and `interop: ~` says the
same, as `null` does in the Laravel config and the PHPStan extension; a list
reads exactly the presets it names, and `[]` turns every preset off. Where several files configure
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

`extractor_service` names a service of yours implementing
`PHPRegex\Linter\Extraction\ExtractorInterface`. `regex:lint` then reads
patterns with it alone: the built-in extractors do not run, so the native
`preg_*` calls are read only if yours reads them, and `extraction` is unused.
`extract()` is handed the PHP files under the paths and returns a list of
`PatternOccurrence`, each built with `PatternOccurrence::found()`: the pattern
with its delimiters and modifiers, the file, the line, the column of the
literal that holds it (its opening quote, counted in bytes from 1) when your
extractor knows it, and the call that holds it, as the report names it:

```php
namespace App\Regex;

use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\PatternOccurrence;

final class RuleExtractor implements ExtractorInterface
{
    public function extract(array $files): array
    {
        $occurrences = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $index => $line) {
                if (1 === preg_match("~Rule::matches\\('([^']*)'~", $line, $match, \PREG_OFFSET_CAPTURE)) {
                    $occurrences[] = PatternOccurrence::found($match[1][0], $file, $index + 1, $match[1][1], 'Rule::matches()');
                }
            }
        }

        return $occurrences;
    }
}
```

```yaml
# config/packages/php_regex.yaml
php_regex:
    extractor_service: App\Regex\RuleExtractor
```

An occurrence your extractor builds carries no inline marker of its own:
`regex:lint` reads the markers of the file it names, so a `@regex-ignore` on
its line silences it as it silences a pattern the built-in extractors find,
and a marker that silences nothing is reported the same way.

`regex:lint` reads the `Regex` constraints of the validator mapping files
FrameworkBundle hands the validator: `config/validator/*.yaml` and `*.xml`,
the files and directories of `framework.validation.mapping.paths`, and the
`validation.yaml` or `validation.xml` of each bundle, but those the
excludes leave out as they leave out a PHP file (`vendor/`). Each file is read with the
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

The `matches` operator of the Twig templates under the linted paths is read
too, as `preg_match()` of its literal: {% raw %}`{% if slug matches '/^[a-z]+$/' %}`{% endraw %}
is reported at the template, with `twig:matches` as its source (see the
[CLI guide](cli.md#twig-templates) for what Twig makes of the backslashes).
`--no-twig` leaves the templates out.

## Using the service

The container registers `php_regex.regex`, a private service autowirable as
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

Every service of the bundle is private and named `php_regex.*`. Besides
`PHPRegex\Toolkit\Regex`, the application autowires only the validator of the
`ValidRegex` constraint, which the validator finds by its class name; the
other classes of the bundle are internal. The commands are lazy, built when
they run.

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

- `message` (default {% raw %}`This value is not a valid regular expression: {{ error }}`{% endraw %})
  takes {% raw %}`{{ value }}`{% endraw %}, {% raw %}`{{ error }}`{% endraw %} (the library's message, `Unmatched closing
  parenthesis at position 4.` for `/abc)/`) and {% raw %}`{{ hint }}`{% endraw %} (how to fix it,
  or an empty string). The code is `ValidRegex::INVALID_ERROR`.
- `redos: true` also refuses a pattern whose [ReDoS](redos.md) severity reaches
  `redosThreshold`: `low`, `medium`, `high` (the default) or `critical`, as a
  string or a `RedosSeverity`. `/x*x*x*$/` (polynomial, `high`) is refused,
  `/a*a*$/` (`medium`) passes. `redosMessage` takes {% raw %}`{{ value }}`{% endraw %} and
  {% raw %}`{{ severity }}`{% endraw %}; the code is `ValidRegex::REDOS_ERROR`. An invalid pattern
  is never analysed for ReDoS.
- Null and the empty string are valid, as for every Symfony constraint: add
  `NotBlank` to refuse them.
- `message` and `redosMessage` are translation keys of the `validators`
  domain, as the messages of Symfony's own constraints are: the bundle ships
  their English catalogue (`Resources/translations/validators.en.xlf`), and
  your application translates them in its own `translations/validators.<locale>.*`
  files. {% raw %}`{{ error }}`{% endraw %} and {% raw %}`{{ hint }}`{% endraw %} are the library's English messages,
  not translated.

{% raw %}
```yaml
# translations/validators.fr.yaml
'This value is not a valid regular expression: {{ error }}': 'Cette valeur n''est pas une expression régulière valide : {{ error }}'
```
{% endraw %}

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
  matched with `{...}s`, and a `host` with `{...}i`, as written and without
  anchors: `path: /api` matches `/v1/api` too, and a space or a line feed
  at either end is part of the pattern, so `' ^/admin'` matches no path.
  `symfony/http-foundation` adds the `s` to a path since 6.4.46, 7.4.19
  and 8.1.7, and never on 8.0: a path is read with the `s` where the
  application's release adds it, and without it elsewhere, so that `.`
  takes the line feed a request path decodes `%0A` to only where Symfony
  lets it. An `access_control` path or host of `""` or `"0"` is none, as
  the security bundle reads it. The options a path opens with,
  `(*UTF)` or `(*LIMIT_MATCH=1000)`, are read there, as PCRE reads them,
  and a path ending on a comment of the `x` flag or on a `\Q` left open
  means what it means alone: `(?x)^/admin  # back office` takes every path
  under `/admin`. The rule of an `access_control` path PCRE refuses,
  `^/(admin`, is left out of the comparison and reported as skipped.
  A path under `(*UTF)` and one without it are compared on the request
  paths that are valid UTF-8, a character at a time for the one and a byte
  at a time for the other: `(*UTF)^/admin/users` before `^/admin` is a
  carve-out. A request path Symfony decodes into invalid UTF-8, `/%E9t%E9`,
  is not compared: PHP does not check it against a `(*UTF)` path, and PCRE2
  reads it as if it were valid, with no defined answer. Routes are compared
  the same way when only one sets the `utf8` option; such a route matches no
  path that is not valid UTF-8.

## The service and the lint judge for different targets

The `php_regex.regex` service (autowired as `PHPRegex\Toolkit\Regex`) runs in
your application: it judges patterns for the PHP running it, under the
locale it runs under, and `php_version` / `pcre_version` / `locale` do not
change that: a request may run under another `LC_CTYPE` than the console.

`regex:lint` judges the patterns of your code for the PHP your project
supports. It picks the target in this order:

1. `php_regex.php_version` and `php_regex.pcre_version`. A single version
   (`"8.2"`, `"8.2.4"`, `80200`) is judged alone. A Composer constraint
   (`">=8.1"`, `"^8.1 <8.7"`) is judged as a `require.php` range: from its
   lowest PHP, then at every later PHP it allows, and the report names
   `php_regex.php_version` as its source. A constraint that names no lowest
   version (`"<8.4"`, `"*"`), or a PHP_VERSION_ID inside a constraint
   (`">=80100"`), stops the container compile, see
   [Naming a range](cli.md#naming-a-range). A single version hides the wider
   range `composer.json` allows: write `">=8.1"`, or leave the key `null` to
   read `composer.json`;
2. `composer.json` in `%kernel.project_dir%`: `config.platform.php` if set,
   else the lowest version `require.php` allows, and then every pattern is also validated on
   the later PHP versions the constraint allows, as
   [the standalone command does](cli.md#several-php-versions);
3. the PHP running the command.

Each version is chosen on its own, and every version judged (the target,
each later version of the range, each version a guard or a marker keeps) gets
its PCRE2 in this order, the [order of every entry point](cli.md#target-php-and-pcre2):

1. `php_regex.pcre_version`, else the release `composer.json` in `%kernel.project_dir%` pins in `config.platform.lib-pcre`
   (`"10.42"`, or `"10.42.0"` as Composer spells it). A pin is used as is,
   older or newer than the one that PHP bundles;
2. the PCRE2 of the PHP running the command, when the version judged is its minor version:
   on a PHP 8.4.27 that links 10.49, PHP 8.4 is judged with 10.49;
3. the PCRE2 that PHP bundles: 10.32 for PHP 7.3, 10.33 for 7.4, 10.35 for
   8.0, 10.39 for 8.1, 10.40 for 8.2, 10.42 for 8.3, 10.44 for 8.4 and 8.5.

A `config.platform.lib-pcre` that names no PCRE2 release is passed over with
a notice.

The locale is `php_regex.locale`, else the one of the PHP
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
    "stats": {"errors": 0, "warnings": 0, "optimizations": 0, "redos_errors": 0, "infos": 0, "lint_errors": 0, "parser_fallbacks": 0, "not_analyzed": 0, "arguments_constant": 0, "arguments_finite_set": 0, "arguments_quoted_shell": 0, "arguments_raw_shell": 0, "arguments_opaque": 0},
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

Under its summary line, the report says how many pattern arguments of the
PHP calls are built at run time, which no rule can verify, as `regex lint`
says it:

```
  Not verifiable: 3 pattern arguments (1 dynamic, 1 around a dynamic part, 1 around preg_quote())
```

The line is left out when there is none (see
[Arguments built at run time](cli.md#arguments-built-at-run-time)). The
command does not list them one by one:
`vendor/bin/regex lint --report-dynamic` does.

Every command is listed by `bin/console list`, and takes these arguments
and options. `regex:lint` refuses a `--jobs` that is not a whole number of 1
or more, and a `--min-savings` that is not a whole number of 0 or more, with
exit code 2 and stage `usage` in the JSON envelope, as `regex lint` does.

| Command | Arguments | Options |
|---|---|---|
| `regex:lint` | `[paths]...` | `--exclude=...` (default: `exclude`), `--min-savings=1`, `-j`/`--jobs=N`, `--no-routes`, `--no-validators`, `--no-twig`, `--format=console\|json\|github\|checkstyle\|junit` |
| `regex:routes` | | `--show-overlaps` (conflicts are always checked) |
| `regex:security` | | `--show-overlaps`, `-c`/`--config=<file>...`, `--skip-firewalls`, `--redos-threshold=high` |
| `regex:analyze` | | `--only=routes\|security...`, `--show-overlaps`, `-c`/`--config=<file>...`, `--skip-firewalls`, `--redos-threshold=high`, `--format=console\|json`, `--debug`, `--fail-on=shadowed,redos,critical` (also `overlap`, `any`, `none`) |
| `regex:compare` | `<pattern1> <pattern2>` | `--method=intersection\|subset\|equivalence`, `--minimizer=hopcroft\|moore`, `--determinizer=subset-indexed\|subset` (defaults: `automata.*`) |
| `regex:explain` | `<pattern>` | `--format=console\|html`, `--highlight` |
| `regex:transpile` | `<pattern>` | `-t`/`--target=js` (`javascript`, `html`, `html-pattern`, `python`, `py`), `--python-version=<x.y>` (3.11 carries atomic groups), `--format=console\|json` |

`regex:compare --method=subset` calls the first pattern a strict subset of
the second only when the second matches more strings; when the subset holds
both ways, it says the patterns are equivalent.

`regex:lint`, `regex:explain` and `regex:transpile` take the options of the
Laravel package's command of the same name, under the same names and short
forms; `regex:compare` takes them but `--format`, which the Laravel command
adds for a JSON report. The standalone `vendor/bin/regex` takes more:
`regex lint` its baseline and check switches, `regex compare` a target
(`--php-version`, `--pcre-version`, `--locale`), while `regex transpile` has
no `-t` and `regex explain` no `--highlight`. `regex:explain` prints the
optimization, the ReDoS risk, the lint issues and a sample beside the
explanation. What stays the bundle's own: `regex:routes`, `regex:security`
and `regex:analyze` read Symfony's router and security configuration, where
Laravel's `regex:routes` lists and validates route constraints.

Their console reports print what the configuration holds, paths, roles,
patterns and the attacks drawn from them, in display form: a line break, a
tab and a carriage return are written `\n`, `\t` and `\r`, another control
character `\xHH` (`\x1B`), a character that moves or hides text such as
U+2028 `\x{2028}`, and a console tag stays text. A pattern is spelled in its
own mode: without `u` that character is its bytes (`/a\xE2\x80\xA8/`), under
it `/a\x{2028}/u`. A value cannot open a line of its own or style the
report; the JSON report keeps each value as found.

`regex:routes` reads the routes in the order they are declared, as the router
matches them: the first route that matches a request wins it. A route is
**shadowed** when an earlier route takes every request it takes, every path
it matches, its methods, its schemes and its host included, so it can never
be reached. Two routes that share some requests but not all are an
**overlap**, listed with `--show-overlaps`: `/blog/{slug}` (`[a-z]+`) declared
before `/blog/{id}` (`\d+|new`) takes `/blog/new`, and a route for `GET` and
`POST` declared before a route for `GET` and `PUT` on the same path leaves
it the `PUT` requests. A narrower route declared before a wider one, whose
requests are all among the later route's, is a **carve-out**, the order the
router expects, and is not reported: `/users/list` before `/users/{id}`, a
route for `GET` before a route for `GET` and `POST` on the same path, a
route for `https` before one for any scheme, a route for a host before one
for any host. Two hosts the analysis cannot compare,
a host it cannot read or one the solver gives up on (a conditional in a
host requirement, say), leave the paths to decide, and the finding notes
`Host requirements were not evaluated.` Two routes whose hosts part on
letter case alone, `A.COM` and `a.com`, are counted among the pairs
skipped: the router sorts the routes that name a host by the end of it,
letter case included, and may try the later one first. A route that names no method or no
scheme takes any; one given only empty ones, `setMethods([''])` or
`setSchemes([''])`, takes no request, as Symfony's matchers read it, and
conflicts with no other route. A route for `GET` takes the `HEAD` requests
too, as the router sends them: a later route for `HEAD` only on the same
path is shadowed, and a route for `HEAD` only declared before a route for
`GET` on the same path is a carve-out that serves both. The report suggests
moving a later route first, or merging two routes on the same paths, only
where neither would leave a route unreachable. The router redirects a `GET`
or `HEAD` request whose path differs from a route's by a trailing slash
alone to that route, and tries no later one: `/foo/` declared before
`/foo` takes `GET /foo`, so the later route is shadowed when it takes only
`GET` and `HEAD`, and an overlap otherwise; the report then suggests paths
that differ by more than the slash. A route carved out of a catch-all,
`/admin` declared before `/{path<.*>}`, takes `GET /admin/` the same way:
that is the router normalising the slash, not a conflict, and the pair is
not reported. The router checks the slash before the
scheme: a later route on another scheme loses those requests too, and
keeps on its own scheme the paths both routes match: `/{x<a|b>}.html` on
`https` before `/{y<a\.html|b\.html/>}` on `http` is an overlap, the later
route serving `http://…/a.html` and losing `/b.html/` to the redirect. A
later route serves the path a redirect leads to when it matches it: one
that matches every path of the earlier one, `/{path<.*>}` on `http` after
`/admin` on `https`, loses none. Where it serves the path the shortest
redirected one leads to but not every path of the earlier route,
`/files/{path<[a-z/]+>}` on `http` after `/files/{name}` on `https`, which
paths it loses is a question on three languages the solver does not
answer, and the pair is counted among the pairs skipped. Where the
earlier route ends on a variable a value of which may end on the slash or
be empty, `.+` or `[a-z]*`, the router matches a path ending on the slash
as is, on that route's schemes, or redirects it, as PCRE captured the
value: on another scheme such a pair is counted among the pairs skipped, a
later route it matches only with the slash, `/{y<a//>}` after
`/{x<a/?>}`, is shadowed only when the earlier route takes every scheme of
it, and a later route for other methods than `GET` and `HEAD` keeps those
on its paths ending on the slash, `/files/{name}/` after
`/files/{path<.+?>}` being an overlap. The default requirement `[^/]++`,
or `\d+`, redirects whatever that order, and the pair is read as any
other. A later
route whose paths the earlier one matches in part and redirects for the
rest, `/blog{slash}` (`/?`) after `/blog`, is shadowed the same way. A
route that takes no `GET` request redirects none. The router looks a route
with no variable up by its path, every trailing slash of the request
trimmed off, before it tries any route with a regex, unless an earlier
route it tries with a regex matches that path: `/admin` redirects
`GET /admin//` too, so `/{p<admin//>}` after it is shadowed, `/admin/`
takes `/admin//` as is, for any method on its own schemes, and `/` answers
`GET //` with a 404. A route tried with a regex takes its path with one
slash at most. A later route ending on a variable that may end on the
slash, with no slash after it, `/{y<a|a/|b>}`, reads a path of its own
ending on the slash for the slash, as PCRE captures the value: it
redirects `GET /a/` to `/a`, and passes `POST /a/` on to the routes after
it. A request it passes on is none it loses, so a pair whose shortest
shared path is one it passes on for the methods both routes take is
counted among the pairs skipped; a redirect of an earlier route leading to
a path it reads for its slash still loses it the request. Each such
finding notes what the router does with the
request: it redirects it to the earlier route, matches it as is where the
earlier route ends on a variable whose value PCRE captures with the slash,
or, for `//` after `/`, answers it with a 404.

`regex:security` reads `access_control` the same way: the first rule whose
path, host, methods, addresses, port, route and attributes take a request
applies to it. A later rule is **shadowed** when an earlier one takes every
request it takes, its path, its host, its methods, its addresses, its port,
its route and its attributes included: it never applies.
An earlier rule is a **carve-out** of a later one when its path is strictly
narrower, or when it takes only requests the later one takes too: that is
the order `access_control` expects, and it is never reported.

```yaml
access_control:
    - { path: ^/admin/public, roles: PUBLIC_ACCESS }   # a carve-out of the next rule
    - { path: ^/admin, roles: ROLE_ADMIN }
```

Any other pair of rules that share a request is an **overlap**, listed with
`--show-overlaps`: the order decides which rule applies to the requests both
take. `{ path: ^/admin, methods: [GET] }` before `{ path: ^/admin/users }`
leaves `POST /admin/users` to the later rule, an overlap and not a shadow.
Roles, methods and addresses are read as the security bundle reads them,
trimmed of nothing: a string of roles or methods is split on its commas
once its parameters are resolved (`roles: '%admin_roles%'` holding
`ROLE_A,ROLE_B` is two roles, `['%admin_roles%']` one),
the request matchers split each method and each address on its commas
(`['GET,POST']` takes `GET` and `POST`), a method written ` get` takes no
request, and a rule is public only for `PUBLIC_ACCESS` written so and alone
(`IS_AUTHENTICATED_ANONYMOUSLY` grants nothing since Symfony 6.0). An
`allow_if` of `"0"`, written so or held by a parameter, is none, as the
bundle reads it.
Addresses, a route and attributes are compared as written: an earlier rule
holding a route or an attribute the later one does not require the same way
leaves it the other requests, so `{ route: app_login }` before
`{ path: ^/admin }` is an overlap. Two rules for different ports share no
request. A rule for `GET` takes the `HEAD` requests too where the
application's `symfony/http-foundation` sends them to it, as the router
does (6.4.50, 7.4.23 and 8.1.11 on, never 8.0): there a rule for `HEAD`
only declared after a rule for `GET` on its paths is shadowed, and one
declared before it is a carve-out. A host the analysis cannot read leaves
the paths to decide; `allow_if` and `requires_channel` decide access, not
which rule applies, and play no part.

`regex:security` and `regex:analyze` read the security configuration the
application runs with, as `bin/console debug:config security` shows it:
`config/packages/security.yaml`, `security.php` or any other file the kernel
loads, in any YAML shape (block or flow lists, anchors and aliases,
`when@<env>` blocks), with its `%parameters%` resolved, as the kernel
resolves them, and checked as the bundle checks what they hold:
`port: '%admin_port%'` is the port the parameter holds, a parameter holding
a list gives a rule its roles, methods or addresses, and one holding the
string `'8080'` as a port is refused, as the bundle refuses it.
An `%env()%` value is known at run time only, in the environment the
application runs in: it is never read from the one the command runs in,
not even its `env()` default, and a rule holding one in its path, host,
addresses, route or attributes is restricted to a value the analysis cannot
know. Through that value it shadows no later rule, a later rule holding one
is shadowed only by an earlier rule that takes every value of it, and the
finding notes `Values read from the environment are not evaluated: host.`;
one on a path read from the environment gives no example path.
The same expression, the same processors over the same variable, gives the
same value: two rules with `host: '%env(ADMIN_HOST)%'`, written so or
through a parameter, compare as holding one host, and
`'%env(trim:ADMIN_HOST)%'` is another value, as is an attribute written
`'%%env(FORMAT)%%'`, the text itself. A firewall whose pattern holds
an `%env()%` value is skipped. A `port` or `methods` read
from the environment is refused, as the security bundle refuses them: it
builds the port matcher, and upper-cases the methods, when the container is
compiled, and the application does not boot.
Each finding names the file and the line its value is written on, or
`security configuration` when no `security.*` file under `config/` writes it.
A rule with no path, host, `route`, `request_matcher` or `allow_if`, such as
`{ roles: ROLE_USER }`, is found by its roles, `ips`, `methods` or
`requires_channel`, on the line that opens its entry.
`--config=<file>` analyzes that YAML file instead, read with `symfony/yaml`
(`composer require symfony/yaml`), its `%parameters%` taken from its own
`parameters` and then from the application's, as its compiled container
holds them, a parameter a compiler pass sets included, its `%env()%` values
left unknown as above: a parameter the application reads from the
environment stays unknown too. The application's container is built only
when the file asks for one of its parameters.

A security configuration either command cannot read stops it with exit code 2:
a `--config` file that is missing, is not YAML or does not parse, a parameter
nothing defines, a configuration the SecurityBundle refuses (a `port` or
`methods` read from the environment among them, the message naming the
field and the rule), `symfony/yaml` not installed. `regex:analyze --format=json` prints the
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
