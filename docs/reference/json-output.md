# JSON Output

The `regex` command prints JSON when it is given `--format=json` or `--json`.
These documents are part of the
[backward compatibility promise](backward-compatibility.md): for all of 2.x,
a key keeps its name, its type and its meaning. A minor release adds keys and
values, and may improve messages; see
[What a minor release may change](#what-a-minor-release-may-change).
This page lists every key of every document.

| command | document |
|---|---|
| `regex lint` | the [lint report](#lint-report) |
| `regex lint --generate-baseline=<file>` | the [baseline file](#baseline-file), written to `<file>` |
| `regex analyze` | the [analyze document](#analyze) |
| `regex debug` | the [debug document](#debug) |
| `regex redos` | the [benchmark document](#redos) |
| `regex transpile` | the [transpile document](#transpile) |

Symfony's and Laravel's `regex:lint` print the same lint report, and their
`regex:transpile` the same transpile document, with the same error envelope.
An argument error their console framework catches before the command runs,
such as an unknown option or a missing argument, is reported by the framework
in its own form, not as the envelope.

## The Rules

### One document on stdout

- Every run in JSON mode prints exactly one JSON document on stdout:
  pretty-printed, slashes and Unicode unescaped, ending with one newline.
- Status lines, progress and notes go to stderr. `--quiet` silences them; it
  never silences the document.
- The same holds for every machine format of `lint`: the JSON, GitHub,
  Checkstyle and JUnit reports are printed under `--quiet`, by `regex lint`
  and by Symfony's and Laravel's `regex:lint`. `--quiet` silences the human
  status lines only.
- A failure prints the [error envelope](#the-error-envelope) instead, with
  the exit code the command uses anyway (see
  [Exit Codes](../guides/cli.md#exit-codes)).
- PHP's own diagnostics (warnings, notices) go to stderr in JSON mode, never
  into the document. A fatal error, such as `redos` running out of
  `--time-limit`, prints the envelope with stage `internal` and exits with 1.
  An exception the command does not handle prints the envelope with stage
  `internal` and exits with 1 too. A process killed from outside, by a hard
  limit or a signal, cannot print anything: stdout is then empty, and the exit
  code is the one the system gives.
- JSON mode is read before anything else: `--format=json`, `--format json`
  and `--json` count wherever they sit on the command line, so a mistake in
  another option is reported as JSON too. An unknown command, or `--json`
  placed before the command, is reported as JSON as well. `"format": "json"`
  in `regex.json` asks for JSON once `lint` reads its options: the report and
  the errors `lint` finds are JSON, but an error in a global option, read
  before `regex.json`, is reported as text unless the command line asks for
  JSON too.
- `--format` values are case-insensitive: `--format=JSON` is `--format=json`.
  When the command line holds several `--format` or `--json` options, the
  last one wins, on a failure as on a success.
- An unknown `--format` value does not ask for JSON: it is a usage error
  printed as text.

### Keys and values

- Every key is snake_case.
- Every key a table lists is present, `null` when it does not apply. A key
  whose type says "optional" may be absent.
- A value listed as one of a few words (a severity, a stage, a category) is
  an open set: a minor release may add a value. Treat a value you do not know
  as you would the closest one you do, or ignore it.
- Messages, hints and tips are for people. Their wording is not frozen and
  may improve in any release. Match on `issue_id`, `error_code`, `stage` or
  `severity`, never on a message.

### Strings are written as they are

A string is written as itself: a pattern holding a newline or a control byte
comes out with JSON's own escape for it (`\n`, `\u0001`), which decodes back
to the same text. The one exception is a byte that is not valid UTF-8, which
JSON cannot carry: each such byte is written as the four characters `\xNN`
(uppercase hex) and the rest of the string is kept. Such a string is a display
form, so a document can always be encoded, even for a byte-mode pattern.

The caret of `caret_snippet` is placed by counting the raw bytes of the
pattern. A byte written as `\xNN` takes four characters in the snippet but
counts as one, so the caret of a pattern holding such a byte sits to the left
of the place it means, by three columns for each such byte before it.

### Positions

Every position counts bytes.

| field | base | counted from |
|---|---|---|
| `line` | 1 | the first line of the file |
| `column` | 1 | the first byte of the line; `null` when unknown |
| `file_offset` | 0 | the first byte of the file |
| `position`, `validation.offset` | 0 | the first byte of the pattern body |
| hotspot `start`, `end` | 0 | the first byte of the pattern body |

- `line`, `column` and `file_offset` of a lint result point at the start of
  the PHP expression that holds the pattern: the opening quote of its first
  string literal. `file_offset` is `null`, and `column` too, when the pattern
  does not come from a PHP string, as a route requirement read from YAML.
- The pattern body is what sits between the delimiters. In `/(a/`, offset 0
  is the `(`. This is the coordinate PCRE2's own error offsets use. An error
  in the modifiers counts on past the body and its closing delimiter.
- A hotspot's `end` is exclusive: the span is `start` to `end - 1`.
- Editors count in UTF-16 code units. An editor integration should use the
  [language server](../guides/lsp.md), which converts, rather than these
  byte offsets.

### Paths

`file` is relative to the working directory, with `/` separators, when the
file lies under it, and absolute otherwise. `regex lint src` and
`regex lint "$PWD/src"` print the same `file` values. The baseline file uses
the same rule, with its `.` and `..` segments resolved and `\` read as a
separator, so `regex lint ./src` and `regex lint src` share a baseline.

### Order

- The lint report lists its results by `file` (compared byte by byte), then
  `line`, then `column` (`null` first), then `file_offset`, then `source`.
- The issues of a result are listed by `position` (`null` first), then
  `issue_id`.
- The `issues` of the baseline file are sorted like the results.
- `lint.target.range` lists the floor first, then the other PHP versions in
  ascending order.

The order is the same whatever the number of workers (`--jobs`). Timing
fields (`duration_ms`, `wall_ms`, `avg_ms`, `cpu_ms`) differ between two runs,
so two runs may not print the same bytes.

### What a minor release may change

- Add a key to any object, and add an optional key to the error envelope.
- Add a value to an open set: a `stage`, a `severity`, a `category`, an
  `issue_id`, an `error_code`.
- Improve a message, a hint, a tip or the escaping of a display string.
- Add an entry to `lint.target.range`: a PHP version a rule of the library
  changes at, learned in that release, which the project's `require.php`
  allows.

It never renames or removes a key, changes a key's type, or turns a success
document into one with a top-level `error`.

### Versions

No document carries a version key. The package version is the schema
version: `regex --version` prints it. `redos_analysis.analysis_version` is
something else, the version of the ReDoS model behind a verdict.

## The Error Envelope

A run that cannot produce its document prints the envelope instead:

```json
{
    "error": "Unknown option: --bogus",
    "stage": "usage"
}
```

A success document never has a top-level `error` key, so testing for it
tells the two apart.

### Error envelope: `error`

| key | type | meaning |
|---|---|---|
| `error` | string | What went wrong, for people. A string for all of 2.x; its wording is not frozen |
| `stage` | string | Where the run stopped, from the open set below |
| `validation` | object, optional | Present when the pattern given is invalid: why, as a [validation](#validation-validation) object |

`stage` is an open vocabulary. Today it is one of:

| stage | meaning |
|---|---|
| `usage` | The command line cannot be used: an unknown command or option, a missing pattern or value, an invalid `--php-version`, a path argument that does not exist, a `--baseline` file that is missing, cannot be read or is not a baseline, a `--generate-baseline` file that cannot be written (an `--output` file that cannot be written is reported on stderr after the run) |
| `config` | The configuration cannot be used: a `regex.json` that is not valid JSON, an unknown or removed key, a path it lists that does not exist |
| `collect` | `lint` could not read the files or the patterns in them |
| `pattern` | The command cannot act on the pattern it was given, such as an invalid one |
| `internal` | The command failed for another reason, a PHP fatal error included; exit code 1. Apart from a time limit you set, this is a bug worth reporting |

Other keys may come next to `error` and `stage` in a minor release, as
`validation` did.

## Invalid Patterns

An invalid pattern is a finding, not a failure, for the commands that report
on patterns:

- `lint` reports it as an issue of severity `error`. Its `issue_id` is the
  error code, such as `regex.group.unclosed`, and its `validation` says why.
- `analyze` prints its document with `parse.ok` false, `validation` saying
  why, and `redos` and `explain` null. The exit code is 1.
- `debug` prints its document with `validation` saying why and `analysis`
  null. The exit code is 1.

The commands that act on a pattern, `transpile` and `redos`, cannot. They
print the envelope with stage `pattern`, carrying the same `validation`
object, and exit with 1. `redos` judges the pattern given with `--safe` the
same way: an invalid one stops it with the envelope, stage `pattern`. `redos`
stops so in JSON only: its console output still measures a pattern PHP
refuses.

## Lint Report

`regex lint --format=json` prints one report for the whole run. `--json` is
short for `--format=json`.

```json
{
    "target": {
        "php": "8.2",
        "pcre": "10.40",
        "source": "composer.json require.php",
        "range": [{"php": "8.2", "pcre": "10.40"}, {"php": "8.3", "pcre": "10.42"}, {"php": "8.4", "pcre": "10.44"}, {"php": "8.4.25", "pcre": "10.44"}, {"php": "8.5", "pcre": "10.44"}, {"php": "8.5.10", "pcre": "10.44"}]
    },
    "stats": {"errors": 1, "warnings": 0, "optimizations": 0, "redos_errors": 0, "infos": 0, "lint_errors": 0},
    "results": [
        {
            "file": "src/Example.php",
            "line": 3,
            "column": 12,
            "file_offset": 18,
            "source": "preg_match()",
            "pattern": "/(a/",
            "location": null,
            "issues": [
                {
                    "severity": "error",
                    "file": "src/Example.php",
                    "line": 3,
                    "column": 12,
                    "file_offset": 18,
                    "position": 2,
                    "issue_id": "regex.group.unclosed",
                    "message": "Expected ) at end of input (found eof)",
                    "hint": null,
                    "tip": null,
                    "source": "preg_match()",
                    "validation": {
                        "is_valid": false,
                        "error": "Expected ) at end of input (found eof)",
                        "complexity_score": 0,
                        "category": "syntax",
                        "offset": 2,
                        "caret_snippet": "Line 1: (a\n          ^",
                        "hint": null,
                        "error_code": "regex.group.unclosed"
                    },
                    "analysis": null,
                    "target": null
                }
            ],
            "optimizations": []
        }
    ]
}
```

### Lint report: `lint`

| key | type | meaning |
|---|---|---|
| `target` | object | The PHP and PCRE2 the patterns were judged for, and every PHP and PCRE2 they were validated at |
| `stats` | object | The counts of the run |
| `results` | list of objects | One entry per pattern with at least one issue or optimization; `[]` when there is nothing to report |

### Target: `lint.target`

| key | type | meaning |
|---|---|---|
| `php` | string | The PHP version, as `major.minor`, or `major.minor.patch` when its patch is not 0 (`8.4.30` for `^8.4.30`, the running PHP's patch for `running PHP`); always the first `range` entry's `php` |
| `pcre` | string | The PCRE2 release, as `major.minor` |
| `source` | string | Where the target came from, such as `--php-version`, `regex.json`, `composer.json require.php` or `running PHP` |
| `range` | list of objects | Every PHP and PCRE2 the patterns were validated at, the floor first; a single entry, the target, unless the PHP came from `composer.json require.php` |

`php`, `pcre` and `source` name the floor: the lint rules and the ReDoS
analysis judge every pattern there. When the PHP comes from `require.php`,
each pattern is also validated (parsed and checked, without the lint rules)
at every PHP version above the floor that a rule of the library changes at
and that the constraint allows, and at the lowest version of each branch of an
OR constraint above the floor (`~8.3.0 || >=8.5.3` adds `8.5.3`), with the
PCRE2 that PHP bundles, or with the release `--pcre-version` or `pcreVersion`
names. See
[Several PHP versions](../guides/cli.md#several-php-versions).

### Target range entry: `lint.target.range[]`

| key | type | meaning |
|---|---|---|
| `php` | string | The PHP version, as `major.minor`, or `major.minor.patch` when the version is a patch release a rule changes at (`8.4.25`), the floor's own patch (`8.4.30` for `^8.4.30`, the running PHP's patch for `running PHP`) or the lowest patch of an OR branch (`8.5.3` for `~8.3.0 \|\| >=8.5.3`) |
| `pcre` | string | The PCRE2 release, as `major.minor` |

### Stats: `lint.stats`

Every count is present on every run, `0` when there is none.

| key | type | meaning |
|---|---|---|
| `errors` | int | Issues of severity `error`: invalid patterns, ReDoS errors and lint rules of error severity |
| `warnings` | int | Issues of severity `warning` |
| `optimizations` | int | Optimization entries |
| `redos_errors` | int | ReDoS issues of severity `error`, counted among `errors` |
| `infos` | int | Issues of severity `info` |
| `lint_errors` | int | Lint rules of error severity that fired, counted among `errors` |

### Result: `lint.results[]`

| key | type | meaning |
|---|---|---|
| `file` | string | The file the pattern was read from (see [Paths](#paths)) |
| `line` | int | The line of the pattern, 1-based |
| `column` | int \| null | The column of the pattern, 1-based, in bytes; `null` when unknown |
| `file_offset` | int \| null | The byte offset of the pattern in the file, 0-based; `null` when unknown |
| `source` | string \| null | What the pattern was read from: the call, as `preg_match()`, or a framework source, as `route:<name>:<parameter>` |
| `pattern` | string | The pattern as written |
| `location` | string \| null | Where the pattern is declared when the file and line cannot say it, such as a route's name and controller |
| `issues` | list of objects | The issues found in the pattern, possibly empty |
| `optimizations` | list of objects | The optimizations suggested for the pattern, possibly empty |

### Issue: `lint.results[].issues[]`

An issue repeats the location of its result, so it can be read alone.

| key | type | meaning |
|---|---|---|
| `severity` | string | `error`, `warning` or `info`; an open set |
| `file` | string | As in the result |
| `line` | int | As in the result |
| `column` | int \| null | As in the result |
| `file_offset` | int \| null | As in the result |
| `position` | int \| null | Where in the pattern body the issue lies, 0-based, in bytes; `null` when it concerns the whole pattern |
| `issue_id` | string \| null | What was found: a lint rule (`regex.lint.quantifier.nested`), the ReDoS verdict (`regex.lint.redos`), the cost of an unanchored search (`regex.lint.redos.search`), a complexity warning (`regex.lint.complexity`), or the error code of an invalid pattern (`regex.group.unclosed`); an open set, and a baseline matches on it. Every issue the library reports today carries one; `null` is kept for an invalid pattern without an error code |
| `message` | string | The finding, for people; not frozen |
| `hint` | string \| null | How to fix it, for people |
| `tip` | string \| null | A further suggestion for an invalid pattern |
| `source` | string \| null | As in the result |
| `validation` | object \| null | For an invalid pattern, why: a [validation](#validation-validation) object; `null` otherwise |
| `analysis` | object \| null | For a ReDoS issue, the verdict: a [ReDoS analysis](#redos-analysis-redos_analysis), whose `search_cost` holds the attack of a `regex.lint.redos.search` issue; `null` otherwise |
| `target` | object \| null | For a pattern valid on the floor and refused by a later PHP of `lint.target.range`, the lowest PHP and PCRE2 that refuse it; `null` for every other issue, which the floor reports |

An issue whose `target` is not `null` has severity `error`, and its
`validation` is the verdict of that target. A pattern the floor refuses is
reported once, by the floor, and not validated at the other versions. A
baseline does not match on `target`.

### Issue target: `lint.results[].issues[].target`

| key | type | meaning |
|---|---|---|
| `php` | string | The PHP version, written as in [`lint.target.range[]`](#target-range-entry-linttargetrange) |
| `pcre` | string | The PCRE2 release, as `major.minor` |

### Optimization entry: `lint.results[].optimizations[]`

| key | type | meaning |
|---|---|---|
| `file` | string | As in the result |
| `line` | int | As in the result |
| `column` | int \| null | As in the result |
| `file_offset` | int \| null | As in the result |
| `optimization` | object | The rewrite |
| `savings` | int | How many bytes shorter the rewrite is |
| `source` | string \| null | As in the result |

### Optimization: `lint.results[].optimizations[].optimization`

| key | type | meaning |
|---|---|---|
| `original` | string | The pattern before the rewrite |
| `optimized` | string | The pattern after the rewrite |
| `changes` | list of strings | What changed, for people |

## Analyze

`regex analyze <pattern> --format=json` reports on one pattern. On an invalid
pattern, `parse.ok` is false, `validation` says why, `redos` and `explain`
are `null`, and the exit code is 1.

### Analyze: `analyze`

| key | type | meaning |
|---|---|---|
| `pattern` | string | The pattern given |
| `runtime` | object | The PCRE the command ran on: a [runtime](#runtime-runtime) object |
| `parse` | object | Whether the pattern parses |
| `validation` | object | The verdict on the pattern: a [validation](#validation-validation) object |
| `redos` | object \| null | The ReDoS verdict: a [ReDoS analysis](#redos-analysis-redos_analysis); `null` for an invalid pattern |
| `explain` | string \| null | The pattern explained in plain language; `null` for an invalid pattern |

### Parse: `analyze.parse`

| key | type | meaning |
|---|---|---|
| `ok` | bool | `true` when the pattern parses and is valid, `false` otherwise |

## Debug

`regex debug <pattern> --format=json` reports the ReDoS analysis in depth.
It always carries `validation`; on an invalid pattern `analysis` is `null`
and the exit code is 1.

### Debug: `debug`

| key | type | meaning |
|---|---|---|
| `pattern` | string | The pattern given |
| `runtime` | object | The PCRE the command ran on: a [runtime](#runtime-runtime) object |
| `validation` | object | The verdict on the pattern: a [validation](#validation-validation) object, `is_valid` true when it is valid |
| `analysis` | object \| null | The ReDoS verdict: a [ReDoS analysis](#redos-analysis-redos_analysis); `null` for an invalid pattern |
| `input` | object | The subject to try the pattern on |

### Debug input: `debug.input`

| key | type | meaning |
|---|---|---|
| `value` | string \| null | The subject given with `--input`, or one generated from the verdict; `null` when there is neither |
| `source` | string \| null | `user` for `--input`, `auto` for a generated subject, `null` without one; an open set |

## Redos

`regex redos <pattern> --format=json` times the pattern against a subject,
and a safer pattern given with `--safe` against the same subject. An invalid
pattern, or an invalid `--safe` pattern, stops it with the envelope (see
[Invalid Patterns](#invalid-patterns)).

### Benchmark document: `redos`

| key | type | meaning |
|---|---|---|
| `pattern` | string | The pattern given |
| `safe_pattern` | string \| null | The pattern given with `--safe`; `null` without it |
| `runtime` | object | The PCRE the benchmark ran on, `--jit` and the limit options applied: a [runtime](#runtime-runtime) object |
| `input` | object | The subject both patterns ran against |
| `settings` | object | How the benchmark ran |
| `bench` | object | One measurement per pattern |
| `summary` | object \| null | The comparison of the two patterns; `null` without `--safe` |

### Benchmark input: `redos.input`

| key | type | meaning |
|---|---|---|
| `source` | string | `user` for `--input`, `file` for `--input-file`, `auto` for one generated from the ReDoS verdict, `default` when none could be generated; an open set |
| `base_length` | int | The length of the base input, in bytes |
| `final_length` | int | The length of the subject, prefix, repeats and suffix included, in bytes |
| `repeat` | int | How many times the base input is repeated (`--repeat`) |
| `prefix` | string | The text before the repeats (`--prefix`) |
| `suffix` | string | The text after the repeats (`--suffix`) |
| `preview` | string \| null | The subject, shortened for display; `null` with `--show-input` |
| `value` | string \| null | The whole subject with `--show-input`; `null` otherwise |
| `note` | string \| null | Why the subject is not what was asked, as when none could be generated |

### Benchmark settings: `redos.settings`

| key | type | meaning |
|---|---|---|
| `iterations` | int | The timed runs of each pattern (`--iterations`) |
| `warmup` | int | The untimed runs before them (`--warmup`) |

### Measurements: `redos.bench`

| key | type | meaning |
|---|---|---|
| `vuln` | object | The measurement of the pattern: a [benchmark](#benchmark-benchmark) object |
| `safe` | object, optional | The measurement of the `--safe` pattern; absent without `--safe` |

### Benchmark: `benchmark`

One measurement, under `redos.bench.vuln` and `redos.bench.safe`.

| key | type | meaning |
|---|---|---|
| `label` | string | `vuln` or `safe` |
| `result` | string | What the last `preg_match()` returned: `match`, `no` or `error` |
| `wall_ms` | float | The wall time of all the timed runs, in milliseconds |
| `avg_ms` | float | The wall time of one run, on average |
| `cpu_ms` | float \| null | The CPU time (user and system) of the timed runs; `null` where PHP cannot measure it |
| `mem_bytes` | int | How much the memory PHP holds grew during the runs, in bytes |
| `peak_bytes` | int | How much the peak memory grew during the runs, in bytes |
| `err_msg` | string | `preg_last_error_msg()` after the runs; `-` when there was no error |
| `err_code` | int | `preg_last_error()` after the runs; `0` when there was no error |
| `iterations` | int | The timed runs made; fewer than asked when a run failed |

### Comparison: `redos.summary`

| key | type | meaning |
|---|---|---|
| `result_parity` | string | `same` when both patterns gave the same `result`, `different` otherwise |
| `speedup` | float \| null | The pattern's `avg_ms` divided by the safe pattern's; `null` when the safe pattern took no measurable time |
| `delta_ms` | float \| null | The pattern's `avg_ms` minus the safe pattern's; `null` when `speedup` is |

## Transpile

`regex transpile <pattern> --format=json` rewrites a pattern for another
regex dialect. Symfony's and Laravel's `regex:transpile --format=json` print
the same document.

The pattern is validated first. An invalid pattern stops the command with the
envelope, stage `pattern`, carrying `validation`, a semantic error such as
`/(?<=a+)b/` included. A valid pattern that uses a construct the target has
no equivalent for stops it with the envelope too, stage `pattern`, without a
`validation` key. Both exit with 1:

```json
{
    "error": "Subroutine calls are not supported in JavaScript.",
    "stage": "pattern"
}
```

### Transpile: `transpile`

| key | type | meaning |
|---|---|---|
| `target` | string | The dialect, by its full name: `javascript`, `html-pattern` or `python`; an open set |
| `source` | string | The PCRE pattern given |
| `pattern` | string | The pattern body in the target dialect |
| `flags` | string | The target's flags |
| `literal` | string | The pattern as a literal of the target language, as `/a+b/i` |
| `constructor` | string | Code that builds the pattern in the target language, as `new RegExp("a+b", "i")` |
| `warnings` | list of strings | Where the target matches differently from PCRE |
| `notes` | list of strings | What was rewritten on the way, matching the same |

## Shared Objects

### Runtime: `runtime`

The PCRE a command ran on, in `analyze`, `debug` and `redos`.

| key | type | meaning |
|---|---|---|
| `version` | string | The PCRE2 version PHP reports, date included, as `10.49 2026-09-28` |
| `jit` | bool | The `pcre.jit` setting, read as PHP reads it; the setting, not whether JIT is available |
| `backtrack_limit` | int | The `pcre.backtrack_limit` setting |
| `recursion_limit` | int | The `pcre.recursion_limit` setting |

### Validation: `validation`

The verdict on a pattern, in `analyze`, `debug`, a lint issue and the error
envelope. It is the JSON form of `ValidationResult`: `json_encode()` of one
gives these keys, in this order.

| key | type | meaning |
|---|---|---|
| `is_valid` | bool | Whether PHP accepts the pattern |
| `error` | string \| null | Why it does not, for people; not frozen |
| `complexity_score` | int | A rough measure of the pattern's complexity |
| `category` | string \| null | `syntax`, `semantic` or `pcre-runtime`; an open set |
| `offset` | int \| null | Where the error lies, 0-based, in bytes from the first byte of the pattern body; `null` without an error or a place |
| `caret_snippet` | string \| null | The pattern with a caret under the error, as the console shows it; the caret counts raw bytes (see [Strings](#strings-are-written-as-they-are)) |
| `hint` | string \| null | How to fix it, for people |
| `error_code` | string \| null | Why it does not, as a stable code such as `regex.group.unclosed`; an open set |

### ReDoS analysis: `redos_analysis`

A ReDoS verdict: `analyze.redos`, `debug.analysis` and the `analysis` of a
lint issue. It is the JSON form of `RedosAnalysis`; the
[ReDoS guide](../REDOS_GUIDE.md#reading-the-result) explains how to read it.

| key | type | meaning |
|---|---|---|
| `severity` | string | `safe`, `low`, `medium`, `high`, `critical` or `unknown`; an open set |
| `score` | int | The severity as a number from 0 to 10 |
| `mode` | string | `off`, `theoretical` or `confirmed`: whether the verdict was replayed on the running PCRE |
| `confirmed` | bool | Whether the replay made PCRE fail |
| `confidence` | string | `low`, `medium` or `high`; an open set |
| `vulnerable_part` | string \| null | The sub-pattern at fault |
| `vulnerable_subpattern` | string \| null | The same, kept for readers of 1.x |
| `trigger` | string \| null | The construct at fault, as `quantifier +` |
| `false_positive_risk` | string \| null | How likely the verdict is wrong, for people |
| `suggested_rewrite` | string \| null | A rewrite to try, for people; verify it |
| `recommendations` | list of strings | Advice, for people |
| `error` | string \| null | Why the analysis could not finish |
| `findings` | list of objects | Each problem found |
| `hotspots` | list of objects | Each span of the pattern at fault |
| `confirmation` | object \| null | The replay in confirmed mode; `null` otherwise |
| `complexity` | string | `linear`, `polynomial`, `exponential` or `unknown`; an open set |
| `degree` | int \| null | The degree of a polynomial verdict, 2 or more; `null` otherwise |
| `proof` | string | `proven`, `heuristic`, `budget_exceeded` or `not_analyzed`; an open set |
| `witness` | object \| null | The attack of a proven vulnerable verdict |
| `replayed` | bool \| null | Whether the witness made the running PCRE fail; `null` when it was not replayed |
| `abstractions` | list of strings | What the model analysed differently from the pattern as written |
| `pcre_version` | string | The PCRE2 release the verdict was computed for, as `10.49` |
| `analysis_version` | string | The version of the model; a verdict is the same for the same release and model version |
| `search_cost` | object \| null | The cost of an unanchored search whose every attempt is proven linear: a [search cost](#search-cost-redos_analysissearch_cost); `null` when no witness was found, which does not prove the search linear |

### Finding: `redos_analysis.findings[]`

| key | type | meaning |
|---|---|---|
| `severity` | string | As in the analysis |
| `message` | string | The problem, for people |
| `pattern` | string | The sub-pattern at fault |
| `trigger` | string \| null | The construct at fault |
| `suggested_rewrite` | string \| null | A rewrite to try; verify it |
| `confidence` | string | As in the analysis |
| `false_positive_risk` | string \| null | How likely the finding is wrong, for people |

### Hotspot: `redos_analysis.hotspots[]`

| key | type | meaning |
|---|---|---|
| `start` | int | Where the span starts, 0-based, in bytes from the first byte of the pattern body |
| `end` | int | Where it ends, exclusive |
| `severity` | string | As in the analysis |
| `pattern` | string | The sub-pattern in the span |
| `trigger` | string \| null | The construct at fault |

### Witness: `redos_analysis.witness`

The attack is the prefix, then the pump repeated, then the suffix. Each part
is written as the body of a PHP double-quoted string, so
`"<prefix>" . str_repeat("<pump>", $n) . "<suffix>"` builds it.

| key | type | meaning |
|---|---|---|
| `prefix` | string | What comes before the repeated part |
| `pump` | string | The part repeated to grow the attack |
| `suffix` | string | What comes after, to make the match fail |

### Search cost: `redos_analysis.search_cost`

One attempt is proven linear, but an unanchored search starts one at each
position of a run, and each reads to the end of the run before it fails: on the
run repeated n times then the breaker, PCRE2's interpreter takes a number of
steps quadratic in n. `preg_match_all()`, `preg_replace()` and `preg_split()`
retry the same way. It is looked for only when one attempt is proven linear: a
worse per-attempt verdict already covers the search. The JIT may avoid the cost
for some patterns; it is not measured, as the analysis never runs a pattern
under the JIT.

| key | type | meaning |
|---|---|---|
| `degree` | int | The degree of the search's cost in the length of the run: `2` |
| `witness` | object | The attack: a [search witness](#search-witness-redos_analysissearch_costwitness) |
| `replayed` | bool \| null | Whether the step replay without the JIT found attempts that take more steps the further they start from the end of the run; `null` when no replay was made (theoretical mode, or a confirmed analysis whose threshold is above `medium`) |

### Search witness: `redos_analysis.search_cost.witness`

Each part is written as the body of a PHP double-quoted string, like the
[witness](#witness-redos_analysiswitness): `"<prefix>" . str_repeat("<run>", $n) . "<breaker>"`
builds the attack.

| key | type | meaning |
|---|---|---|
| `prefix` | string | What comes before the run, often empty: the first attempt fails on it where it would match the bare run (`"!"` for `/^\s+\|\s+$/`) |
| `run` | string | The part repeated: every attempt started in it reads to its end |
| `breaker` | string | What comes after the run and fails every attempt; it holds the last code unit PCRE2 requires, read as its compiler reads it, when the run does not, as PCRE2 gives up at once on a subject without it (`">"` for `/\s*=>/`), with a character after it when an alternative would match it at the end (`"!b!"` for `/a+b\|b$/`) |

### Confirmation: `redos_analysis.confirmation`

| key | type | meaning |
|---|---|---|
| `confirmed` | bool | Whether a replay made PCRE fail |
| `samples` | list of objects | Each subject tried |
| `jit_setting` | bool \| null | The `pcre.jit` setting the replay ran under: the setting, not JIT availability; `false` today, as the replay runs without JIT |
| `backtrack_limit` | int \| null | The backtrack limit the replay ran under |
| `recursion_limit` | int \| null | The recursion limit the replay ran under |
| `iterations` | int | The runs per subject |
| `timeout_ms` | float | The time budget of the replay, in milliseconds |
| `timed_out` | bool | Whether the replay ran out of time |
| `evidence` | string \| null | What the replay showed, for people |
| `note` | string \| null | How the replay was made, for people |
| `error` | string \| null | Why the replay could not run |

### Confirmation sample: `redos_analysis.confirmation.samples[]`

| key | type | meaning |
|---|---|---|
| `input_length` | int | The length of the subject, in bytes |
| `duration_ms` | float | How long the match took, in milliseconds |
| `input_preview` | string \| null | The subject, shortened for display |
| `preg_error_code` | int \| null | `preg_last_error()` after the match |
| `preg_error` | string \| null | `preg_last_error_msg()` after the match |

## Baseline File

`regex lint --generate-baseline=<file>` writes the issues of the run to
`<file>`: one JSON document, its `issues` sorted like the report, ending with
a newline. `--baseline=<file>` then hides the issues it lists. An issue
matches an entry by `issue_id`, `file` and `pattern_hash`, so a pattern that
moves down its file or a reworded message does not bring it back; `line` only
decides between entries that share all three, and an issue's `target` plays
no part. One entry hides one issue. A run with `--baseline` still reports
`column` and `file_offset` for the issues left.

A 1.x baseline, a plain list of `{file, line, message}` entries, is still
read, matched by `file`, `line` and `message`, with a note on stderr (on
stdout with the console report) suggesting to generate it again. A baseline
that is missing or holds neither form is a usage error.

```json
{
    "version": 1,
    "issues": [
        {
            "file": "src/Example.php",
            "line": 3,
            "column": 12,
            "issue_id": "regex.group.unclosed",
            "message": "Expected ) at end of input (found eof)",
            "severity": "error",
            "pattern": "/(a/",
            "pattern_hash": "280856e984a6cedd308bdb41caaa4672"
        }
    ]
}
```

### Baseline file: `baseline`

| key | type | meaning |
|---|---|---|
| `version` | int | The format of the file, `1`; every 2.x reads a file of version `1` |
| `issues` | list | One entry per issue of the run, the entries below |

### Baseline entry: `baseline.issues[]`

| key | type | meaning |
|---|---|---|
| `file` | string | As in the issue (see [Paths](#paths)); what a baseline matches on |
| `line` | int | As in the issue; decides between entries that match the same issues |
| `column` | int \| null | As in the issue |
| `issue_id` | string | As in the issue, or `""` for an issue without one; what a baseline matches on |
| `message` | string | As in the issue, for people |
| `severity` | string | As in the issue |
| `pattern` | string | The pattern of the issue's result, for people |
| `pattern_hash` | string | A hash of the exact bytes of the pattern; what a baseline matches on |

---

Previous: [CLI Guide](../guides/cli.md) | Next: [Reference Index](README.md)
