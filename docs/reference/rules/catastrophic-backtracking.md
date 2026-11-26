---
description: "The ReDoS verdict regex.lint.redos: how PHPRegex proves that one match attempt grows with the input, the attack string it hands back, and the risk levels."
head_title: "Catastrophic backtracking in PHP regex (ReDoS)"
parent: /reference/rules/
rule_category: "Security (ReDoS)"
rule_order: 1
rule_ids:
  - regex.lint.redos
---
# Catastrophic Backtracking

**Identifier:** `regex.lint.redos`; `regex.redos` in PHPStan, whatever the severity; the message names it

**When it triggers:** The ReDoS analyzer proves how one match attempt grows with the input, by following PCRE's backtracking order, and hands back the input that triggers it. Patterns outside that model (backreferences, conditionals, recursion, an analysis over budget) are judged by heuristics, and the report says which of the two decided. The message prefix names the class: `Exponential backtracking (ReDoS)`, `Polynomial backtracking (ReDoS)` or `Potential backtracking (ReDoS)`.

**Risk Levels:**

| Level      | Proven class                     | Action Required      |
|------------|----------------------------------|----------------------|
| `critical` | exponential                      | Refactor immediately |
| `high`     | polynomial, degree 3 or more     | Consider refactoring |
| `medium`   | polynomial, degree 2 (quadratic) | Monitor and plan fix |
| `low`      | heuristic finding only           | Accept with logging  |

See the [ReDoS guide](../../guides/redos.md) for the guarantee and its limits.

**Example:**
```php
// VULNERABLE: Exponential backtracking
preg_match('/(a+)+$/', $input);  // CRITICAL

// SAFER: Atomic group
preg_match('/(?>a+)+$/', $input);  // SAFE

// SAFER: Possessive quantifier
preg_match('/(a++)+$/', $input);  // SAFE
```

**Fix:** Make the ambiguous part atomic or possessive, or refactor.

**Read more:**
- [OWASP: Regular Expression Denial of Service](https://community.owasp.org/attacks/Regular_expression_Denial_of_Service_-_ReDoS)

---

**Try it.** [Open `/(a+)+$/` in the playground](/#pattern=%2F%28a%2B%29%2B%24%2F&subject=aaaaab): the ReDoS tab holds the verdict.
