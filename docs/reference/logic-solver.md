# Regex Logic & Automata Solver

## The Concept

PHPRegex can transform a regex into a deterministic finite automaton (DFA). That means a pattern becomes a **set of strings**, and comparisons become precise set operations instead of guesswork.

Verified example (intersection):

```bash
bin/regex compare '/edit/' '/[a-z]+/'
```

```
  FAIL Conflict detected!

  Example:           "edit"
```

## Use Case 1: Route Conflict Detection (The "Intersection" Problem)

Scenario: Route A is `/order/\d+` and Route B is `/order/[a-z0-9]+`. They look different, but can they match the same string?

Command:

```bash
bin/regex compare '/order\/\d+/' '/order\/[a-z0-9]+/'
```

Result:

```
  FAIL Conflict detected!

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
  FAIL Pattern 1 allows strings that Pattern 2 forbids.

  Counter-example:   "_"
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
  PASS Patterns are mathematically equivalent.
```

Educational value: **Equivalence** asks "do these patterns accept the exact same set of strings?"

## Use Case 4: Rewrites that Keep $matches (The "Match Equivalence" Problem)

Scenario: two patterns match the same strings, yet one replaces the other only if
`preg_match()` writes the same `$matches` for them. PCRE takes the first alternative that
leads to a match, not the longest: on `ab`, `/a|ab/` matches `a` and `/ab|a/` matches `ab`.

```php
$solver = new LanguageSolver();

$solver->equivalent('/(a|ab)/', '/(ab|a)/')->isEquivalent;          // true: same strings
$result = $solver->matchEquivalent('/(a|ab)/', '/(ab|a)/');
$result->isEquivalent;                                               // false
$result->counterExample;                                             // "ab": $1 is "a", then "ab"

$solver->matchEquivalent('/a|ab/', '/a(?:b)??/')->isEquivalent;      // true
```

Educational value: **Match equivalence** asks "does `preg_match()` give the same answer,
the same match and the same groups for every subject?" The solver runs both patterns side
by side the way a leftmost-first matcher runs one, its threads in the order PCRE tries
its paths, a new start at each position until a match is found. It keeps positions only
as far as their equality goes, so the search over every subject ends, and the first
subject on which the two differ is the shortest.

It reads the fragment where that run picks the match PCRE's backtracking picks. It refuses,
each with its reason: backreferences, subroutines, conditionals and control verbs;
lookarounds; atomic groups and possessive quantifiers; a loop over a body that can match
empty, which PCRE stops after an empty iteration; word boundaries, multiline anchors, `\G`
and `\K`; an end anchor followed by more of the pattern; and two patterns of which one
reads UTF-8 and the other bytes. Groups count by number and by name: `/(?<n>a)/` and `/(a)/`
write different `$matches`.

## Use Case 5: Live Input Validation (The "Prefix" Problem)

Scenario: a form field checks a date as it is typed. `preg_match()` says `0` for `2026`
and for `202a` alike: neither is a whole date, and nothing tells the user which one can
still become one.

```php
$solver = new LanguageSolver();

$solver->acceptsPrefix('/^\d{4}-\d{2}-\d{2}$/', '2026');    // true: "2026-10-03" completes it
$solver->acceptsPrefix('/^\d{4}-\d{2}-\d{2}$/', '202a');    // false: nothing does
$solver->acceptsPrefix('/^\d{4}-\d{2}-\d{2}$/', '2026-10-031'); // false: one digit too many
```

Educational value: **Prefix** asks "does some string starting with this input belong to
the language?" The solver reads the input on the pattern's DFA, then asks whether an
accepting state can still be reached. In UTF mode an input that ends in the middle of a
character is viable when one way to finish the character is.

## The Guarantee: One Engine, One Truth

Every character set the solver matches is asked from the PCRE2 that runs in your
PHP — through the normalized form (HIR) the pattern is compiled to first.
Classes and ranges, `\w`, `\s`, `\d`, the dot, POSIX classes, `\p{...}`
properties, Perl extended classes `(?[ ... ])` and case-insensitive folding all
come from that one engine, never from tables the library maintains by hand.

The consequences:

- **Constructs the old conversion refused outright are now answered**: POSIX
  classes (`[[:alpha:]]`, also negated `[[:^digit:]]`), Unicode properties
  (`\p{L}`, `\P{L}`, scripts like `\p{Greek}`), extended classes
  (`(?[ \p{L} - [aeiou] ])`) and the class-set operations inside them, and the
  `\C` "one byte" escape.
- **Verdicts are engine-relative. A `(*UTF)` start verb sets the alphabet without Unicode properties: `(*UTF)\w` stays ASCII, as the engine reads it.** What `[[:alpha:]]` means under `/u` is what
  your PCRE2 says it means, and a pattern analyzed on one machine can only be
  compared with a pattern analyzed on the same engine. Every result says which
  engine answered, in `pcreVersion`.
- **Without `/u` the alphabet is bytes** (`0x00`-`0xFF`); **with `/u` it is
  code points** `U+0000`-`U+10FFFF` minus the surrogate block `U+D800`-`U+DFFF`
  — real subjects never contain the surrogates, so no set the solver builds
  does either. Counter-example strings above byte `0x7F` are raw bytes, as the
  engine reads them. (When the code-point alphabet arrived, the sets' shape
  changed once; the pinned verdicts did not.)

The same engine-relative question, asked at the CLI:

```bash
bin/regex compare '/[[:alpha:]]/u' '/[a-zA-Z]/u'
```

```
  FAIL Conflict detected!

  Example:           "A"
```

That only proves the languages meet — `A` is a letter either way. The interesting
answer is the difference, which the API hands you: on a PCRE2 built with UCP
support, `[[:alpha:]]` under `/u` is wider than the ASCII letters, and the
solver's counter-example names a character only the POSIX side matches (`ª`,
for one). On an engine where it is not, the two patterns come out equivalent.
The solver follows the engine in both cases; it never guesses.

## PHP API

`PHPRegex\Automata\LanguageSolver` is the one entry point. Each method takes two patterns (with delimiters and
flags) and optional `SolverOptions`, and returns a result object that carries the shortest string proving the answer:

```php
use PHPRegex\Automata\LanguageSolver;

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

$solver->acceptsPrefix('/^ab$/', 'a'); // true: some string starting with "a" matches

$match = $solver->matchEquivalent('/a|ab/', '/ab|a/');
$match->isEquivalent;               // false: same strings, other matches
$match->counterExample;             // "ab"

$dfa = $solver->compile('/[a-z]+/'); // the pattern's DFA (PHPRegex\Automata\Model\Dfa)
```

| method                                        | result               | answer            | witness                                  |
|-----------------------------------------------|----------------------|-------------------|------------------------------------------|
| `intersection($left, $right, $options = null)` | `IntersectionResult` | `isEmpty`         | `example`: a string both match           |
| `subsetOf($left, $right, $options = null)`     | `SubsetResult`       | `isSubset`        | `counterExample`: only the left matches  |
| `equivalent($left, $right, $options = null)`   | `EquivalenceResult`  | `isEquivalent`    | `leftOnlyExample`, `rightOnlyExample`    |
| `matchEquivalent($left, $right, $options = null)` | `MatchEquivalenceResult` | `isEquivalent` | `counterExample`: the shortest subject with other `$matches` |
| `acceptsPrefix($pattern, $input, $options = null)` | `bool`          | whether some string starting with `$input` matches | — |
| `compile($pattern, $options = null)`           | `Dfa`                | the pattern's DFA | stored in the DFA cache when one is set  |

Each of the four result objects also carries `pcreVersion`, the PCRE2 release
the answer was computed with (`"10.49"` on the engine this page's examples ran
on). Two results with different stamps describe two engines' languages; compare
them only knowing that.

The constructor takes the parser that reads the patterns, so they are read for its PHP and PCRE2 target, and a DFA cache
that keeps compiled patterns between questions:

```php
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Parser\RegexParser;

$solver = new LanguageSolver(RegexParser::create(['pcre_version' => '10.42']), new InMemoryDfaCache());
```

A pattern outside the regular subset (backreferences, recursion, ...) throws a `ComplexityException`
instead of returning an answer that would be wrong. The next section lists every reason.

The public classes of `PHPRegex\Automata` are `LanguageSolver`, `Options\SolverOptions`, `Options\MatchMode`,
`Determinization\DeterminizationAlgorithm`, `Minimization\MinimizationAlgorithm`, the four result classes in
`Solver\`, `Model\Dfa` and the `Model\DfaState` it hands out, `Solver\DfaCacheInterface`, `Solver\InMemoryDfaCache`, `TrivialMatchClassifier` with the `TrivialMatch` and `TrivialMatchKind` it returns, and `Language`. Every other class of the namespace is
`@internal` and may change in any release.

## The Language of a Pattern

`language()` hands out what the automaton of a pattern knows once built, under the match
mode of the options: whether its language is finite, how many strings of each length it
holds, each of them in order, and the strings it rejects.

```php
$solver = new LanguageSolver();

$plates = $solver->language('/^[A-Z]{2}\d{4}$/');
$plates->isFinite();          // true
$plates->size();              // "6760000"
$plates->minLength();         // 6

$solver->language('/^[a-z]+$/')->countOfLength(20); // "19928148895209409152340197376"

iterator_to_array($solver->language('/^[ab]{2}$/')->strings(), false); // ['aa', 'ab', 'ba', 'bb']
```

Counts are exact decimal strings, however large, with no extension needed. `strings()` and
`nonMembers()` are generators: shortest first, then in code point order; an infinite
language never runs out, so take what you need. Each string `nonMembers()` hands out is
proven outside the language by the automaton: test fixtures with positives and negatives
both certain. Under the default full match mode a string belongs when the whole of it
matches; ask for `MatchMode::Partial` to read the subjects `preg_match()` accepts, a final
newline after `$` included.

## Lookarounds

Lookarounds keep a language regular, and the solver reads them: a password rule
`^(?=.*\d)(?=.*[a-z]).{8,}$` is a language like any other.

```php
$solver = new LanguageSolver();

$solver->subsetOf('/^(?=.*\d)(?=.*[a-z]).{8,}$/', '/^.{8,}$/')->isSubset;     // true
$solver->equivalent('/^a(?!b)./', '/^a[^b\n]/')->isEquivalent;              // true
$solver->equivalent('/a(?=b)c/', '/[^\s\S]/')->isEquivalent;               // true: never matches
```

A lookahead is a promise about what follows: crossing it starts a run of its body's
automaton on the characters still to come, kept once that run accepts — broken, for a
negative lookahead. A lookbehind asks about what came before: the automaton of "anything,
then its body" runs from the start of the subject, and the lookbehind reads where it
stands. The product of the pattern's automaton with the pending promises and those runs is
an automaton of its own, determinized as any other (Berglund, van der Merwe and van
Litsenborgh, "Regular Expressions with Lookahead", 2021).

A word boundary is two lookarounds in disguise, and reads as them: `\b` holds between a
word character and something else, `(?<=\w)(?!\w)|(?<!\w)(?=\w)`, `\B` where it does not.
Word characters are the engine's: `é` is one under `/u`, two bytes that are not without it.

A lookaround inside a lookaround, an anchor or word boundary inside one, and the non-atomic
`(*napla:...)` are refused. The product can grow large; the usual NFA and DFA budgets bound it.

## What the Solver Refuses

One taxonomy, one exception (`ComplexityException`), one message per reason.
The messages below are the texts the library prints; `getMessage()` returns them
verbatim:

| the pattern holds …                       | the message begins … |
|-------------------------------------------|----------------------|
| a backreference, subroutine call, callout or control verb (and `\X`) | `Backreferences, subroutines, callouts and control verbs carry match state the automata solver cannot read as a pure language.` |
| a conditional group                        | `Conditional groups branch on match state the automata solver cannot read as a pure language.` |
| a lookaround inside a lookaround           | `A lookaround inside a lookaround is beyond what the automata solver reads.` |
| an anchor inside a lookaround              | `An anchor inside a lookaround is beyond what the automata solver reads.` |
| a non-atomic lookaround, `(*napla:...)`    | `A non-atomic lookaround, (*napla:...) or its kind, backtracks into its body, which the automata solver does not read.` |
| an atomic group (and `\R`)                 | `Atomic groups commit to their first match and never retry, which is ordered behaviour the solver cannot read as a pure language.` |
| `\K`, `\G`, or an anchor away from the edge of an alternative | `\K, \G and anchors away from the edges of an alternative are zero-width conditions the automata solver cannot read as a pure language.` |
| a possessive quantifier the solver cannot prove inert | `Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.` |
| a surrogate code point the pattern names under `/u` | `PCRE refuses any pattern that names a surrogate code point, which the automata solver cannot read as a pure language.` |
| a flag outside `i`, `s`, `u`, `D`          | `Unsupported regex flags for automata: m.` (the pattern's own flags, in order) |

Two refinements inside those rules:

- **Anchors.** `^`, `$`, `\A`, `\z` and `\Z` are read where they carry no
  meaning of their own — at the outer start or end of an alternative
  (`/\Aab\z/` and `/^ab$/` are answered; `/a\Ab/` is refused with the
  zero-width message above). `^` and `$` misplaced keep their own message
  (`Anchors in partial match mode must appear at the start or end of each
  alternative.`), as do anchors nested inside a group or under a quantifier
  (`Nested anchors are not supported in partial match mode.`), and alternatives
  that anchor differently (`Mixed start anchors across alternatives are not
  supported in partial match mode.`).
- **Possessive quantifiers and atomic groups are refused — except when nothing
  that follows can take the characters back.** `/^a*+a$/` matches nothing at
  all, an ordered fact no pure language can say, so it is refused; but `a++`
  before `c?d` holds, because everything after it starts with characters its
  atom cannot match. The rule reads the first characters of everything that
  follows — the rest of the sequence, through followers that may be skipped,
  and past the sequence's own end through what follows the capture, the
  alternation branch or the repetition body it sits in, the loop's own first
  characters included — case- and flag-aware, so Symfony's `[^/]++` route
  requirements keep being analyzed. A flat chain of
  possessives (`/x*+ab*+/`) is refused: the second possessive sits beyond the
  next part, which is exactly what the rule cannot see across.

An atom the engine itself refuses to define (the character-set query PCRE
rejects) is a refusal too, under the first message — never an approximation:
this solver answers languages, it does not read anything "as any character".
Naming a surrogate code point under `/u` — a literal `\x{D800}`, a class
member, or the endpoint of a range — is the same kind of refusal with its own
message: PCRE refuses to compile the pattern at all, so there is no language
to read. A range that straddles the block with both endpoints outside it
(`[\x{D7FF}-\x{E000}]`) is legal, and its set keeps the two real ends with
the hole out.

On the command line the same refusal is summarized as
`Comparison not supported: Pattern contains advanced features (e.g., backreferences).`
and the command exits with code 1.

## How it Works (Under the Hood)

PHPRegex follows a formal pipeline:

1. The pattern's normalized form (HIR) — where every character set is already
   the engine's — becomes an NFA (Thompson construction)
2. NFA -> DFA (powerset construction)
3. BFS traversal over product DFA to find overlap or counter-examples

The BFS step guarantees the **shortest possible counter-example** when one exists.

## Determinization Strategies

PHPRegex determinizes NFAs using a selectable strategy:

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
# config/packages/php_regex.yaml
php_regex:
  automata:
    determinization_algorithm: subset-indexed
```

You can also set it programmatically:

```php
use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\Options\SolverOptions;

$options = new SolverOptions(
    determinizationAlgorithm: DeterminizationAlgorithm::SubsetIndexed,
);
```

## Minimization Strategies and Complexity

PHPRegex minimizes DFAs before comparison to shrink the product graph and keep searches fast.

- **Hopcroft worklist** (default): `O(|Σ_eff| · n log n)`
- **Moore partition refinement**: `O(|Σ_eff| · n^2)`

**Effective alphabet (`Σ_eff`)** means only the symbols that actually appear as DFA transitions are iterated.
This avoids scanning a full Unicode range and keeps minimization proportional to real symbols. For byte-mode
DFAs, `Σ_eff` is at most 256 symbols and often much smaller; under `/u` it counts the code-point ranges the
patterns actually use, not the whole `U+0000`-`U+10FFFF` span.

### Selecting a Strategy

CLI:

```bash
bin/regex compare '/foo/' '/bar/' --minimizer=moore
```

Symfony bundle:

```yaml
# config/packages/php_regex.yaml
php_regex:
  automata:
    minimization_algorithm: hopcroft
```

You can also override it per command:

```bash
bin/console regex:compare '/foo/' '/bar/' --minimizer=moore
```

## Performance

Asking the engine for a character set costs one scan per distinct atom, and the
answers are cached per process: the same `\w` under `/u` is asked once, whatever
the number of patterns. Measured over the project's corpus of about 1,700
real-world patterns:

- 715 distinct atoms scanned once per process, 1.77 s one-time — about 18 % of
  a cold corpus-wide comparison pass;
- after that one-time cost, the migrated solver runs at parity cold (0.91x the
  pre-migration time) and faster warm (0.80x), while answering 56 corpus
  patterns the old conversion refused.

On the Optimizer side, the solver that verifies rewrites keeps a DFA cache, so
a repeated `optimize()` of the same pattern answers from the cache instead of
rebuilding both automata — measured at roughly two orders of magnitude on the
second run of the corpus' equivalence questions; a single pass is unchanged.

## Safety Limits & Work Budget

Large patterns can cause determinization or minimization to grow quickly. You can enforce a hard work budget using
`SolverOptions::maxTransitionsProcessed`.

```php
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Exception\ComplexityException;

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

- Supports the **regular subset** of PCRE only — no
  backreferences, no recursion, no atomic or possessive construct the follower
  rule cannot prove inert. Every refusal names its reason; see
  [What the Solver Refuses](#what-the-solver-refuses).
- The pattern flags `i`, `s`, `u` and `D` are read; `m`, `x` and `r` are refused.
- In partial match mode, a search, `$` and `\Z` also match before a newline that ends
  the subject, as PCRE's do without `/D`: `/^ab$/` matches `"ab\n"`, `/^ab\z/` does not.
  A full match covers the whole subject, so there `$` is the end of the subject.
  Inline flags (`(?i:...)`) are applied where they hold.
- Case-insensitive matching folds single code points, as the engine folds
  them. A fold that produces several code points (the Turkish `İ`, the `DŽ`
  digraph) is outside the model: under `/iu`, `k` still matches the Kelvin sign
  U+212A and `s` the long s U+017F, while the dotless `ı` stays apart.
- Under `/u` the sets cover the code points minus the surrogates
  `U+D800`-`U+DFFF`; without `/u` the alphabet is the 256 bytes, and a
  multi-byte subject is read byte by byte, as the engine does without `/u`.
- Partial matching is modeled as **Σ\* L Σ\***, with anchors allowed only at
  the outer boundaries of each alternative, consistently across alternatives.
