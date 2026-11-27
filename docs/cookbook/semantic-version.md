---
description: "The regular expression SemVer suggests, for PHP, proven safe from ReDoS: what it matches, what it rejects, why it is linear, and how to validate a version."
head_title: "PHP regex for semantic versions (SemVer)"
parent: /cookbook/
pattern: "/^(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*)(?:-((?:0|[1-9]\\d*|\\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\\.(?:0|[1-9]\\d*|\\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\\+([0-9a-zA-Z-]+(?:\\.[0-9a-zA-Z-]+)*))?$/"
---
# PHP regex for semantic versions

This pattern accepts a version in the form [SemVer](https://semver.org/) defines: `MAJOR.MINOR.PATCH`, each a number without a leading zero, then an optional pre-release after a hyphen and an optional build metadata after a plus — `1.0.0`, `1.0.0-alpha.1`, `1.0.0-alpha+build.123`. It is the second of the two regular expressions [the specification suggests](https://semver.org/#is-there-a-suggested-regular-expression-regex-to-check-a-semver-string), the one with numbered groups.

It refuses a leading zero, a part missing, an empty pre-release or an empty build metadata, and an underscore: only letters, digits and hyphens make the identifiers of a pre-release or of a build metadata.

```
/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/
```

**Try it.** [Open `/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/` in the playground](/#pattern=%2F%5E%280%7C%5B1-9%5D%5Cd*%29%5C.%280%7C%5B1-9%5D%5Cd*%29%5C.%280%7C%5B1-9%5D%5Cd*%29%28%3F%3A-%28%28%3F%3A0%7C%5B1-9%5D%5Cd*%7C%5Cd*%5Ba-zA-Z-%5D%5B0-9a-zA-Z-%5D*%29%28%3F%3A%5C.%28%3F%3A0%7C%5B1-9%5D%5Cd*%7C%5Cd*%5Ba-zA-Z-%5D%5B0-9a-zA-Z-%5D*%29%29*%29%29%3F%28%3F%3A%5C%2B%28%5B0-9a-zA-Z-%5D%2B%28%3F%3A%5C.%5B0-9a-zA-Z-%5D%2B%29*%29%29%3F%24%2F&subject=1.0.0): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ 1.0.0
✓ 2.10.5
✓ 10.0.0
✓ 1.0.0-alpha
✓ 1.0.0-alpha.1
✓ 1.0.0+build.123
✓ 1.0.0-alpha+build.123
✓ 1.0.0-x.7.z.92+exp.sha.5114f85  (a pre-release of several identifiers, then build metadata)
```

## What it rejects

```
✗ 01.0.0  (leading zero)
✗ 1.0  (missing patch)
✗ 1.0.0-  (empty pre-release)
✗ 1.0.0-0.  (invalid pre-release segment)
✗ 1.0.0-01  (leading zero in a numeric identifier)
✗ 1.0.0-alpha_1  (an underscore is not allowed)
✗ v1.0.0  (a leading v)
```

## Why it is safe

The linter reports two things on this pattern: `regex.lint.quantifier.nested`, on the nested repeats of the pre-release part, and `regex.lint.overlap.charset`, on the alternation of that part, whose branches `0` and `\d*[a-zA-Z-][0-9a-zA-Z-]*` both begin with a digit. Both are structural warnings. The analyzer proves that no input makes a match attempt backtrack beyond a linear number of steps: its verdict is `safe (proven)`.

## How to validate a semantic version in PHP

The groups are numbered: the three numbers, then the pre-release, then the build metadata.

```php
$pattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

$version = '1.2.3-rc.1+build.5';
preg_match($pattern, $version, $matches);
var_dump($matches[1], $matches[4], $matches[5]); // string(1) "1"\nstring(4) "rc.1"\nstring(7) "build.5"

var_dump(preg_match($pattern, '01.0.0')); // int(0)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own.
