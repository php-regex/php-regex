CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `regex.redos.search` at the calls that look for every match also covers
   short matches, as `/a*b|a/` on a run of `a`, whose attempt reads to the
   end of the run before each one; the tip then starts with `Quadratic
   search over short matches`.
 * `regex.redos.search` is reported at a nette/utils `Strings::matchAll()`
   whose `lazy` is `true`, by its value or its type, for the cost of the
   loop it runs itself through `preg_match()`, with no retry after an empty
   match: `/(?:a+\d)?/` is reported there, `/(?:a+\d)??/` is not. A `lazy`
   that may be `true` or `false` names neither loop, and stays unreported.
 * `regex.redos.search`, with its message `Quadratic search (ReDoS): %s`,
   also covers the calls of `preg_match_all()`, `preg_replace()`,
   `preg_filter()`, `preg_replace_callback()`, `preg_replace_callback_array()`
   and `preg_split()`, their `Safe\` drop-ins and the composer/pcre,
   nette/utils, spatie/regex and Laravel `Str` methods that call them, on a
   pattern whose search costs them n² through PHP's
   retry after an empty match; the tip names the function. A limit whose
   type bounds the call keeps it quiet. Severity `medium`: hidden at the
   default `high` threshold, shown from `threshold: medium`, where a baseline
   gains these entries.
 * `regex.target.empty` and `regex.target.invalid` quote the marker text in
   display form: a control character or a line break is spelled out, and a
   `<` that would open a console tag is `\x3C`.
 * `checks.redos.threshold` is `high` when left unset, as everywhere else,
   where it was `critical`.
 * The extension registers its pattern rules only: `phpRegex.noNewMixed`,
   `NoNewMixedRule` and `MixedTypeCollector`, the library's check on its
   own declared types, are gone from the package.
 * The `Read more:` tip of every lint error links to the docs on
   php-regex.com, for every rule, where it linked to the GitHub copy of the
   docs and to 45 rules only.
 * `phpRegex.locale` names the `LC_CTYPE` locale the patterns run under,
   the one of the PHP running PHPStan by default; every version of the
   range and every version a guard or a marker keeps is judged under it.
 * What the running PCRE2 answers about Unicode classes is kept under
   PHPStan's `tmpDir`, in `php-regex/`, for the next run and the other
   workers: the extension's `bootstrap.php`, listed in its `bootstrapFiles`,
   sets it once in each PHPStan process, and building a rule sets nothing
   process-wide.
 * `@regex-target` narrows the versions a call is judged for;
   `regex.target.empty` and `regex.target.invalid` report the markers that
   keep no version or cannot be read.
 * Under a `phpVersion` range, a call is judged for the versions a guard on
   `PHP_VERSION_ID` leaves it to run on, as PHPStan narrows the constant.
 * A PHP below 7.3, which ships PCRE1, is judged as `regex lint` judges it:
   refused when `phpRegex.phpVersion` or PHPStan's `phpVersion` names it,
   judged from the lowest PHP on PCRE2 of a range or of the `composer.json`
   PHPStan read it from.
 * PHPStan's `phpVersion: {min, max}` is read whole: each later PHP up to
   `max` where a rule changes validates the patterns too, reported as
   `regex.invalidForTarget`.
 * `phpRegex.phpVersion` takes a range: a Composer constraint (`'>=8.1'`,
   `'^8.1 <8.7'`) or `{min, max}`, each bound a quoted version or a
   `PHP_VERSION_ID`. It is judged at the versions `regex lint` judges, kept
   within PHPStan's `phpVersion` when that is `{min, max}` too, and a guard
   on `PHP_VERSION_ID` narrows it; only a version that refuses a pattern is
   reported. A range the extension refuses, or two ranges sharing no
   version, stop PHPStan while it loads the extension's bootstrap file,
   before any file is analysed. A single version still overrides PHPStan's
   range, and `composer.json`'s `require.php` is not read for it.
 * PHPStan builds `RegexPatternRule` and `RegexPatternArgumentRule` from
   `extension.neon`: their constructors are `@internal`, and the rules are
   configured through `phpRegex`.
 * `RegexPatternRule` exports `ConfigArray`, the shape of the `phpRegex`
   parameter, which both rules take as their `$config`.
 * `RegexPatternArgumentRule` checks the constant pattern passed to a
   parameter marked `#[PHPRegex\Parser\Attribute\RegexPattern]` or
   `#[JetBrains\PhpStorm\Language('RegExp')]`, in calls to functions, static
   and instance methods and constructors: `regexp.pattern` for a pattern the
   running engine refuses, `regex.invalidForTarget`, then lint and ReDoS as
   for `preg_*()` calls. On a PHPStan older than 2.1.31 that offers no
   parameter attributes, no parameter is read.
 * `regex.redos.search`, `Quadratic search (ReDoS): <pattern>`: the quadratic
   cost of an unanchored search whose every attempt is proven linear, under the
   ReDoS setting, from `threshold: medium`.
 * `regex.replacement.undefinedGroup`, always reported: a constant replacement
   of `preg_replace()` or `preg_filter()` refers to a group the pattern does
   not have, or names a group (`${name}`), which PHP never substitutes. A
   pattern and a replacement that both vary are not paired value by value: a
   reference is reported only when no possible pattern defines its group.
 * The static methods of every preset `regex lint` reads are judged by
   default; `phpRegex.extraction.interop` takes the preset names of
   `regex.json`'s `extraction.interop` to read only some, and refuses an unknown one, naming
   the preset it most likely means, as the other bridges do. A named argument is read under the name the library
   gives its pattern parameter.
 * A named argument is matched with its case, as PHP does: `PATTERN:` names
   no `$pattern`.
