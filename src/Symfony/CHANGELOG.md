CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
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
   is no string is ignored with a warning naming its type. A `Regex`
   constraint read from both the PHP file and the validator's metadata is
   reported once.
 * `php_regex.extraction.interop` and `functions` take one name written
   without a list, and a `functions` spec the lint cannot read is ignored
   with a warning, `Malformed pattern function "x#a", ignored.`
 * `regex:routes` and `regex:analyze` read the routes in declaration order,
   as the router does, first match wins: two routes sharing some paths are
   an overlap whichever is declared first, the earlier one winning them, and
   a route is shadowed when an earlier one takes all its paths. A narrower
   route declared before a wider one is not reported.
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
