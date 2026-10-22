# build/ — a PHP 7.4 twin of src/Parser

Question: can the parser ship as a PHP 7.4-compatible artifact (the model
phpstan-src consumes through its downgraded better-reflection fork), produced
mechanically and verified against the original?

The generated tree lives in out/ (gitignored); everything here is the
toolchain that produces and verifies it.

## Regenerate

1. `cp -R src/Parser build/php74/Parser`
2. `tools/rector/vendor/bin/rector process build/php74/Parser --config build/rector-downgrade.php --no-progress-bar --no-diffs`
3. `php build/emit-enum-factories.php` — regenerates the 17 enum files as
   cached-instance twins: `GroupType::Capturing()` returns one shared
   instance, so `===`, `switch`, `->value`, `->name`, `instanceof` and the
   native `GroupType` type hints all keep the enum's semantics. Pure enum
   methods (`PcreFeature::release()`, `CharLiteralType::label()`) are ported
   as per-case maps read off the original by reflection.
4. `php build/rewrite-enum-calls.php` — `Enum::Case` becomes `Enum::Case()`
   (1182 sites; `::class`, `::from()` and friends start lowercase and never
   match).
5. `php build/hoist-enum-consts.php` — class constants holding enum
   factories become lazy static methods of the same name, rewritten only in
   the file where each constant lived (11 constants; a name can repeat
   across classes, so a global rewrite collides).
6. Fix the two signatures Rector mangles by hand (`?` without a type in
   FormatResult, `final` instead of the class name in ValidationResult).

## Verify

- Lint: every file must `php -l` clean under `docker run php:7.4-cli`
  (197/197)
- Function: `php build/smoke/digest.php vendor/autoload.php > 84.jsonl`
  (original through this checkout's vendor) versus
  `docker run ... php build/smoke/digest.php build/smoke/vendor/autoload.php
  > 74.jsonl` (twin through polyfills php80/php81/mbstring). The digests must
  match; enum values normalize to their backing string on both sides.

## Findings

- 197/197 lint clean under PHP 7.4.33; 108/108 identical parse statuses
  (refusals included); 106/108 identical AST structures
- Two deltas remain, both on the container's PCRE2 10.35: `\x{1F600}` and
  `\g{-1}` tokenize as literals instead of their escape/backreference nodes.
  The master lexer reads both on 10.49 — version-aware lexer construction or
  engine behavior, not the downgrade transform; a permanent 7.4 lane
  adjudicates this against the engine, and one of them may be a real
  old-target bug on our side
- The cached-instance strategy beats string constants: native enum type
  hints stay (no widening of 36 return types and 3 params), `->value` and
  `->name` reads stay, and the only caller rewrite is the purely syntactic
  `::Case` to `::Case()`
