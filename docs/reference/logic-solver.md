# Regex Logic & Automata Solver

## The Concept

RegexParser can transform a regex into a deterministic finite automaton (DFA). That means a pattern becomes a **set of strings**, and comparisons become precise set operations instead of guesswork.

Verified example (intersection):

```bash
bin/regex compare '/edit/' '/[a-z]+/'
```

```
  FAIL  Conflict detected!

  Example:           "edit"
```

## Use Case 1: Route Conflict Detection (The "Intersection" Problem)

Scenario: Route A is `/order/\d+` and Route B is `/order/[a-z0-9]+`. They look different, but can they match the same string?

Command:

```bash
bin/regex compare '/order/\d+/' '/order/[a-z0-9]+/'
```

Result:

```
  FAIL  Conflict detected!

  Example:           "order/0"
```

Interpretation: conflict detected on input `order/0`.

Educational value: **Intersection** asks "is there a string that matches BOTH patterns?" If the answer is yes, your routes can shadow each other.

## Use Case 2: Security Audits (The "Subset" Problem)

Scenario: A security policy allows `[a-zA-Z0-9]+`. A developer writes `\w+`, which includes `_` and might be forbidden.

Command:

```bash
bin/regex compare '/\w+/' '/[a-zA-Z0-9]+/' --method=subset
```

Result:

```
  FAIL  Pattern 1 allows strings that Pattern 2 forbids.

  Counter-example:  "_"
```

Interpretation: FAIL. Counter-example: `_`.

Educational value: **Subset** asks "does pattern 1 allow ONLY what pattern 2 allows?" If not, the counter-example shows the exact violation.

## Use Case 3: Safe Refactoring (The "Equivalence" Problem)

Scenario: You want to simplify `[0-9]` to `\d` and prove it is safe.

Command:

```bash
bin/regex compare '/[0-9]+/' '/\d+/' --method=equivalence
```

Result:

```
  PASS  Patterns are mathematically equivalent.
```

Educational value: **Equivalence** asks "do these patterns accept the exact same set of strings?"

## PHP API

`PhpRegex\Automata\LanguageSolver` is the one entry point. Each method takes two patterns (with delimiters and
flags) and optional `SolverOptions`, and returns a result object that carries the shortest string proving the answer:

```php
use PhpRegex\Automata\LanguageSolver;

$solver = new LanguageSolver();

$intersection = $solver->intersection('/order\/\d+/', '/order\/[a-z0-9]+/');
$intersection->isEmpty;             // false
$intersection->example;             // "order/0"

$subset = $solver->subsetOf('/\w+/', '/[a-zA-Z0-9]+/');
$subset->isSubset;                  // false
$subset->counterExample;            // "_"

$equivalence = $solver->equivalent('/[0-9]+/', '/\d+/');
$equivalence->isEquivalent;         // true
$equivalence->leftOnlyExample;      // null: no string only the left pattern matches
$equivalence->rightOnlyExample;     // null: no string only the right pattern matches

$dfa = $solver->compile('/[a-z]+/'); // the pattern's DFA (PhpRegex\Automata\Model\Dfa)
```

| method                                        | result               | answer            | witness                                  |
|-----------------------------------------------|----------------------|-------------------|------------------------------------------|
| `intersection($left, $right, $options = null)` | `IntersectionResult` | `isEmpty`         | `example`: a string both match           |
| `subsetOf($left, $right, $options = null)`     | `SubsetResult`       | `isSubset`        | `counterExample`: only the left matches  |
| `equivalent($left, $right, $options = null)`   | `EquivalenceResult`  | `isEquivalent`    | `leftOnlyExample`, `rightOnlyExample`    |
| `compile($pattern, $options = null)`           | `Dfa`                | the pattern's DFA | stored in the DFA cache when one is set  |

The constructor takes the parser that reads the patterns, so they are read for its PHP and PCRE2 target, and a DFA cache
that keeps compiled patterns between questions:

```php
use PhpRegex\Automata\LanguageSolver;
use PhpRegex\Automata\Solver\InMemoryDfaCache;
use PhpRegex\Parser\RegexParser;

$solver = new LanguageSolver(RegexParser::create(['pcre_version' => '10.42']), new InMemoryDfaCache());
```

A pattern outside the regular subset (backreferences, lookarounds, recursion, ...) throws a `ComplexityException`
instead of returning an answer that would be wrong.

The public classes of `PhpRegex\Automata` are `LanguageSolver`, `Options\SolverOptions`, `Options\MatchMode`,
`Determinization\DeterminizationAlgorithm`, `Minimization\MinimizationAlgorithm`, the three result classes in
`Solver\`, `Model\Dfa` and the `Model\DfaState` it hands out, `Solver\DfaCacheInterface` and `Solver\InMemoryDfaCache`. Every other class of the namespace is
`@internal` and may change in any release.

## How it Works (Under the Hood)

RegexParser follows a formal pipeline:

1. AST -> NFA (Thompson construction)
2. NFA -> DFA (powerset construction)
3. BFS traversal over product DFA to find overlap or counter-examples

The BFS step guarantees the **shortest possible counter-example** when one exists.

## Determinization Strategies

RegexParser determinizes NFAs using a selectable strategy:

- **subset**: classic powerset construction.
- **subset-indexed** (default): pre-indexes transition ranges to reduce move checks on large alphabets.

The indexed strategy typically runs faster at the cost of a slightly higher memory footprint.

### Selecting a Strategy

CLI:

```bash
bin/regex compare '/foo/' '/bar/' --determinizer=subset-indexed
```

Symfony bundle:

```yaml
# config/packages/regex_parser.yaml
regex_parser:
  automata:
    determinization_algorithm: subset-indexed
```

You can also set it programmatically:

```php
use PhpRegex\Automata\Determinization\DeterminizationAlgorithm;
use PhpRegex\Automata\Options\SolverOptions;

$options = new SolverOptions(
    determinizationAlgorithm: DeterminizationAlgorithm::SubsetIndexed,
);
```

## Minimization Strategies and Complexity

RegexParser minimizes DFAs before comparison to shrink the product graph and keep searches fast.

- **Hopcroft worklist** (default): `O(|Σ_eff| · n log n)`
- **Moore partition refinement**: `O(|Σ_eff| · n^2)`

**Effective alphabet (`Σ_eff`)** means only the symbols that actually appear as DFA transitions are iterated.
This avoids scanning a full Unicode range and keeps minimization proportional to real symbols. For standard
byte-based DFAs, `Σ_eff` is at most 256 symbols and often much smaller.

### Selecting a Strategy

CLI:

```bash
bin/regex compare '/foo/' '/bar/' --minimizer=moore
```

Symfony bundle:

```yaml
# config/packages/regex_parser.yaml
regex_parser:
  automata:
    minimization_algorithm: hopcroft
```

You can also override it per command:

```bash
bin/console regex:compare '/foo/' '/bar/' --minimizer=moore
```

## Safety Limits & Work Budget

Large patterns can cause determinization or minimization to grow quickly. You can enforce a hard work budget using
`SolverOptions::maxTransitionsProcessed`.

```php
use PhpRegex\Automata\LanguageSolver;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Exception\ComplexityException;

$solver = new LanguageSolver();
$options = new SolverOptions(maxTransitionsProcessed: 200000);

try {
    $solver->subsetOf('/[a-z]+/', '/[a-z0-9]+/', $options);
} catch (ComplexityException $exception) {
    $diagnostic = $exception->getDiagnostic();
    // ['phase' => 'determinize'|'minimize', 'states' => ..., 'transitions' => ..., 'alphabet' => ..., 'consumed' => ..., 'limit' => ...]
}
```

When the budget is exceeded, a `ComplexityException` is thrown with a diagnostic payload:

- `phase`: `determinize` or `minimize`
- `states`: number of DFA states seen so far
- `transitions`: NFA/DFA transition count at the time of failure
- `alphabet`: effective alphabet size
- `consumed`: work units consumed
- `limit`: configured budget

Use this to surface safe failure messages in CI or tooling.

## Limitations

- Supports the **regular subset** of PCRE only (no lookarounds, no backreferences, no recursion).
- Operates on **UTF-8 bytes** (alphabet 0-255), not full Unicode code points.
- Partial matching is modeled as **Σ* L Σ***, with start/end anchors allowed only at the outer boundaries.
