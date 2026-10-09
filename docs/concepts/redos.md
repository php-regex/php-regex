# ReDoS Deep Dive

**ReDoS** (Regular Expression Denial of Service) is a vulnerability where a crafted input makes a backtracking regex engine do exponential or high polynomial work before it gives up. In PHP, that work usually ends in a silent failure: `preg_match()` returns `false` once PCRE's backtrack limit is exhausted.

## Simple explanation

Take `/(a+)+$/` and the input `"aaaaaaaaaaaaaaaaaaa!"`. The `!` means the pattern cannot match, but the engine only learns that after trying every way of splitting the `a`s between the inner `+` and the outer `+`. Each extra `a` doubles the number of ways. With PHP's default limits, 19 `a` and a `!` are enough for `preg_match()` to fail with "Backtrack limit exhausted".

## How ReDoS happens

### The problem: ambiguity under backtracking

PCRE tries the alternatives of a pattern in a fixed order: the left alternative first, a greedy quantifier's next iteration before its exit, a lazy one the other way round. When an attempt fails, it backtracks to the last choice and tries the next one. That costs nothing when there is only one way to match each part of the input. It costs a lot when there are many:

```
Pattern: /(a+)+$/
Input:   "aaaa!"

The engine tries:
- (a+) takes "aaaa", the outer + stops, $ fails on "!"
- (a+) takes "aaa", the outer + repeats with "a", $ fails
- (a+) takes "aa", then "aa", $ fails
- (a+) takes "aa", then "a", then "a", $ fails
- ... every composition of 4: 2^3 ways, then 2^(n-1) for n characters
```

### Two classes of cost

- **Exponential**: a loop that can read the same text in two different ways, as `(a+)+`, `(a|aa)*` or `(a|a)*`. Each repetition of that text doubles the work: 2ⁿ steps.
- **Polynomial**: two or more quantifiers in a row that can read the same text, as `a*a*` (n² steps) or `a*a*a*` (n³). The backtrack limit does not stop it in time: `/a*a*$/` matches 200,000 `a` and a `!` without an error, after 6.5 seconds.

### Vulnerable shapes

Each pattern below was run through `RedosAnalyzer` and, for the vulnerable ones, its attack replayed on PCRE2:

| pattern | verdict |
|---|---|
| `(a+)+$`, `(.*)*$`, `(\d+)+$` | exponential: nested unbounded quantifiers |
| `(a\|aa)+$`, `(a\|a)*$` | exponential: alternatives that read the same text |
| `(a*)*$` | exponential: a repeated group that can match nothing and something |
| `a+a+$`, `(\w+)(\w+)$`, `\d+\d+$` | polynomial, degree 2: adjacent quantifiers over the same characters |
| `a*a*a*$` | polynomial, degree 3 |
| `(a\|b)+c`, `(a\|ab)+$`, `(a?)+$` | linear: proven safe, though they look alike |
| `(\w+)\1+$` | outside the model (backreference): the heuristics decide |

## How PHPRegex detects ReDoS

PHPRegex reads the pattern, and runs it only to check a witness through a lookaround on a few short inputs (or in [confirmed mode](../REDOS_GUIDE.md#confirmed-mode)):

1. The lexer and parser build a `RegexNode` AST.
2. `RedosAnalyzer` builds from it a **prioritized NFA**: an automaton whose ε-transitions are ordered as PCRE tries them. Atomic groups, possessive quantifiers and atomic lookaround bodies are separate automata, analysed on their own and seen from outside as one step.
3. It looks for **ambiguity** in that automaton, after the method of Weideman et al. ("Analyzing Matching Time Behavior of Backtracking Regular Expression Matchers by Using Ambiguous NFA", 2016):
   - a state with two different loops reading the same word means **exponential** cost;
   - a chain of k states, each looping on a word and reaching the next on that same word, means **polynomial** cost of degree k.
4. From the ambiguous loop it builds the **witness**: a prefix that reaches it, the word to pump, and the shortest suffix that makes the attempt fail. Characters are chosen deterministically: the smallest printable ASCII character of each set, then the other sets of the loop, before giving up. The model does not decide a lookaround: a witness through one is checked on the running PCRE2, its attempt pinned where it starts.
5. The pattern's class is the worst class over the pattern and its sub-searches.

A pattern is `safe (proven)` only when the automaton holds no ambiguity at all. An ambiguity for which no witness can be built is not taken as safe: the heuristics decide, and `abstractions` lists `ambiguity without witness at offset N`.

The model covers characters and classes (exact sets under `/u` and `/i`, computed from the running PCRE2), alternation, groups, every quantifier, atomic groups, possessive quantifiers, atomic lookarounds, anchors and word boundaries. It reads inline options as PCRE does: an option set inside one alternative also holds in the alternatives after it, `r` (caseless restrict) and the ASCII options (`a`, `aD`, `aS`, `aW`, `aP`, `aT`) are read per scope, `(?^)` clears `i`, `m`, `n`, `s`, `x`, `xx` and `r` but keeps `U` and the ASCII options, `xx` drops a class's spaces and tabs, and `\b` under `aW` reads the ASCII `\w`. Backreferences, conditionals, recursion and subroutine calls, backtracking control verbs, callouts, `\X`, `\R`, non-atomic lookarounds such as `(*napla:…)`, `\b` and `\B` under two different ASCII scopes in one pattern (`(?aW:\b)…\b`), and a bounded repeat whose body can match the empty string are outside it: there, as for a pattern over the [analysis budget](../REDOS_GUIDE.md#outside-the-model-heuristics-and-budget), the structural heuristics of `RedosProfiler` decide, as in 1.x:

- star height: nested unbounded quantifiers;
- overlapping alternatives inside a repetition, through `CharSetAnalyzer`;
- backreference loops inside a repetition;
- quantifiers over sub-patterns that can match nothing;
- adjacent quantifiers over overlapping character sets;
- atomic groups and possessive quantifiers lowering the severity.

The result says which of the two decided: `proof` is `proven`, `heuristic`, `budget_exceeded` or `not_analyzed`. The [ReDoS guide](../REDOS_GUIDE.md#the-guarantee) states what a proof guarantees, and its limits.

## Using PHPRegex for ReDoS protection

### CLI usage

```bash
# Check a single pattern
vendor/bin/regex analyze '/(a+)+$/'

# Replay the attack on the running PCRE
vendor/bin/regex analyze '/(a+)+$/' --redos-mode=confirmed

# Scan your entire codebase for ReDoS risk
vendor/bin/regex lint src/ --redos --no-lint --no-optimize
```

### PHP API

```php
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

$analysis = $regex->redos('/(a+)+b/');
echo $analysis->severity->value, "\n";   // critical
echo $analysis->complexity->value, "\n"; // exponential
echo $analysis->proof->value, "\n";      // proven
echo $analysis->witness->render(), "\n"; // "a" x n . "!b"

// Check against a threshold
if ($analysis->exceedsThreshold(RedosSeverity::High)) {
    echo "Pattern is vulnerable\n";
}

// Proven safe, not just "nothing found"
var_dump($regex->redos('/a+b/')->isProvenSafe()); // bool(true)

// Recommendations from the structural analysis
foreach ($analysis->recommendations as $recommendation) {
    echo 'Suggestion: ', $recommendation, "\n";
}
```

## Fixing vulnerable patterns

Each rewrite below is proven safe by the analyzer. Verify that it still matches and captures what you need.

### 1. Use possessive quantifiers

```
Vulnerable: /(a+)+b/
Safer:      /a++b/
```

Possessive quantifiers (`*+`, `++`, `?+`, `{m,n}+`) never give back what they matched.

### 2. Use atomic groups

```
Vulnerable: /(a+)+b/
Safer:      /(?>a+)b/
```

An atomic group `(?>...)` commits to the first way its body matched.

### 3. Simplify nested repeats

```
Vulnerable: /(a+)+b/
Equivalent: /a+b/
```

### 4. Remove overlapping alternatives

```
Vulnerable: /(a|aa)+$/
Safer:      /a+$/
```

### 5. Avoid repeating a group that can match nothing

```
Vulnerable: /(a*)*$/
Safer:      /a*$/
```

### 6. Separate adjacent quantifiers

```
Vulnerable: /a+a+$/
Safer:      /a+$/
Safer:      /a++a+$/   (if the split must be preserved)
```

### 7. Bound your repeats

```
Vulnerable: /(\d+)+$/
Safer:      /\d{1,10}$/
```

## Quick reference: vulnerable vs safer patterns

```
(a+)+        -> a++        or (?>a+)
(a|aa)+      -> a+
(\d+)+       -> \d++       or \d{1,10}
(.+)+        -> .++        or .{1,100}
(a*)*        -> a*
a+a+         -> a+         or a++a+
(\w+\d+)+    -> (?>\w+\d+)+
```

## Defense in depth

1. **Analyze patterns early**: run `regex lint --redos` or PHPStan in CI.
2. **Check for `false`**: a vulnerable pattern makes `preg_*` fail silently; read `preg_last_error()`.
3. **Limit input length**: bound what reaches a regex from outside.
4. **Prefer deterministic patterns**: possessive quantifiers and atomic groups.
5. **Mind every-match functions**: the guarantee covers one match attempt; `preg_match_all()`, `preg_replace()` and `preg_split()` make many.

## Related concepts

- **[ReDoS Guide](../REDOS_GUIDE.md)** - The verdict, the witness, confirmed mode and the guarantee
- **[Architecture](../ARCHITECTURE.md)** - Where the analysis sits in the library
- **[FAQ & Glossary](../reference/faq-glossary.md)** - Common ReDoS questions

## Further reading

- [OWASP ReDoS Guide](https://owasp.org/www-community/attacks/Regular_expression_Denial_of_Service_-_ReDoS) - Security best practices
- [Regex Performance](https://sw.kovidgoyal.net/kitty/conf/#regex-performance) - Optimization techniques
- [Catastrophic Backtracking](https://www.regular-expressions.info/catastrophic.html) - Detailed explanation

---

Previous: [Understanding Visitors](visitors.md) | Next: [PCRE vs Other Engines](pcre.md)
