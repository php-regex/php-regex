CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
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
