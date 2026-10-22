# Parser legacy spike — a PHP 7.4 twin of src/Parser

Question: can the parser ship as a PHP 7.4-compatible artifact (the model
phpstan-src consumes through its downgraded better-reflection fork), produced
mechanically and verified against the original?

## Regenerate

1. `cp -R src/Parser spike/out/Parser` (out/ is generated, not committed)
2. `tools/rector/vendor/bin/rector process spike/out/Parser --config spike/rector-downgrade.php --no-progress-bar --no-diffs`
3. `php spike/fix-enum-usages.php` — rewrites enum-typed reads and adds the
   from/tryFrom/cases shims; PcreFeature::release() needs the reflection step
   below when the enum gains cases
4. Reflection-emit `PcreFeature::release()` as a static map (see the digest),
   lowercase its keys, drop Rector's instance-method duplicate
5. Rewrite native `EnumName` param/return types to `string` (36 returns,
   3 params at the time of writing) and fix the two signatures Rector mangles
   (`?` without a type, `final` instead of the class name)

## Verify

- Lint: every file under `docker run php:7.4-cli` must `php -l` clean
  (197/197 at the time of writing)
- Function: `php spike/smoke/digest.php vendor/autoload.php > 84.jsonl`
  (original, via this checkout's vendor) versus
  `docker run ... php spike/smoke/digest.php spike/smoke/vendor/autoload.php > 74.jsonl`
  (twin, polyfills + the downgraded tree); the two jsonl files must match

## Findings so far

- 197/197 lint clean under PHP 7.4.33; 108/108 identical parse statuses
  (including refusals), 106/108 identical AST structures
- Two structural deltas on the container's PCRE2 10.35: `\x{1F600}` and
  `\g{-1}` tokenize as literals instead of their escape/backreference nodes.
  The master lexer handles both on 10.49; the divergence is version-aware
  lexer construction or engine behavior, not the downgrade transform —
  exactly what a permanent 7.4 lane would adjudicate against the engine
