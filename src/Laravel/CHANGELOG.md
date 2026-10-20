CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `regex:lint` reads `paths` and `exclude` of `config/php-regex.php` from
   `base_path()`, whatever directory it runs from; the paths given on the
   command line are read from the working directory.
 * `regex:lint` stops with exit code 2 on a path that does not exist, as
   `regex lint` does, where it passed.
 * `regex:lint` runs the lint the Symfony bundle runs, and prints it as `regex lint`
   does: the steps and their progress bars on stderr, the banner and the
   report on stdout, and an error that stops the run as one line on stderr,
   `Error: ...`.
 * The console reports name the author and end on the request for a GitHub
   star under `-v` only.
 * `locale` in `config/php-regex.php` names the `LC_CTYPE` locale
   `regex:lint` judges patterns under; the `Regex` service judges under the
   running PHP's.
 * `report_unmatched_ignores`, on by default, makes `regex:lint` report each
   `@regex-ignore` marker, or issue identifier it names, that silences
   nothing, as `regex.ignore.unmatched`.
 * The facade's `create()` and `optimize()` take the `OptionsArray` and
   `OptimizerOptionsArray` shapes of the toolkit, so PHPStan checks the
   options passed through them.
 * `analysis.complexity`, off by default, makes `regex:lint` report the
   patterns whose complexity score reaches `analysis.warning_threshold`,
   as `regex lint` does under `checks.complexity`.
 * `regex:compare --method intersection|subset|equivalence`, defaulting to
   `intersection` as the CLI and Symfony do; the JSON report names the
   method. `-j` for `regex:lint --jobs`, `-t` for `regex:transpile --target`.
 * `regex:lint` reads a `regex:` or `not_regex:` validation rule as Laravel
   reads it: a rules string is split on every `|`, so the rule after
   `nullable|string|` is read, and the parameter is linted as written,
   between braces or any other delimiter PHP takes.
 * `regex:explain` no longer stops with an error on a pattern the optimizer
   shortens.
 * A route constraint is judged as the router compiles it: between braces,
   in a group named after the parameter, its own groups non-capturing.
   `[{]`, `a\$` and `(a)(b)\2`, which break the route, are invalid.
 * `regex:compare` prints its example quoted and escaped, as the CLI does.
 * The `regex:routes` and `regex:compare --format=json` reports are part of
   the backward compatibility promise, listed key by key in the JSON output
   reference: one document ending with a newline, Unicode and slashes
   unescaped, a byte that is not valid UTF-8 written `\xNN`. `regex:compare`
   prints every key whatever the method (`left_only_example` and
   `right_only_example` in snake_case), and a failure as the error envelope:
   stage `usage` for an option, `pattern` for an invalid pattern (with its
   `validation`) or one the automata cannot read.
 * The `ValidRegex` rule validates a pattern a user submits, with the
   application's `Regex` service and the library's message; with
   `redos: true` a ReDoS severity from `redosThreshold` (`high`) up is
   refused too.
 * `regex:lint` shows the progress as the workers make it, in three steps
   (`[3/3] Suggesting optimizations`), counts the workers it started under
   `Processes`, and defaults to the logical CPUs the process may use, within a
   container's CPU quota. A worker that fails or exits stops it with exit code
   1, naming the files it was reading; JSON says stage `analyze`.
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
 * `regex:lint` reads the functions marked `#[RegexPattern]` (or PhpStorm's
   `#[Language('RegExp')]`) in the configured `paths` (the linted ones when
   none are), with `exclude` where they are linted, and in `vendor/` whatever
   `exclude` says: a call to one is read as a `preg_*()` call.
 * `regex:lint` reads `php-regex.extraction.interop` (`null` for every
   preset, the default) and `php-regex.extraction.functions`, the shape of
   `extraction` in `regex.json`. An unknown preset stops `regex:lint`
   (exit 2), naming the preset it most likely means; an entry of either
   list that is no string is ignored with a warning naming its type.
 * A `functions` spec the lint cannot read is ignored with a warning,
   `Malformed pattern function "x#a", ignored.`
