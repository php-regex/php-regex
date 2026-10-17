CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * A guard on `PHP_VERSION_ID` around a pattern narrows the versions it is
   judged for, as in `regex lint`.
 * `@regex-target` narrows the versions a pattern is judged for, as in
   `regex lint`; a marker that keeps no version, or cannot be read, is an
   error on the marker.
 * `@regex-ignore` and `@regex-ignore-next-line` may name the issues they
   silence; a marker that silences nothing is a warning on the marker,
   `regex.ignore.unmatched`, unless `reportUnmatchedIgnores` in `regex.json`
   is `false`.
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
 * A failing handler leaves one stderr line, for a request as for a
   notification — the request is still answered Internal Error (-32603),
   where it used to be answered in silence.
 * The completion context is computed on the pattern's bytes: a cursor past
   an escape the literal spells (`\x5b`, `\u{e9}`) or a multibyte character,
   or on a later line of a multi-line pattern, offers what belongs there,
   where columns used to be mixed with byte offsets.
 * A second `initialize` is answered Invalid Request (-32600) instead of
   re-initializing the whole session; notifications that arrive before
   `initialize` are dropped — with a line on stderr, the `$/` ones
   silently — instead of being served.
 * A frame that declares more than 16 MiB of body, or a header block over
   8 KiB, is refused: the oversized body is drained and the session goes
   on, the unbounded header block ends it at exit 0 — exit 1 stays the
   crash signal.
 * A client that offers dynamic registration is asked, after `initialized`,
   to watch the workspace's PHP files and `vendor/composer/installed.json`;
   the events it then sends are read, vendor files aside, once per
   notification.
 * Every diagnostics push carries the document's `version` when the client
   sent one, the clearing push on close aside.
 * A pattern not analyzed for a limit is published as a Warning, the
   severity `regex lint` reports for the same outcome, where it used to be
   Information.
 * The client capabilities are read from `capabilities`, where the
   `initialize` request carries them: the watched-files registration is
   offered to the clients that really offer dynamic registration.
 * A frame log on stderr: the protocol's `trace` setting at `initialize`,
   `initializationOptions.trace` over it, and `$/setTrace` after it turn it
   on — `messages` writes one line per frame read or written, its kind,
   method and id; `verbose` adds its payload as JSON, cut past 1 KiB on a
   character boundary. A value that is not `off`, `messages` or `verbose`
   is ignored with a warning.
