CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `regex lint` names the author in its title and ends on the request for a
   GitHub star under `--verbose` only, as the bridges' `regex:lint` does.
 * Without `--ansi` or `--no-ansi`, colour follows the standard output on
   every system: a terminal gets it, a pipe does not, and on Windows a
   console that renders the escape sequences; it never depended on the
   posix extension.
 * `--locale <name>` names the `LC_CTYPE` locale patterns are judged under,
   and wins over `locale` in `regex.json`.
 * `--cache-dir <dir>` keeps what the running PCRE2 answered about Unicode
   classes in `<dir>`, for the next run to read instead of asking again.
 * `regex lsp` runs the language server over stdio, so `regex.phar` carries
   it; installed without `php-regex/regex-language-server`, the command
   exits with code 2 and names the package.
 * `lint` reports the inline markers and the baseline entries that silence
   nothing, as `regex.ignore.unmatched`; `--no-report-unmatched-ignores`
   turns the report off for one run.
 * `validate`, `analyze` and the other commands that read one pattern refuse
   a `--php-version` below 7.3 as `lint` does, with exit code 2.
 * `regex.phar` is built from the release tag by a GitHub Actions workflow
   and carries a build provenance attestation:
   `gh attestation verify regex.phar --repo php-regex/php-regex` checks it.
 * `regex analyze` and `regex debug` show no hotspot or finding for a pattern
   proven safe: the analysis carries none.
 * `regex debug` names no culprit or trigger and generates no attack subject
   for a pattern proven safe; a subject given with `--input` is still shown.
 * The progress and the steps of `regex lint` go to stderr, so that a report
   redirected to a file holds no progress frames; the run has a third step,
   `[3/3] Suggesting optimizations`, and its clock keeps running while the
   workers work.
 * The default `--jobs` is the number of logical CPUs the process may use,
   within a container's CPU quota; `swoole_cpu_num()`, `hw.ncpu` and `wmic`
   are no longer read, and without `pcntl` the run uses one process.
 * A worker that fails or exits stops `regex lint` with exit code 1 and names
   the files it was reading; `--format=json` prints the envelope with stage
   `analyze`.
 * `regex lint --verbose` names each file read with the tokenizer because the
   PHP parser could not read it (`Parsed with the tokenizer: <file> (<reason>)`),
   and the JSON report counts them in `stats.parser_fallbacks`.
 * `regex lint --output=<file>` writes the console report without the
   terminal's margin: each report starts at the first column of the file.
 * `vendor/bin/regex` loads the autoloader Composer names, so a package
   installed from a symlinked path repository runs on the project's
   dependencies; with no autoloader at all it says to run `composer install`
   and exits with 1.
 * `regex lint --output=<file>` refuses a file it cannot write before the
   run, with exit code 2 and the JSON stage `usage`, as `--generate-baseline`
   does; it used to report it on stderr after the run and exit with 0.
 * The help screens list every option each command accepts (`lint`,
   `compare`, `diagram`; `--json` on `analyze`, `debug`, `redos` and
   `transpile`).
 * `regex lint` reads the eight presets by default, `RegexIterator`,
   `filter_var()` and the patterns the same file proves through a constant,
   a variable or a property; `--interop`, `--no-interop` and
   `extraction.interop` narrow what is read, an unknown preset name is a
   usage error (exit 2) that names the preset it most likely means, and
   `regex debug` prints the warnings of `regex.json` on stderr.
 * A `--pattern-function` or `extraction.functions` spec the lint cannot
   read, `x#a` say, is ignored with a warning instead of silently.
 * `graph` reads `--format` in any case, as the other commands do, and
   names `graphviz`, an alias of `dot`, in its usage, its error and its help.
