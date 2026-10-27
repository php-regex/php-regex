---
description: "Add a new PCRE construct to the parser — node, lexer token, parser rule, visitor method, tests — with the callout syntax (?C) as the worked example."
redirect_from:
  - /EXTENDING_GUIDE.html
---
# Extending PHPRegex

This guide shows how to add new PCRE features, build custom visitors, and integrate PHPRegex into tools.

<div class="page-filter">
  <label class="visually-hidden" for="page-filter-input">Filter the extending guide</label>
  <input type="search" id="page-filter-input" data-unit="sections" placeholder="Filter by section — e.g. visitor, rule, service" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>Nothing matches that filter — try a shorter fragment, or press Escape to see the whole page again.</p>

---

## What You Can Extend

**PHPRegex** is designed for extensibility:

- New AST nodes for new syntax
- New visitors for analysis or transformation
- Framework integrations
- CLI commands

Lint rules are not an extension point in 2.0: the rule contract and its
registry are internal, and there is no public registry to add a rule to (see
[Backward Compatibility](reference/backward-compatibility.md#using-the-api-call-extend-implement)).
To check a pattern for something of your own, write an analysis visitor, as in
Pattern 1 below. A rule that belongs in the library itself is a contribution:
[Adding a Lint Rule](adding-a-lint-rule.md) walks through one.

---

## Extension Architecture

PHPRegex core extension points:
- Nodes (`src/Parser/Node/`) for new node types.
- Visitors (`src/Parser/AbstractNodeVisitor.php` and its subclasses in each package) for new analyses or transforms.
- Parser (`src/Parser/Syntax/TokenParser.php`) to recognize new syntax.
- CLI (`src/Cli/`) for new commands.

---

## Adding a PCRE Construct

This part is for contributors to the library. It walks through a new piece of
PCRE syntax from the engine's answer to the tests, with the callout `(?C)`,
`(?C1)` or `(?C"name")` as the worked example. A snippet from `src/` is the
code the library ships, cut where a comment says so.

A node reaches far: `grep -rln CalloutNode src` lists about forty files in
nine packages. The steps below cover the parser, the tests and the
documentation; the [checklist](#checklist-every-place-a-node-touches) after
them lists every place, package by package, with the test that fails when one
is missed.

---

## Step 0: Ask the Engine First

Before you write down what a pattern means, run it through PCRE. The library
reports what PCRE2 reports, so the engine is the reference, and a test written
from intuition passes for the wrong reason.

```bash
php -r 'var_dump(@preg_match("/(?C1)a/", "a"), preg_last_error_msg());'
# int(1)
# string(8) "No error"

php -r 'var_dump(@preg_match("/(?C256)a/", "a"), error_get_last()["message"]);'
# bool(false)
# string(82) "preg_match(): Compilation failed: number after (?C is greater than 255 at offset 6"
```

`false` means the pattern does not compile. The warning that `@` hides holds
PCRE's message and its offset: the library's error must report the same
offset.

The answer can depend on the PCRE2 release. `php -r 'echo PCRE_VERSION;'`
names yours. Each PHP version bundles one release, and a PHP built against
the system library may run a newer one. The official Docker images answer for
the bundled releases:

```bash
for v in 8.1 8.2 8.3 8.4 8.5; do
  docker run --rm php:$v-cli php -r 'echo PHP_VERSION, " / PCRE2 ", PCRE_VERSION, ": "; var_dump(preg_match("/^a{,3}$/", "aa"));'
done
```

```text
8.1.34 / PCRE2 10.39 2021-10-29: int(0)
8.2.34 / PCRE2 10.40 2022-04-14: int(0)
8.3.35 / PCRE2 10.42 2022-12-12: int(0)
8.4.26 / PCRE2 10.44 2024-06-07: int(1)
8.5.11 / PCRE2 10.44 2024-06-07: int(1)
```

`a{,3}` is text up to PCRE2 10.42 and a repeat from 10.43. Syntax whose
meaning moves like this is gated by release, as Step 4 shows. The patch
versions above are those of one run: the images move, so read the release
each one prints.

---

## Step 1: Add the Node

**Location:** `src/Parser/Node/CalloutNode.php`

```php
<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Node;

use PHPRegex\Parser\NodeVisitorInterface;

final class CalloutNode extends AbstractNode
{
    public function __construct(
        public readonly int|string|null $identifier,
        public readonly bool $isStringIdentifier,
        int $startPosition,
        int $endPosition,
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitCallout($this);
    }
}
```

### Checklist for New Nodes

- [ ] A `final class` in `src/Parser/Node/` that extends `AbstractNode`, its
  name ending in `Node`: the tests find the node classes by that name.
- [ ] Each field is a `public readonly` promoted property. The class itself is
  not `readonly`: the packages run on PHP 8.1, which has no readonly
  classes, and `AbstractNode` is not one.
- [ ] `parent::__construct($startPosition, $endPosition)` keeps the byte
  offsets of the node in the pattern body.
- [ ] `accept()` has no native return type: the `@return T` of
  `NodeInterface::accept()` gives the visitor's (`AcceptReturnTypeContractTest`).
- [ ] A node that holds other nodes returns them from `getChildren()`, in the
  order they stand in the pattern.

---

## Step 2: Lex the New Syntax

The lexer (`src/Parser/Lexer.php`) cuts the pattern into tokens. Each token
has a `TokenType` case (`src/Parser/Token/TokenType.php`). A whole callout,
`(?C...)` up to its `)`, is one `TokenType::Callout` token, and its value is
the text between `(?C` and `)`. A new token needs three things:

- a case in `TokenType`, e.g. `case Callout = 'callout';`;
- a pattern in the lexer's token map (`PATTERNS_OUTSIDE`, or `PATTERNS_INSIDE`
  for a token read inside a character class), keyed `T_` plus the upper-cased
  backing value: `'T_CALLOUT' => '...'`. The lexer turns the key back into the
  case with `TokenType::from(Ascii::lower(substr($tokenName, 2)))`, so a key
  that does not spell a backing value is an error;
- the same key in the matching priority list, `TOKENS_OUTSIDE` or
  `TOKENS_INSIDE`: the lexer reads the token's type from the first key of that
  list whose pattern matched. A key missing from the list is never read, and a
  match of its pattern ends the run with a lexer internal error.

The patterns are tried in the order of the token map, and the first that
matches at the current position wins: a new pattern goes before any pattern
that would match a prefix of its syntax (`T_CALLOUT` sits before
`T_GROUP_MODIFIER_OPEN`, which would match the `(?` of `(?C1)`).

---

## Step 3: Parse the Token

The parser, `src/Parser/Syntax/TokenParser.php`, reads a `TokenStream`
(`src/Parser/Token/TokenStream.php`):

- `current()` returns the token under the cursor, and `advance()` steps over it.
- `previous()` returns the token just stepped over.
- `match(TokenType $type)` steps over the current token when it has that type, and says whether it did.
- `consume(TokenType $type, string $error, ErrorCode $code)` steps over a token of that type, or throws a parser exception with that error code.

An item is dispatched in `parseAtom()`, by the type of the token that opens
it. Each `case` steps over the token and hands it to its reader:

```php
private function parseAtom(): NodeInterface
{
    $token = $this->stream->current();
    $startPosition = $token->position;

    switch ($token->type) {
        // ... the extended class "(?[" and the comment "(?#"
        case TokenType::Callout:
            $this->stream->advance();

            return $this->parseCallout();
        case TokenType::GroupOpen:
            $this->stream->advance();

            return $this->parsePlainGroup($token);
        case TokenType::GroupModifierOpen:
            $this->stream->advance();

            return $this->parseGroupModifier();
        // ... the character class and the verbs
        default:
            if (null !== $node = $this->parseSimpleAtom($startPosition)) {
                return $node;
            }
    }

    // ... the errors for a token that opens no item
}
```

A token of its own gets a `case` here. Syntax that opens with `(?` and has no
token of its own is read by `parseGroupModifier()`; an escape or a single
character, by `parseSimpleAtom()`.

`parseCallout()` builds the node from the token `parseAtom()` stepped over:

```php
private function parseCallout(): CalloutNode
{
    $token = $this->stream->previous();
    $startPosition = $token->position;
    $value = $token->value; // the text between "(?C" and ")"
    $endPosition = $startPosition + \strlen($token->value) + self::CALLOUT_WRAPPER_LENGTH;

    if ('' === $value) {
        return new CalloutNode(null, false, $startPosition, $endPosition);
    }

    if (Ascii::isDigit($value)) {
        return new CalloutNode((int) $value, false, $startPosition, $endPosition);
    }

    // ... a delimited string, or else:
    throw $this->parserException(
        \sprintf('Invalid callout argument: %s at position %d: %s.', $value, $position, self::calloutProblem($code)),
        $code,
        $position,
    );
}
```

Each way the syntax can be wrong has its own `ErrorCode` case
(`src/Parser/ErrorCode.php`), here `CalloutInvalidDelimiter`,
`CalloutOutOfRange`, `CalloutUnclosed` and `CalloutUnclosedString`, and its
row in the error code table of
[Diagnostics](reference/diagnostics.md#error-codes). The exception carries the
offset PCRE reports, the one Step 0 printed.

The parser then returns the node in the tree:

```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/a(?C1)b(?C"tag")/');
// $ast->pattern->children[1]: CalloutNode, identifier 1, isStringIdentifier false, positions 1 to 6
// $ast->pattern->children[3]: CalloutNode, identifier "tag", isStringIdentifier true, positions 7 to 16
```

---

## Step 4: Gate Syntax That Depends on the PCRE2 Release

When the engine answers differently by release, as `a{,3}` does in Step 0,
the code asks the target whether it has the behaviour. It never compares
release numbers itself. Each behaviour is a case of `PcreFeature`
(`src/Parser/PcreFeature.php`):

```php
/**
 * "{,2}" and a count padded with spaces, as "{ 2 }", repeat instead of matching as text.
 */
case OpenAndPaddedRepeatCounts;
```

To add one:

1. Add the case, with a one-line docblock that says what changed, and the
   release it arrived in to `release()`.
2. Add its row to the table in [PCRE](concepts/pcre.md):
   `PcreFeatureTableTest` compares the table with the enum.
3. Add a row to `provideReleases()` in `tests/Unit/PcreFeatureTest.php`, with
   the release before and the release from which the target has it.
4. Read it from the target. The lexer reads each feature once, in its
   constructor:
   `$this->wideRepeatCounts = $target->supports(PcreFeature::OpenAndPaddedRepeatCounts);`.
   The parser and the validator call `$this->supports(PcreFeature::...)`.

`PcreFeatureTest::test_no_rule_names_a_release_by_hand` fails on a
`pcreAtLeast('10.43')` written anywhere in `src/`.

The target is the `pcre_version` and `php_version` options, or the running
engine when neither is given. A test sets `pcre_version` to judge a release
other than the one it runs on:

```php
use PHPRegex\Parser\RegexParser;

RegexParser::create(['pcre_version' => '10.42'])->parse('/a{,3}/')->pattern; // SequenceNode: the text "a{,3}"
RegexParser::create(['pcre_version' => '10.43'])->parse('/a{,3}/')->pattern; // QuantifierNode
```

---

## Step 5: Teach the Visitors the Node

### Add Method to NodeVisitorInterface

**Location:** `src/Parser/NodeVisitorInterface.php`

```php
/**
 * @return TReturn
 */
public function visitCallout(CalloutNode $node);
```

### Add Default to AbstractNodeVisitor

**Location:** `src/Parser/AbstractNodeVisitor.php`

```php
public function visitCallout(CalloutNode $node)
{
    return $this->defaultReturn();
}
```

### Add Descent to AbstractTraversingVisitor

**Location:** `src/Parser/AbstractTraversingVisitor.php`

The method visits the node's children through `getChildren()`, so a visitor
that extends this base still reaches the nodes below the new one:

```php
public function visitCallout(CalloutNode $node)
{
    return $this->traverse($node);
}
```

### Implement It in Each Visitor

Every visitor of the library that returns a value needs its own method: the
default returns `null`, and a visitor typed to return a string or an array
fails with a `TypeError` on the new node. The
[checklist](#checklist-every-place-a-node-touches) names each one. The
printer's method looks like this:

```php
public function visitCallout(CalloutNode $node): string
{
    if (null === $node->identifier) {
        return '(?C)';
    }

    return $node->isStringIdentifier
        ? '(?C"'.$node->identifier.'")'
        : '(?C'.$node->identifier.')';
}
```

This sketch does not double a `"` inside the string, as `(?C"a""b")` needs.
`PatternPrinter::visitCallout()` is the full version.

---

## Step 6: Cache Version, Export and Schema

- **Cache version.** `RegexParser::CACHE_VERSION` is a fingerprint of the code
  that builds a tree, so that a tree cached by older code is never read back.
  Run `task cache-version` (or `composer cache-version`) and commit
  `src/Parser/RegexParser.php`. A new file the parser reads goes in the
  sources of `tests/Support/AstFingerprint.php` and of the `cache-version`
  task in `taskfile.dist.yaml`. See
  [Changing what the parser builds](https://github.com/php-regex/php-regex/blob/2.x/CONTRIBUTING.md#changing-what-the-parser-builds).
- **Cache reader.** `AstSerializer::NODE_CLASSES`
  (`src/Parser/Cache/AstSerializer.php`) lists the classes a cached tree may
  hold. A class missing from it is never read back.
- **JSON export.** `JsonDumper` (`src/Parser/Printer/JsonDumper.php`) needs
  its own `visitCallout()`, and `src/Parser/ast.schema.json` a definition
  under `$defs` plus a `$ref` to it in the list of nodes. A new kind of node
  adds to the format and leaves `JsonDumper::VERSION` as it is: the version
  goes up only when a field is renamed, removed or given another meaning.

---

## Step 7: Write the Tests, Engine First

Write the test before the code, and check what the engine does before you
check what the library does. The test suite's conventions:

- a `final` class that extends `TestCase`;
- each test marked `#[Test]`, named `test_{feature}_{scenario}`;
- a static data provider that returns `iterable` and yields keyed rows, with
  the row's name as its key;
- the engine asked first, in the test itself, so the test fails when the
  engine stops agreeing with it.

**Location:** `tests/Unit/Parser/CalloutTest.php`

```php
<?php

declare(strict_types=1);

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CalloutTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCallouts')]
    public function test_callout_is_read_with_its_identifier(string $pattern, int|string|null $identifier, bool $isString): void
    {
        // The engine first: the running PCRE2 compiles the pattern.
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern.' must compile.');

        $node = RegexParser::create(['cache' => null])->parse($pattern)->pattern;

        $this->assertInstanceOf(CalloutNode::class, $node);
        $this->assertSame($identifier, $node->identifier);
        $this->assertSame($isString, $node->isStringIdentifier);
    }

    /**
     * @return iterable<string, array{pattern: string, identifier: int|string|null, isString: bool}>
     */
    public static function provideCallouts(): iterable
    {
        yield 'no argument' => ['pattern' => '/(?C)/', 'identifier' => null, 'isString' => false];
        yield 'a number' => ['pattern' => '/(?C255)/', 'identifier' => 255, 'isString' => false];
        yield 'a string' => ['pattern' => '/(?C"tag")/', 'identifier' => 'tag', 'isString' => true];
        yield 'a doubled delimiter' => ['pattern' => '/(?C"a""b")/', 'identifier' => 'a"b', 'isString' => true];
    }

    #[Test]
    public function test_callout_number_past_255_is_refused_where_pcre_refuses_it(): void
    {
        // PCRE2: "number after (?C is greater than 255 at offset 6".
        $this->assertFalse(@preg_match('/(?C256)/', ''));

        $result = RegexParser::create(['cache' => null])->validate('/(?C256)/');

        $this->assertFalse($result->isValid);
        $this->assertSame(ErrorCode::CalloutOutOfRange, $result->errorCode);
        $this->assertSame(6, $result->offset);
    }

    #[Test]
    #[DataProvider('provideOpenRepeatCounts')]
    public function test_open_repeat_count_follows_the_release(string $release, bool $repeats): void
    {
        // The engine first, on whichever side of PCRE2 10.43 it runs.
        $this->assertSame(
            PcreTarget::runtime()->supports(PcreFeature::OpenAndPaddedRepeatCounts) ? 1 : 0,
            preg_match('/^a{,3}$/', 'aa'),
        );

        $node = RegexParser::create(['cache' => null, 'pcre_version' => $release])->parse('/a{,3}/')->pattern;

        $this->assertSame($repeats, $node instanceof QuantifierNode);
    }

    /**
     * @return iterable<string, array{release: string, repeats: bool}>
     */
    public static function provideOpenRepeatCounts(): iterable
    {
        yield 'text before 10.43' => ['release' => '10.42', 'repeats' => false];
        yield 'a repeat from 10.43' => ['release' => '10.43', 'repeats' => true];
    }
}
```

The last test holds on every engine: it asks the running one, then judges
both releases through `pcre_version`. A row that only holds from one release
on uses the `PcreGates` trait (`tests/Support/PcreGates.php`):
`$this->requiresPcre2('10.43', $pattern)` skips it on an older engine, with a
message that says why.

Cover the edge cases too: the empty argument, the largest and the first
refused value, every delimiter, the syntax inside a class and under `x`, and
each error with its offset.

---

## Step 8: Update the Documentation

1. **[AST Nodes](nodes/README.md)**: the node, its fields and an example.
2. **[Visitor Reference](visitors/README.md)**: the visit method.
3. **[Feature support](reference/feature-support-matrix.md)**: the construct, and what each tool does with it.
4. **CHANGELOG**: one line in `CHANGELOG.md`, and one in the changelog of each
   package you changed (`src/Parser/CHANGELOG.md` and so on).

---

## Checklist: Every Place a Node Touches

These are the places `CalloutNode` touches today, by package. A visitor whose
method is missing fails in `VisitorExhaustivenessTest`, which runs every
visitor whose constructor takes no argument over an instance of every node.
For that, the new node needs a pattern in `provide_node_covering_patterns()`
and an instance in `test_every_visitor_handles_synthetic_instances_of_all_node_types()`.
The places marked "read it" are not visitors: they test the node's class
(`instanceof`), and no test names them, so read each one and decide whether
the new node belongs there.

### Parser (`php-regex/regex-parser`)

| Where | What to add | What fails when it is missed |
|---|---|---|
| `Node/CalloutNode.php` | the node | `AcceptReturnTypeContractTest` for a native return type on `accept()` |
| `Token/TokenType.php`, `Lexer.php` | the token: case, pattern, priority | your parser tests: the syntax is read as something else, or the lexer stops with an internal error |
| `Syntax/TokenParser.php` | the `case` in `parseAtom()`, the reader, in the reader of its family (`GroupParser`, `VerbParser`, ...) when it has one | your parser tests |
| `ErrorCode.php` | one case per way the syntax can be wrong | `ErrorCodeDocumentationTest`, until `docs/reference/diagnostics.md` lists it |
| `PcreFeature.php` | a case for each behaviour that changed by release | `PcreFeatureTableTest`, until `docs/concepts/pcre.md` lists it |
| `NodeVisitorInterface.php`, `AbstractNodeVisitor.php` | the visit method and its default | every visitor of the library, with a PHP error |
| `AbstractTraversingVisitor.php` | the descent | `AbstractTraversingVisitorTest`, which also wants an instance of the node in `everyNodeClass()` |
| `Validation/Validator.php` | the checks PCRE makes when it compiles | `VisitorExhaustivenessTest`; your tests against the engine |
| `Validation/CompiledLength.php` | what the item costs in the compiled pattern, for "pattern too large" | read it |
| `Analysis/LengthRangeCalculator.php`, `ComplexityScorer.php`, `LiteralExtractor.php`, `MetricsCollector.php` | the item's length, score, literals and counts | `VisitorExhaustivenessTest` |
| `Analysis/CaptureFacts.php` | what the item does to captures | read it |
| `Hir/HirTranslator.php` | the normalized form: an item the solver cannot read becomes an `OpaqueHir` | read it |
| `Printer/PatternPrinter.php`, `Printer/NodeDumper.php` | printing the pattern back, dumping the tree | `VisitorExhaustivenessTest` |
| `Printer/JsonDumper.php`, `ast.schema.json` | the export and its schema | `JsonDumperTest`, which also wants a row in `provideNodes()` |
| `Cache/AstSerializer.php` | the class in `NODE_CLASSES` | `AstSerializerTest::test_every_node_class_may_be_read_back` |
| `RegexParser.php` | `CACHE_VERSION`, written by `task cache-version` | `AstSerializerTest::test_the_cache_version_is_the_fingerprint_of_the_code_that_builds_a_tree` |

### Explain (`php-regex/regex-explain`)

| Where | What to add | What fails when it is missed |
|---|---|---|
| `TextExplainer.php`, `HtmlExplainer.php` | the explanation | `VisitorExhaustivenessTest` |
| `AsciiTreeRenderer.php`, `MermaidRenderer.php`, `RailroadLayoutVisitor.php` | the diagram | `VisitorExhaustivenessTest` |
| `Highlighter/AbstractHighlighter.php` | the highlighting, for the console and HTML highlighters | `VisitorExhaustivenessTest` |

### Optimizer, Generator and Transpiler

| Where | What to add | What fails when it is missed |
|---|---|---|
| `Optimizer/Rewriter.php`, `Optimizer/Modernizer.php` | the node, returned as it is unless a rewrite holds | `VisitorExhaustivenessTest` |
| `Generator/SampleGenerator.php`, `Generator/TestCaseGenerator.php` | what the item adds to a sample | `VisitorExhaustivenessTest` |
| `Transpiler/Target/JavaScript/JavaScriptPrinter.php`, `Transpiler/Target/Python/PythonPrinter.php` | the target's spelling, or a refusal when it has none, as `(?C)` gets in JavaScript | your transpiler tests: the printers take a context, so `VisitorExhaustivenessTest` does not build them |

### ReDoS, Automata and Linter

| Where | What to add | What fails when it is missed |
|---|---|---|
| `Redos/RedosProfiler.php` | the item's risk | `VisitorExhaustivenessTest` |
| `Redos/Backtrack/LastCodeUnit.php` | what the item does to the code unit PCRE2 requires in the subject | read it |
| `Automata/LanguageSolver.php` | a refusal for an item the solver cannot read | read it |
| `Linter/Rule/Support/NodePredicates.php`, `LoopShape.php`, `CaptureWitness.php` | the item, in the helpers the rules share | read it |
| `Linter/Rule/LookaroundEdgeQuantifierRule.php`, `RedundantGroupRule.php`, `RedundantLookaroundRule.php` | the item, where a rewrite must not move it | read it |

`Toolkit/Upgrade/UpgradeMap.php` names `CalloutNode` too, but only to map the
1.x class names: a new node has no 1.x name.

---

## Writing a Visitor

A visitor walks the tree and returns what you compute from it. It is the way
to read a pattern for your own purpose, and it needs no change to the library.

### Create a Pattern Length Visitor

```php
<?php

declare(strict_types=1);

namespace App\Regex;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\AbstractNodeVisitor;

/**
 * Calculates pattern complexity score.
 *
 * @extends AbstractNodeVisitor<int|null>
 */
final class ComplexityVisitor extends AbstractNodeVisitor
{
    private int $score = 0;

    public function getScore(): int
    {
        return $this->score;
    }

    public function visitRegex(RegexNode $node): int
    {
        $this->score = 0;
        $node->pattern->accept($this);

        // Add base score for flags
        $this->score += strlen($node->flags);

        return $this->score;
    }

    public function visitSequence(SequenceNode $node): int
    {
        foreach ($node->children as $child) {
            $child->accept($this);
        }

        return $this->score;
    }

    public function visitAlternation(AlternationNode $node): int
    {
        // Alternations increase complexity
        $this->score += 10;

        foreach ($node->alternatives as $alternative) {
            $alternative->accept($this);
        }

        return $this->score;
    }

    public function visitQuantifier(QuantifierNode $node): int
    {
        // Nested quantifiers increase complexity
        $this->score += 5;

        $node->node->accept($this);

        return $this->score;
    }

    public function visitGroup(GroupNode $node): int
    {
        $node->child->accept($this);

        return $this->score;
    }

    public function visitLiteral(LiteralNode $node): int
    {
        $this->score += strlen($node->value);

        return $this->score;
    }

    public function visitCharClass(CharClassNode $node): int
    {
        $this->score += 5;

        return $this->score;
    }

    // Add other visit methods as needed...
}
```

### Use Your Visitor

```php
use App\Regex\ComplexityVisitor;
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
$ast = $regex->parse('/^(?:[a-z]+|\d{3,})+$/');

$visitor = new ComplexityVisitor();
$ast->accept($visitor);

echo "Complexity: " . $visitor->getScore();  // Output: Complexity: 30
// 10 for the alternation, 3 x 5 for the quantifiers, 5 for the character class
```

A visitor that only collects needs no `@extends`: the visitor base types
default their return to `null`, and an unbound subclass passes PHPStan as a
collector (Psalm still wants the explicit binding). A visitor whose methods return a value binds that type — `null`
included, since the methods it does not override return `null` — as the
`@extends AbstractNodeVisitor<int|null>` above does; `$ast->accept($visitor)`
then reads as `int|null` to PHPStan, and an overridden method whose body
forgets its `return` is flagged.

The library ships its own scorer for this job — [ComplexityScorer](visitors/README.md#complexityscorer),
with bands calibrated for CI gates; the visitor above is a small cousin whose
weights you control.

---

## Common Extension Patterns

### Pattern 1: Analysis Visitor

An analysis visitor walks the tree and collects what it finds.
`AbstractTraversingVisitor` visits every child for you: override the nodes you
check and call the parent method to keep descending.

```php
use PHPRegex\Parser\AbstractTraversingVisitor;
use PHPRegex\Parser\Node\QuantifierNode;

class UnboundedStarFinder extends AbstractTraversingVisitor
{
    /** @var list<string> */
    private array $findings = [];

    public function visitQuantifier(QuantifierNode $node)
    {
        if ('*' === $node->quantifier) {
            $this->findings[] = sprintf('Unbounded "*" at offset %d.', $node->startPosition);
        }

        return parent::visitQuantifier($node);
    }

    /** @return list<string> */
    public function getFindings(): array
    {
        return $this->findings;
    }
}
```

Its findings are yours to report: they carry no rule id, and `regex lint`
does not run them.

### Pattern 2: Pattern Transformation

A transformer rebuilds the tree itself — `AbstractNodeVisitor` returns `null`
for the nodes it does not override, so override the containers too, or the
rewritten literal never reaches the new tree:

```php
class YourTransformer extends AbstractNodeVisitor
{
    public function visitRegex(RegexNode $node): RegexNode
    {
        return new RegexNode(
            $node->pattern->accept($this),
            $node->flags,
            $node->delimiter,
            $node->startPosition,
            $node->endPosition,
        );
    }

    public function visitSequence(SequenceNode $node): SequenceNode
    {
        return new SequenceNode(
            array_map(
                fn (NodeInterface $child): NodeInterface => $child->accept($this),
                $node->children,
            ),
            $node->startPosition,
            $node->endPosition,
        );
    }

    public function visitLiteral(LiteralNode $node): NodeInterface
    {
        // Transform: lowercase to uppercase
        return new LiteralNode(
            strtoupper($node->value),
            $node->startPosition,
            $node->endPosition
        );
    }
}
```

The [visitors reference](visitors/README.md#pattern-3-transforming-visitor)
shows the same transformer with its imports and the `PatternPrinter` round
trip; `Rewriter` and `Modernizer` are full working examples in the source.

### Pattern 3: Custom Analysis

```php
class YourAnalyzer extends AbstractNodeVisitor
{
    /** @var array<string, int> */
    private array $counts = [];

    public function getCounts(): array
    {
        return $this->counts;
    }

    public function visitGroup(GroupNode $node): void
    {
        $this->counts['groups'] = ($this->counts['groups'] ?? 0) + 1;
        $node->child->accept($this);
    }
}
```

---

## Troubleshooting

### "Node not recognized"

Make sure you:
1. Created the node class
2. Gave its token a `case` in `parseAtom()`, or read it from `parseGroupModifier()` or `parseSimpleAtom()`
3. Added the visitor method, and went through the [checklist](#checklist-every-place-a-node-touches)

### "Visitor method not called"

Check:
1. Node's `accept()` calls the matching `visitXxx()` method on the visitor
2. Visitor implements the method
3. Visitor is registered/used correctly

### "Parse error"

Verify parser logic:
1. Token type check is correct
2. Token consumption order is right
3. Error handling for missing tokens

---

## Best Practices

1. **Immutability** - A node holds `public readonly` properties and never changes once built
2. **Position tracking** - Preserve start/end positions
3. **The engine decides** - Check every meaning and every error against `preg_match()` first
4. **Error handling** - Throw meaningful exceptions, with the offset PCRE reports
5. **Testing** - Cover edge cases
6. **Documentation** - Explain the feature clearly

---

## Learning Resources

- **[AST Nodes](nodes/README.md)** - Node reference
- **[Visitors](visitors/README.md)** - Visitor patterns
- **[AST Traversal](design/ast-traversal.md)** - Traversal design
- **[Adding a Lint Rule](adding-a-lint-rule.md)** - A rule, from its class to its documentation
- **[Contributing](https://github.com/php-regex/php-regex/blob/2.x/CONTRIBUTING.md)** - Setup, tests and conventions
- **Source code** - Learn from existing implementations

---

## Next Steps

1. Pick a simple feature to implement
2. Follow the steps above
3. Write tests
4. Update documentation
5. Submit a PR!

---

End of extending guide.
