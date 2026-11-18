CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `regex:security` and `regex:analyze` read an `access_control` path or
   host ending on a comment of the `x` flag, `(?x)^/admin  # back office`,
   or on a `\Q` left open, `^/admin\Q.x`, as Symfony compiles it, where the
   search built from it swallowed its closing parenthesis and the command
   stopped on a syntax error. A path PCRE refuses, `^/(admin` or
   `(?<=a+)b`, leaves its rule out as an invalid path regex, and a host PCRE
   refuses is not evaluated, where the first stopped the command and the
   second was compared.
 * `regex:security` and `regex:analyze` read an `access_control` path or
   host, and a firewall pattern, as written, as the security bundle
   compiles it: `^/admin\` and a line feed matches that line feed, where it
   was trimmed into an invalid path regex and its rule left out, and
   `' ^/admin'` matches no path, where it was read as `^/admin`. A path or
   a host of spaces only is one, where it was read as none, and one of
   `"0"` is none, as the bundle reads it.
 * `regex:security` and `regex:analyze` read an `access_control` path, and
   a firewall pattern, with the `s` modifier only where the application's
   `symfony/http-foundation` compiles it with `s` (6.4.46, 7.4.19 and 8.1.7
   on, never 8.0): elsewhere `^/admin.x` no longer takes `/admin%0Ax`, nor
   shadows `^/admin\nx`. A path is still searched for across the line
   feeds of a request path, whatever the modifier. The package requires
   `symfony/http-foundation`, which `symfony/http-kernel` already installs.
 * `regex:security` and `regex:analyze` read an `access_control` rule for
   `GET` as taking the `HEAD` requests where the application's
   `symfony/http-foundation` sends them to it (6.4.50, 7.4.23 and 8.1.11
   on, never 8.0): there `{ path: ^/x, methods: [GET] }` shadows a later
   `{ path: ^/x, methods: [HEAD] }`, where the pair was set apart on its
   methods.
 * `regex:security` and `regex:analyze` read the roles, methods and
   addresses of an `access_control` rule as the security bundle and its
   request matchers do, where they were trimmed and a string was split on
   every comma: `roles: '%admin_roles%'` holding `ROLE_A,ROLE_B` is two
   roles, `methods: ['GET,POST']` takes `GET` and `POST`, ` get` takes no
   request, `ips: '10.0.0.1, 10.0.0.2'` holds both addresses, and a rule
   is public only for `PUBLIC_ACCESS` as written and alone, no longer for
   `public_access` or `IS_AUTHENTICATED_ANONYMOUSLY`, which grant nothing,
   nor for `PUBLIC_ACCESS` beside another role. Roles differing in case are other roles. An `allow_if` of `"0"` is none,
   one of a space is one, and a `request_matcher` or a `requires_channel`
   is read as written.
 * `regex:security` and `regex:analyze` read the security configuration of
   the kernel as `MergeExtensionConfigurationPass` has the bundle process
   it, its parameters checked as what they hold and its environment values
   taken by any node: `port: '%admin_port%'` holding `8080`, roles, methods
   or addresses a parameter holds as a list, or a firewall's
   `stateless: '%env(bool:STATELESS)%'` no longer stops the command, and a
   port given by a parameter holding the string `'8080'` stops it as the
   bundle refuses it. An
   `%env()%` value, in the kernel or in a `--config` file, is never read
   from the environment the command runs in, nor from its `env()` default,
   nor through a parameter of the application a `--config` file takes:
   a rule holding one in its path, host, addresses, route or attributes is
   restricted to a value the analysis cannot know, shadows no later rule
   through it, and notes
   `Values read from the environment are not evaluated: host.`, with no
   example path when the path is read so; a firewall
   whose pattern holds one is skipped, with that reason. Two rules reading
   a field through the same expression, the same processors over the same
   variable, compare as holding one value, so the shadow between them is
   found, and one written as the same text, `'%%env(FORMAT)%%'`, as
   another value. An `%env()%` value nothing defines no longer stops the reading of
   a `--config` file. A
   `port` or `methods` read from the environment stops the command with
   exit code 2, naming the field and the rule, as the security bundle
   refuses them: the application does not boot.
 * `regex:security` and `regex:analyze` read the `port`, `route` and
   `attributes` of an `access_control` rule, as the security bundle chains
   them to its request matcher: an earlier rule holding one the later rule
   does not require the same way no longer shadows it, and two rules for
   different ports share no request. `{ route: app_login, roles:
   PUBLIC_ACCESS }` before `{ path: ^/admin, roles: ROLE_ADMIN }` is an
   overlap, where it was reported as a critical shadow. A rule with no
   path is said to match every path, where it was said to match all
   requests whatever its methods, host, port or addresses; one naming a
   route or attributes is no longer counted among them. A `port` that is no
   number is an error.
 * `regex:security` compares a path under `(*UTF)` with one without it, and
   `regex:routes` a route with the `utf8` option with one without it, on the
   request paths that are valid UTF-8, as Symfony matches them:
   `(*UTF)^/admin/users` before `^/admin` is a carve-out where it was an
   overlap, `^/admin` before `(*UTF)^/admin/users` shadows it, and
   `/users/{id}` before the same route with `utf8` shadows it.
 * `php_regex.php_version` takes a Composer constraint (`">=8.1"`,
   `"^8.1 <8.7"`) besides a single version: `regex:lint` judges the range as
   `regex lint --php-version` does, from its lowest PHP and at every later
   PHP it allows, with `php_regex.php_version` as the target's source. A
   constraint that names no lowest version (`"<8.4"`, `"*"`) stops the
   container compile with the message `regex lint` gives it. The
   `php_regex.regex` service still judges for the running PHP.
 * `regex:lint` reads the pattern arguments `regex lint` now reads: those
   built from parts at the call, chosen by a ternary or a `match`, held in
   an array, at file level or in a global constant of another file. Under
   its summary line, the report says how many pattern arguments are built
   at run time, which no rule could verify: `Not verifiable: 3 pattern
   arguments (1 dynamic, 1 around a dynamic part, 1 around preg_quote())`.
 * The console reports of `regex:routes`, `regex:security` and
   `regex:analyze` write the text they quote from the configuration in
   display form: a line break, a tab and a carriage return are `\n`, `\t`
   and `\r`, another control character `\xHH`,
   a pattern is spelled in its own mode and a console tag stays text.
   The JSON report keeps each value as found.
 * `regex:lint` reads the `matches` patterns of the Twig templates under the
   linted paths; `--no-twig` leaves them out.
 * `regex:lint --min-savings` takes a whole number, 0 or more, and refuses
   anything else as `--jobs` is refused, exit code 2 and `The --min-savings
   value must be a non-negative integer.`: `--min-savings=abc` was read as 1
   and `--min-savings=-5` as a negative saving.
 * `regex:lint` leaves a validator mapping file out as it leaves out a PHP
   file: an `exclude` is a directory name anywhere below the project, or the
   path of a file or a directory, from the project, behind `./` or absolute,
   where only a directory at the root of the project left a mapping file
   out.
 * `regex:lint` reads the `Regex` constraints of the validator mapping files
   FrameworkBundle hands the validator (`config/validator/`,
   `framework.validation.mapping.paths`, the bundles' validation files), where
   it read none: it asked for a service FrameworkBundle does not define. Each
   pattern is reported at its YAML or XML file, on the line that writes it,
   where a running counter stood in for the line; a mapping file the
   validator cannot read is `regex.lint.source.unreadable`. An attribute is
   read from the PHP file alone.
 * The bundle is `PHPRegex\Symfony\PHPRegexSymfonyBundle`, the name Symfony
   Flex looks for: `composer require` registers it in `config/bundles.php`.
 * The bundle is an `AbstractBundle` that defines its configuration and loads
   its services itself: `PHPRegex\Symfony\DependencyInjection\PHPRegexExtension`
   and `Configuration`, both internal, are gone. The `php_regex` alias and
   every configuration key are unchanged, as `config:dump-reference
   php_regex` shows.
 * Every service is private and named `php_regex.*`: an application
   autowires `PHPRegex\Toolkit\Regex` and the `ValidRegexValidator`, and
   none of the about twenty internal classes registered under their class
   name. `php_regex.regex` and the commands are private; the
   `PHPRegex\Toolkit\Regex` alias stays public. `RouteControllerFileResolver`,
   which nothing called, is gone.
 * `regex:security` and `regex:analyze` read `access_control` as Symfony's
   `AccessMap` applies it, first match: a narrower rule placed first on
   purpose, `^/admin/public` before `^/admin`, is a carve-out and no longer
   reported as an overlap; a later rule is shadowed only when the earlier
   one takes every request it takes, methods, host and addresses included,
   where `^/admin` for `GET` shadowed `^/admin/users` for every method.
 * `regex:security` and `regex:analyze` keep the options an
   `access_control` path or host opens with, `(*UTF)` or
   `(*LIMIT_MATCH=1000)`, in front of the search they read it as: PCRE
   reads them there only. `(*UTF)^/admin` before `^/admin/users` shadows it,
   where the pair was compared on a pattern PCRE refuses and went
   unreported.
 * `regex:security` and `regex:analyze` search for every branch of an
   `access_control` path or host written as a top-level alternation, as
   Symfony's matchers do: `^/assets|^/login` with `PUBLIC_ACCESS` before
   `^/assets/private` with `ROLE_ADMIN` is a critical shadow, where the first
   branch was read as the whole path and the pair went unreported. An anchor
   binds only the branch it is written in.
 * The `ValidRegex` messages ship in an English catalogue,
   `Resources/translations/validators.en.xlf`, of the `validators` domain:
   an application translates them as it translates the messages of
   Symfony's own constraints.
 * `regex:analyze` is given its two analyzers, routes then security, in place
   of an internal `php_regex.bridge_analyzer` tag and an `AnalyzerRegistry`
   that sorted them by priority; both are gone.
 * The bundle sets no container parameter: its configuration goes straight
   into its services, where 22 `php_regex.*` parameters (two of them read by
   nothing) let an application read and override it. Read the configuration
   with `debug:config php_regex`.
 * `ide` defaults to `null`, and the links of `regex:lint` then open the
   editor of `framework.ide` (`SYMFONY_IDE` by default), as FrameworkBundle's
   own pages do. The documented fallback never fired: the default was an
   environment placeholder, never empty, and FrameworkBundle sets
   `debug.file_link_format`, not `framework.ide`.
 * `regex:lint` reads `paths` and `exclude` from `%kernel.project_dir%`,
   whatever directory it runs from; the paths given on the command line are
   read from the working directory.
 * `regex:lint` stops with exit code 2 on a path that does not exist, as
   `regex lint` does, where it passed.
 * `regex:lint` runs the lint the Laravel package runs, and prints it as `regex lint`
   does: the steps and their progress bars on stderr, the banner and the
   report on stdout, and an error that stops the run as one line on stderr,
   `Error: ...`.
 * The console reports name the author and end on the request for a GitHub
   star under `-v` only.
 * `locale` names the `LC_CTYPE` locale `regex:lint` judges patterns
   under; the `php_regex.regex` service judges under the running PHP's.
 * `report_unmatched_ignores`, on by default, makes `regex:lint` report each
   `@regex-ignore` marker, or issue identifier it names, that silences
   nothing, as `regex.ignore.unmatched`.
 * `analysis.complexity`, off by default, makes `regex:lint` report the
   patterns whose complexity score reaches `analysis.warning_threshold`,
   as `regex lint` does under `checks.complexity`.
 * `regex:explain` explains a pattern, with `--format=console|html` and
   `--highlight` as in the Laravel package and the CLI, and the optimization,
   the ReDoS risk, the lint issues and a sample beside it.
 * `regex:routes` and `regex:security` are listed by `bin/console list`,
   where they were hidden.
 * A route requirement is judged as the router compiles it: in a group
   named after the parameter, its own groups non-capturing, under `i` for
   a host parameter. `[{]` and `(a)(b)\2`, which break the route, are
   invalid. A requirement is read as the route holds it, sanitized once:
   `^^a` and `a$$` keep their second anchor, and its spaces stay.
 * The `regex:analyze --format=json` report is part of the backward
   compatibility promise, listed key by key in the JSON output reference: a
   section's `meta` and `debug` are `{label, value}` rows, a usage error is
   the error envelope, stage `usage`, and Unicode and slashes are written
   unescaped. The firewall finding labels its ReDoS severity `Severity`.
 * The `ValidRegex` constraint validates a pattern a user submits, with the
   bundle's `Regex` service: the library's message and hint as `{{ error }}`
   and `{{ hint }}`, and with `redos: true` a ReDoS severity from
   `redosThreshold` (`high`) up refused too. Needs `symfony/validator`.
 * `regex:lint` shows the progress as the workers make it, in three steps
   (`[3/3] Suggesting optimizations`), counts the workers it started under
   `Processes`, and defaults to the logical CPUs the process may use, within a
   container's CPU quota. A worker that fails or exits stops it with exit code
   1, naming the files it was reading; JSON says stage `analyze`.
 * `regex:lint` leaves the files the PHP parser could not read, read with the
   tokenizer instead, out of the number of patterns found; its JSON report
   counts them in `stats.parser_fallbacks`.
 * A route requirement is linted as the route compiler matches it,
   `{^...$}sD` plus `u` under the `utf8` option; a security `path` as
   `{...}s` and a `host` as `{...}i`, without anchors. A requirement or a
   path starting with `/` is no delimited regex.
 * `regex:transpile` validates the pattern first and prints the JSON of
   `regex transpile`; `regex:lint` prints its machine reports under `--quiet`
   and a JSON failure as the error envelope, with its `stage`.
 * `regex:transpile --format` values are case-insensitive (`--format=JSON`),
   as in `regex transpile` and `regex:lint`.
 * A route requirement is linted as the route compiler reads it: anchors
   stripped, a top-level alternation grouped, `en|fr|de` as
   `#^(?:en|fr|de)$#`; a `#` in it picks another delimiter, kept as
   written; a trailing `\z` stays a strict end.
 * `regex:security` reads a block-style list of roles, methods or
   addresses in an `access_control` rule, where each item became a rule.
 * `regex:lint` reads the functions marked `#[RegexPattern]` (or PhpStorm's
   `#[Language('RegExp')]`) in the configured `paths`, with `exclude` where
   they are linted, and in the project's `vendor/` whatever `exclude` says: a
   call to one is read as a `preg_*()` call.
 * `regex:lint` reads `php_regex.extraction.interop` (every preset by
   default, and for `~`) and `php_regex.extraction.functions`, the shape of
   `extraction` in `regex.json`; a later configuration file's list replaces
   the earlier one. An unknown preset is an `InvalidConfigurationException`
   that names the preset it most likely means; an entry of either list that
   is no string is ignored with a warning naming its type.
 * `php_regex.extraction.interop` and `functions` take one name written
   without a list, and a `functions` spec the lint cannot read is ignored
   with a warning, `Malformed pattern function "x#a", ignored.`
 * `regex:routes` and `regex:analyze` read the routes in declaration order,
   as the router does, first match wins: two routes sharing some paths are
   an overlap whichever is declared first, the earlier one winning them, and
   a route is shadowed when an earlier one takes all its paths. A narrower
   route declared before a wider one is not reported. Two routes whose
   hosts part on letter case alone, `A.COM` and `a.com`, are counted among
   the pairs skipped: the router sorts the routes that name a host by the
   end of it, letter case included, and may try the later one first.
 * `regex:routes` and `regex:analyze` read the redirect of the trailing
   slash, as the framework's matcher sends it: a `GET` or `HEAD` request
   whose path differs from an earlier route's by a trailing slash alone
   goes to that route, and a later route never gets it, whatever its
   scheme. `/foo/` before `/foo`, or `/a/{x}/` before `/a/{x}`, is an
   overlap, or a shadow when the later route takes only `GET` and `HEAD`,
   where the pair was reported as no conflict. A later route whose paths
   the earlier one matches in part and redirects for the rest,
   `/blog{slash}` (`/?`) after `/blog`, is shadowed too. On another
   scheme, a later route that shares paths with the earlier one keeps them
   and loses the redirected ones: an overlap, or a pair skipped where the
   earlier route ends on a variable a value of which may end on the slash
   or be empty, `.+` or `[a-z]*`, whose redirect PCRE's order decides; the
   default `[^/]++`, or `\d+`, redirects whatever that order and is read.
   Such a route matches a path ending on the slash as is, on its own
   schemes, or redirects it, as PCRE captured the value: a later route it
   matches only with that slash, `/{y<a//>}` after `/{x<a/?>}`, is shadowed
   only when the earlier route takes every scheme of it, and one for other
   methods than `GET` and `HEAD` on a path ending on the slash,
   `/files/{name}/` after `/files/{path<.+?>}`, is an overlap.
   A later route serves the path a redirect leads to when it matches it:
   one that matches every path of the earlier route, `/{path<.*>}` on
   `http` after `/admin` on `https`, loses none. Where it serves the path
   the shortest redirected one leads to but not every path of the earlier
   route, `/files/{path<[a-z/]+>}` on `http` after `/files/{name}` on
   `https`, the pair is skipped. A route with no variable is looked up by
   its path, every trailing slash of the request trimmed off, before any
   route tried with a regex, unless an earlier one of those matches its
   path: `/admin` redirects `GET /admin//` too, shadowing `/{p<admin//>}`,
   `/admin/` takes `/admin//` as is, for any method on its own schemes,
   and `/` answers `GET //` with a 404; a route tried with a regex takes
   one slash at most. A later route ending on a variable that may end on
   the slash, with no slash after it, `/{y<a|a/|b>}`, reads a path of its
   own ending on the slash for the slash: for `POST` it passes `/a/` on,
   which it does not lose, and a pair whose shortest shared path it passes
   on is skipped; a redirect leading to a path it reads for its slash
   still loses the request. Each such finding notes what the router does
   with the request: redirects it to the earlier route, matches it as is, or
   answers it with a 404. The report
   suggests paths that differ by more than the slash, never the reorder
   that only turns the redirect round.
 * `regex:routes` and `regex:analyze` call a route shadowed only when an
   earlier route takes every request of it, its methods (`GET` taking
   `HEAD`), its schemes and its host included, as the security analysis
   does. An earlier route whose requests are all among the later one's,
   narrower by path, method, scheme or host, is a carve-out and is not
   reported, as the security analysis reads it: a route for `HEAD` only
   before a route for `GET` on the same path, a route for `GET` before one
   for `GET` and `POST`, a route for `https` before one for any scheme and
   a route for a host before one for any host, which were reported as
   shadowed. Any other pair sharing a path is an overlap. The report no
   longer suggests merging two routes on the same paths that each keep
   some requests.
 * `regex:routes` and `regex:analyze` read a route for `GET` as taking the
   `HEAD` requests too, as Symfony's matchers send them: a route for `HEAD`
   only declared after a route for `GET` on its paths is shadowed, where the
   pair was set apart on its methods.
 * `regex:security` and `regex:analyze` read the security configuration the
   application runs with, as `debug:config security` shows it: every file
   format the kernel loads, every YAML shape, the parameters resolved, where
   a line scanner read the block style of `security.yaml` only and passed
   on the rest. `--config=<file>` reads that YAML file with `symfony/yaml`,
   now suggested. A configuration they cannot read is exit code 2, stage
   `config` in the JSON report of `regex:analyze`.
 * `regex:routes` and `regex:security` print the sections of `regex:analyze`,
   the same analyzers in the same layout; `-v` adds the counts of the
   analysis and why each entry left out was left out.
 * `regex:security` and `regex:analyze` skip a pair of `access_control` rules
   whose path the automata cannot read (a backreference), where they stopped
   on an uncaught exception; the commands catch the library's exceptions
   only, and any other error surfaces.
