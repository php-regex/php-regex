---
description: "For contributors: add a lint rule to PHPRegex, from the rule class and its registration to its configuration key, its PHPStan identifier, its documentation and its engine-first tests."
---
# Adding a Lint Rule

This page is for contributors to the library. Lint rules are not an extension
point: the rule contract and its registry are internal, and a project that
uses PHPRegex cannot add a rule of its own (see
[Backward Compatibility](reference/backward-compatibility.md#using-the-api-call-extend-implement)).
To check patterns for your own purpose, write a visitor, as the
[extending guide](extending.md#pattern-1-analysis-visitor) shows.

The steps below follow what one rule touched when it was added:
`regex.lint.lookaround.redundant`, the rule that reports `(?=a(?=b))`, which
asserts what `(?=ab)` asserts. One commit added its class, registered it,
gave it an entry in the rule catalogue, a reference entry and its tests.

The linter's internal classes may move between releases. The steps stay the
same, and each step names the test that fails until it is done: when a class
named here has moved, that test points to where the list lives now.

---

## Step 0: Decide What the Rule Reports

- **The id.** A rule id reads `regex.lint.<area>.<name>`, as
  `regex.lint.lookaround.redundant`. The id is public once released: it
  names the finding in the JSON report of `regex lint`, it is the PHPStan
  error identifier users ignore errors by, and the LSP diagnostic code. Pick
  it with care.
- **The configuration key.** The key in `regex.json` is the id without
  `regex.lint.`: `"lookaround.redundant": true` under `checks.lint.rules`.
- **On or off by default.** A rule that finds a defect runs by default. A
  rule about style or speed (`LintSeverity::Style` or `LintSeverity::Perf`)
  is off by default, and a project turns it on.
- **The engine decides.** A rule that says two spellings match the same thing
  must be checked against PCRE: run both through `preg_match_all()` on a list
  of subjects before you write the rule, as in [Step 6](#step-6-write-the-tests-engine-first).

---

## Step 1: Write the Rule Class

**Location:** `src/Linter/Rule/ZeroQuantifierRule.php`

A rule is a `final` class in `src/Linter/Rule/` that extends
`AbstractLintRule` and says `@internal`. The smallest rules look like this
one, as shipped:

```php
<?php

declare(strict_types=1);

namespace PHPRegex\Linter\Rule;

use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;

/**
 * Detects {0} quantifiers, which always repeat zero times.
 *
 * @internal
 */
final class ZeroQuantifierRule extends AbstractLintRule
{
    public const ID = 'regex.lint.quantifier.zero';

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [QuantifierNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof QuantifierNode) {
            return [];
        }

        if (!str_starts_with($node->quantifier, '{')) {
            return [];
        }

        [$min, $max] = QuantifierMath::parseRange($node->quantifier);
        if (0 !== $min || 0 !== $max) {
            return [];
        }

        return [new RuleViolation(
            self::ID,
            'Quantifier always repeats zero times; it can be removed.',
            $node->startPosition,
            'Remove the quantified element or replace it with an empty pattern.',
            end: $node->getEndPosition(),
        )];
    }
}
```

- `ID` is the id, as a public constant: the rule reports it through
  `self::ID`, and the rule catalogue names the rule by it. A class that
  reports a few ids whose findings belong together gives each one a constant
  of its own, `ID_` and a name, as `ImpossibleAnchorRule::ID_START`.
  `RuleCatalogTest` fails when an id a rule reports is not one of its
  constants.
- `getRuleIds()` lists every id the rule reports.
- `getNodeTypes()` lists the node classes the rule inspects. The linter walks
  the tree once and calls `check()` on each node of those classes.
- `check()` returns a list of `RuleViolation`: the id, the message, the byte
  offset where the finding starts in the pattern body, a hint, the severity
  (`LintSeverity::Warning` when none is given) and `end:`, where the finding
  ends.
- `begin()` resets what a rule keeps between nodes, and `finish()` reports
  what the rule could only know once it has seen the whole pattern.
  `AbstractLintRule` gives both an empty body.

`RedundantLookaroundRule.php` shows the parts a newer rule adds:
`LintSeverity::Style` for a style rule, a message that
quotes the pattern and a hint that names the rewrite:
`Write "(?=ab)".`

---

## Step 2: Register It

Add the rule at the end of the constructor of `LintRuleRegistry`
(`src/Linter/Rule/LintRuleRegistry.php`):

```php
$this->register(new RedundantLookaroundRule());
```

The rules run in the order they are registered, and their findings come out
in that order, so a new rule goes last.

`LintRuleRegistryTest` fails until it lists the new id in
`test_default_registry_order_is_stable()` and the new count in
`test_all_N_rule_ids_are_covered()`, whose name carries the count.

---

## Step 3: Add It to the Rule Catalogue

`RuleCatalog` (`src/Linter/Rule/RuleCatalog.php`) holds, for each rule, what
it reports, whether it runs by default and the section of the docs that
explains it. Add the rule to `LINT_RULES`, last:

```php
RedundantLookaroundRule::ID => ['Report a lookaround at the edge of a lookaround read the same way, which can hold its body (style).', false, 'https://php-regex.com/reference/rules/#lookaround-inside-a-lookaround'],
```

Everything else reads the catalogue:

- the `checks.lint.rules` key of `regex.json`, the id without `regex.lint.`
  (`"lookaround.redundant": true`; the id in full is accepted too), with its
  description and its default, and the list of the rules off by default in
  the description of `checks.lint.rules`;
- `--enable-rule` and `--disable-rule`, which refuse an id the catalogue
  does not hold, and the inline markers, which read the short id;
- the link of each finding: `docs_url` in the JSON report, the last line of
  the GitHub and Checkstyle messages, the `codeDescription` of the editor's
  diagnostic and the "Read more" tip of the PHPStan error. The PHPStan rule
  reports each finding with the rule id as its error identifier.

Then:

1. Write `src/Linter/regex.schema.json` again from the definition:
   `php tests/Tools/write_config_schema.php`.
2. For a rule off by default, add its key to the list in the
   `checks.lint.rules.<id>` row of `src/Linter/README.md`.

The tests that fail until this is done:

- `RuleCatalogTest`: the catalogue lists exactly the rules the registry
  runs, and each links to the docs site;
- `RuleDocsAnchorsTest`: the link lands on a heading of the docs whose
  section names the id (write the section of Step 5 first);
- `LintRuleConfigurationTest`: the schema file lists the rule, and
  `provideNewRuleDefaults()` holds a row for the new key;
- `LintRuleInventoryTest`: the Linter README names every rule off by default;
- `LintConfigSchemaTest::test_schema_file_is_written_from_the_php_definition`.

---

## Step 4: Check the Editor and PHPStan Output

Nothing to add: the language server and the PHPStan rule read the id and
the link from the catalogue. Run the PHPStan tests of the rule's area, whose
expected tips end with the new `Read more:` line.

---

## Step 5: Document It

- **`docs/reference/rules.md`**: a section under the rule's area, with its
  heading (the one the catalogue links to), then **Identifier:** (with
  "off by default" when it is), **When it triggers:**, **Message:**,
  **Example:** and **Fix:**, and a row in the Quick Reference Table.
  `SonarParityReferenceTest` checks the rows of the rules mapped to a
  SonarPHP rule.
- **`docs/guides/lsp.md`**: a row in the table of diagnostic codes, with the
  LSP severity its `LintSeverity` maps to.
- **`docs/reference/diagnostics-cheatsheet.md`**: a quick fix, when the
  finding is one people will often meet.
- **The rule count**: `src/Linter/README.md`, `src/Cli/README.md` and
  `src/PHPStan/README.md` each give the number of lint rules.
  `LintRuleInventoryTest::test_a_page_counts_the_rules_the_registry_holds`
  fails until each says the new number.
- **The changelogs**: one line in `CHANGELOG.md`, and an entry in
  `src/Linter/CHANGELOG.md`. For a rule off by default, the entry says so,
  and the list "The rules off by default are ..." names it:
  `LintRuleInventoryTest::test_the_linter_changelog_names_each_rule_off_by_default`.

---

## Step 6: Write the Tests, Engine First

Write the test before the rule. The test suite's conventions: a `final` class,
each test marked `#[Test]` and named `test_{feature}_{scenario}`, a static
data provider that returns `iterable` and yields keyed rows, and the engine
asked first, in the test itself.

**Location:** `tests/Unit/Lint/Rule/RedundantLookaroundTest.php`

The rule says the pattern and its rewrite match the same thing. Each reported
row checks that claim against PCRE before it checks the rule, and each silent
row checks that the rewrite would change the matches:

```php
<?php

declare(strict_types=1);

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedundantLookaroundTest extends TestCase
{
    private const RULE = 'regex.lint.lookaround.redundant';

    private const SUBJECTS = ['', 'a', 'b', 'ab', 'abc', 'ba', 'aab', 'xab'];

    #[Test]
    #[DataProvider('provideInlinableLookarounds')]
    public function test_lookaround_its_parent_can_hold_is_reported(string $pattern, string $rewrite, string $hint): void
    {
        // The engine first: the rewrite the tip names finds the same matches.
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(
                [preg_match_all($rewrite, $subject, $expected, \PREG_OFFSET_CAPTURE), $expected],
                [preg_match_all($pattern, $subject, $actual, \PREG_OFFSET_CAPTURE), $actual],
                $pattern.' on '.$subject,
            );
        }

        $issues = self::issues($pattern, true);

        $this->assertCount(1, $issues);
        $this->assertSame($hint, $issues[0]->hint);
        $this->assertSame(LintSeverity::Style, $issues[0]->severity);
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, hint: string}>
     */
    public static function provideInlinableLookarounds(): iterable
    {
        yield 'a lookahead ending a lookahead' => ['pattern' => '/(?=a(?=b))/', 'rewrite' => '/(?=ab)/', 'hint' => 'Write "(?=ab)".'];
        yield 'a lookbehind starting a lookbehind' => ['pattern' => '/(?<=(?<=a)b)/', 'rewrite' => '/(?<=ab)/', 'hint' => 'Write "(?<=ab)".'];
    }

    #[Test]
    #[DataProvider('provideLookaroundsThatMatter')]
    public function test_lookaround_that_changes_the_assertion_is_not_reported(string $pattern, string $rewrite): void
    {
        // The engine first: the rewrite would find other matches.
        $differs = false;
        foreach (self::SUBJECTS as $subject) {
            $differs = $differs
                || [preg_match_all($pattern, $subject, $actual, \PREG_OFFSET_CAPTURE), $actual]
                !== [preg_match_all($rewrite, $subject, $expected, \PREG_OFFSET_CAPTURE), $expected];
        }
        $this->assertTrue($differs, $pattern.' behaves as '.$rewrite);

        $this->assertSame([], self::issues($pattern, true));
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string}>
     */
    public static function provideLookaroundsThatMatter(): iterable
    {
        yield 'a negative lookahead inside' => ['pattern' => '/(?=a(?!b))/', 'rewrite' => '/(?=a[^b])/'];
        yield 'a lookahead starting a lookahead' => ['pattern' => '/(?=(?=b)a)/', 'rewrite' => '/(?=ba)/'];
    }

    #[Test]
    public function test_lookaround_rule_is_off_by_default(): void
    {
        $this->assertSame([], self::issues('/(?=a(?=b))/', false));
    }

    /**
     * @return list<RuleViolation>
     */
    private static function issues(string $pattern, bool $enabled): array
    {
        $linter = new PatternLinter($enabled ? ['lookaround.redundant' => true] : []);
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_values(array_filter(
            $linter->getIssues(),
            static fn (RuleViolation $issue): bool => self::RULE === $issue->id,
        ));
    }
}
```

The shipped test holds more rows: each place the rule must stay silent, each
case its message covers, and a subject list long enough to tell the rewrite
from the pattern.

`LintRuleExamplesTest` also wants, for each rule id, one pattern the rule
reports and one sound pattern it leaves alone, in its `EXAMPLES` list; a rule
off by default goes in its `ENABLE_ALL` list too. Its
`test_every_registered_rule_has_an_example()` fails until then.

---

## Checklist

What `regex.lint.lookaround.redundant` touched, with what fails when a place
is missed:

| Where | What | What fails when it is missed |
|---|---|---|
| `src/Linter/Rule/RedundantLookaroundRule.php` | the rule and its `ID` | the rule's own test, `RuleCatalogTest` |
| `src/Linter/Rule/LintRuleRegistry.php` | the registration | `LintRuleRegistryTest`, `LintRuleExamplesTest` |
| `src/Linter/Rule/RuleCatalog.php` | the description, the default, the docs link | `RuleCatalogTest`, `LintRuleConfigurationTest` |
| `src/Linter/regex.schema.json` | written from the definition | `LintConfigSchemaTest` |
| `docs/reference/rules.md` | the section and the Quick Reference row | `RuleDocsAnchorsTest` for the heading the link names |
| `docs/guides/lsp.md` | the row of diagnostic codes | nothing: check it by hand |
| `src/Linter/README.md`, `src/Cli/README.md`, `src/PHPStan/README.md` | the rule count, and the list of rules off by default | `LintRuleInventoryTest` |
| `CHANGELOG.md`, `src/Linter/CHANGELOG.md` | the entries | `LintRuleInventoryTest`, for a rule off by default |
| `tests/Unit/Lint/Rule/RedundantLookaroundTest.php` | the rule's test, engine first | |
| `tests/Unit/Lint/Rule/LintRuleExamplesTest.php`, `LintRuleRegistryTest.php`, `LintRuleConfigurationTest.php` | the rows for the new id | each fails until its row is there |

Run the linter's tests and the documentation's while you work:

```bash
composer phpunit -- tests/Unit/Lint tests/Integration/Bridge/PHPStan tests/Documentation
```
