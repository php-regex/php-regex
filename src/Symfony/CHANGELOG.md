CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `regex:transpile` validates the pattern first and prints the JSON of
   `regex transpile`; `regex:lint` prints its machine reports under `--quiet`
   and a JSON failure as the error envelope, with its `stage`.
 * `regex:transpile --format` values are case-insensitive (`--format=JSON`),
   as in `regex transpile` and `regex:lint`.
