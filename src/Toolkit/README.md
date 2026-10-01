<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.svg?v=1">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.svg?v=1">
        <img src="art/banner.svg?v=1" alt="PHPRegex Toolkit" width="100%">
    </picture>
</p>

PHPRegex Toolkit
================

One entry point to every PHPRegex analysis library: parse, validate, explain, check ReDoS, optimize, generate, transpile and lint.

Requires PHP 8.2+. MIT licensed.

Features
--------

* One facade, `Regex::create()`, over the seven analysis packages it installs: parser, explain, generator, linter, optimizer, redos, transpiler.
* Parse strictly (errors throw) or tolerantly (errors come back in the result), with byte-offset tokens; validation reports the error offset, a caret snippet and a hint.
* Explain a pattern in plain English, as text or HTML; highlight it for console or HTML output.
* Check for ReDoS: theoretical mode by default, or confirmed by a bounded run; four severity levels.
* Optimize with rewrites proven equivalent to the original, generate a sample the running engine has verified to match, transpile to JavaScript or Python.

Installation
------------

```bash
composer require php-regex/regex-toolkit
```

The seven libraries behind the facade come with it; nothing else to install. To drive the same analyses from the terminal, add `php-regex/regex-cli`. Upgrading from `yoeunes/regex-parser` 1.3? Run the Rector set shipped as `Resources/rector/upgrade-2.0.php`.

Configuration
-------------

`Regex::create()` reads an options array. Every key is validated; an unknown key throws.

| Option | Default | What it does |
| --- | --- | --- |
| `max_pattern_length` | `100000` | Longest pattern accepted, in bytes |
| `max_lookbehind_length` | `255` | Longest variable-length lookbehind; a fixed-length one is only limited by PCRE's 65535 |
| `max_recursion_depth` | `1024` | How deep the parser may nest groups before it stops |
| `cache` | `ArrayCache` | A `CacheInterface`; `FilesystemCache` survives restarts |
| `redos_ignored_patterns` | `[]` | Patterns the ReDoS analysis skips |
| `runtime_pcre_validation` | `false` | Compile each pattern with the running PCRE to validate it |
| `php_version`, `pcre_version` | running releases | The PHP (`"8.3"`, `80300`, `"runtime"`) and PCRE2 (`"10.44"`) the patterns are judged for |

Usage
-----

Parse a pattern, then read it as an AST or as an English sentence:

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
$ast = $regex->parse('/\d{3}-\d{4}/'); // RegexNode AST
echo $regex->explain('/\d{3}-\d{4}/'), "\n";
```
```
Regex matches
    Character Type: A digit: [0-9] (exactly 3 times)
  '-'
    Character Type: A digit: [0-9] (exactly 4 times)
```

Validate a pattern before it reaches `preg_match` — the failing branch says where and why:

```php
$result = $regex->validate('/(?<year>\d{4})-(\k<month>/');
echo $result->error, "\n";
echo $result->caretSnippet, "\n";
```
```
Expected ) at end of input (found eof)
Line 1: (?<year>\d{4})-(\k<month>
                                 ^
```

Check a pattern for ReDoS, theoretically by default, or confirmed by a bounded run:

```php
use PHPRegex\Redos\RedosMode;

$analysis = $regex->redos('/(a+)+$/', mode: RedosMode::Confirmed);
echo $analysis->severity->value, "\n";
echo $analysis->isConfirmed() ? "confirmed\n" : "theoretical\n";
```
```
critical
confirmed
```

Rewrite, sample and port a pattern:

```php
echo $regex->optimize('/[0-90-9a-fA-F]+/')->optimized, "\n";
echo $regex->generate('/[a-z]+@[a-z]+\.[a-z]{2,}/'), "\n"; // random sample, one possible output
echo $regex->transpile('/^\d{4}-\d{2}-\d{2}$/', 'javascript')->constructor, "\n";
```
```
/[0-9A-Fa-f]+/
rcu@d.rx            # one possible output (random)
new RegExp("^\\d{4}-\\d{2}-\\d{2}$", "")
```

Or run every check at once — `analyze()` validates, lints, and reports ReDoS and optimizations in one report:

```php
$report = $regex->analyze('/(a+)+b/');
foreach ($report->lintIssues as $issue) {
    echo $issue->id, ': ', $issue->message, "\n";
}
```
```
regex.lint.quantifier.nested: Nested quantifiers can cause catastrophic backtracking.
regex.lint.group.quantifiedCapture: Quantified capturing group "(...)" with "+": only the last iteration's capture is retained.
```

Documentation
-------------

* [Quick start](https://github.com/php-regex/php-regex/blob/2.x/docs/QUICK_START.md) — from installation to a first analysis, PHP API and CLI
* [API reference](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/api.md) — every public class and method
* [ReDoS guide](https://github.com/php-regex/php-regex/blob/2.x/docs/REDOS_GUIDE.md) — risky shapes, detection modes and mitigations
* [Backward compatibility](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md) — the promise every sibling ships under, one version number for all

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Changelog](CHANGELOG.md)
* [Bridges and tools](https://github.com/php-regex/php-regex/blob/2.x/README.md#getting-started) — Laravel, Symfony, CLI, PHPStan and LSP
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).
