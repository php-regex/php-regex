CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `vendor/bin/regex-lsp` loads the autoloader Composer names, so a package
   installed from a symlinked path repository runs on the project's
   dependencies.
 * Diagnostics run the validator too: a pattern that parses but that PCRE
   refuses (an unbounded lookbehind, a reference to a missing group) is
   published with that error, and no lint issue beside it.
 * A call to a function or static method declaring a parameter with
   `#[RegexPattern]`, or PhpStorm's `#[Language('RegExp')]`, is checked at
   that argument, as `regex lint` checks it. The declarations are read from
   the workspace's PHP files at `initialize` (regex.json's `paths` and
   `exclude`, `vendor` left out by default, 20,000 files at most) and from
   the open documents on every change; `textDocument/didSave` and
   `workspace/didChangeWatchedFiles` read a file again.
 * A double-quoted pattern is read as PHP reads it: `"/\d+\.x/"` is
   `/\d+\.x/`, where the escapes PHP keeps as written lost their backslash.
 * The server reads the calls `regex lint` reads, configured by
   `extraction.interop` and `extraction.functions` in the workspace's
   `regex.json` (one it cannot read, an unknown preset included, is an
   error shown to the user, and the file is ignored), and diagnoses a
   pattern held in a constant, a variable or a property at its literal.
   The declarations of `vendor/` are read too, 20,000 PHP files at most,
   with a warning logged past that.
 * PHP's own warnings go to stderr, never between the JSON-RPC frames on
   stdout.
