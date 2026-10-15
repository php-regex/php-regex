CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
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
