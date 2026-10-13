CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `regex lint --verbose` names each file read with the tokenizer because the
   PHP parser could not read it (`Parsed with the tokenizer: <file> (<reason>)`),
   and the JSON report counts them in `stats.parser_fallbacks`.
 * `regex lint --output=<file>` writes the console report without the
   terminal's margin: each report starts at the first column of the file.
 * `vendor/bin/regex` loads the autoloader Composer names, so a package
   installed from a symlinked path repository runs on the project's
   dependencies; with no autoloader at all it says to run `composer install`
   and exits with 1.
 * The help screens list every option each command accepts (`lint`,
   `compare`, `diagram`; `--json` on `analyze`, `debug`, `redos` and
   `transpile`).
