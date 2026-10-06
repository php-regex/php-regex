CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * The lint command reads the file of `--baseline` and `--generate-baseline`
   after a space too (`--baseline base.json`); either option with no file is a
   usage error.
