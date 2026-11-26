---
description: "The complexity score of a pattern, the number Regex::validate() returns, reaches the threshold of regex.json, 50 by default: a warning, once the check is on."
head_title: "Pattern complexity score: the warning threshold"
parent: /reference/rules/
rule_category: "Pattern Complexity"
rule_order: 1
rule_ids:
  - regex.lint.complexity
---
# Complexity Threshold

**Identifier:** `regex.lint.complexity`

**When it triggers:** The analysis' complexity score for the pattern — the same number `Regex::validate()` returns as `complexityScore` — reaches the warning threshold, 50 by default.

**Message:** `Pattern is complex (score: 74).` — here for `/((a|b)+c?){3}(x|y)*[0-9]{2,4}(p|q|r)+\s*z{5,}/`:

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->validate('/((a|b)+c?){3}(x|y)*[0-9]{2,4}(p|q|r)+\s*z{5,}/');
echo $result->complexityScore;  // 74
```

It is off by default. `regex lint` reports it once `regex.json` turns it on, with the threshold you choose:

```json
{
  "checks": {
    "complexity": {
      "enabled": true,
      "threshold": 50
    }
  }
}
```
{: data-file="regex.json" }

The PHPStan rule reports it under the same identifier once its `complexity` check is on; `rules.neon` leaves it off:

```neon
parameters:
    phpRegex:
        checks:
            complexity:
                enabled: true
                threshold: 50
```
{: data-file="phpstan.neon" }

The Symfony and Laravel lint commands leave it out.

**Fix:** Split the pattern, or factor its repeated parts out.
