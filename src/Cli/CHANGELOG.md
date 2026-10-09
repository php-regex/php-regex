CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `regex lint --verbose` names each file read with the tokenizer because the
   PHP parser could not read it (`Parsed with the tokenizer: <file> (<reason>)`),
   and the JSON report counts them in `stats.parser_fallbacks`.
 * The help screens list every option each command accepts (`lint`,
   `compare`, `diagram`; `--json` on `analyze`, `debug`, `redos` and
   `transpile`).
