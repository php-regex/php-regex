CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `regex:lint` counts the files the PHP parser could not read, read with the
   tokenizer instead, in `stats.parser_fallbacks` of its JSON report, and
   leaves them out of the number of patterns found.
 * `regex:transpile --format=json` prints the JSON of `regex transpile`, which
   replaces `{source, target, result, flags, warnings, compatible}` and the
   `{error, details, snippet}` errors; `regex:lint` prints its machine reports
   under `--quiet` and a JSON failure as the error envelope, with its `stage`.
 * `regex:transpile --target` takes the transpiler's targets, as `regex
   transpile` does: `javascript` (alias `js`) and `python` (alias `py`). The
   `ruby`, `go`, `rust`, `java`, `csharp` and `swift` values, which never
   transpiled, are gone; an unknown target is a usage error (exit 2, `stage`
   `usage` in JSON). `--format` values are case-insensitive.
