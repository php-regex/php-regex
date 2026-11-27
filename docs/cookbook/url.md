---
description: "A PHP regex for http and https URLs, proven safe from ReDoS: what it accepts, what it refuses, why it is linear, and how to validate a URL in PHP."
head_title: "PHP regex for URL validation: a proven pattern"
parent: /cookbook/
pattern: "/^https?:\\/\\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(?::\\d{1,5})?(\\/[^\\s]*)?$/i"
---
# PHP regex for URLs

This pattern accepts an address that starts with `http://` or `https://`, goes on with a host that begins and ends with a letter or a digit (dots and hyphens may stand between), and may end with a port of one to five digits and a path that holds no whitespace — query string and fragment included. It refuses other schemes, a missing host, and any space.

The `i` flag lets capitals through in the scheme and the host. The pattern checks the shape of each part and no more: a port above 65535 (`https://example.com:99999`) passes, while a user name before the host (`https://user@example.com`) or an IPv6 address in brackets (`http://[::1]/`) is refused.

```
/^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?(\/[^\s]*)?$/i
```

**Try it.** [Open `/^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?(\/[^\s]*)?$/i` in the playground](/#pattern=%2F%5Ehttps%3F%3A%5C%2F%5C%2F%5Ba-z0-9%5D%28%5Ba-z0-9.-%5D*%5Ba-z0-9%5D%29%3F%28%3F%3A%3A%5Cd%7B1%2C5%7D%29%3F%28%5C%2F%5B%5E%5Cs%5D*%29%3F%24%2Fi&subject=https%3A%2F%2Fexample.com): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ https://example.com
✓ http://sub.domain.com/path
✓ https://example.com/path/to/page?query=value
✓ http://localhost:8080
✓ http://127.0.0.1:8000
```

## What it rejects

```
✗ ftp://example.com  (not http or https)
✗ https://  (missing host)
✗ https://exam ple.com  (space in URL)
✗ example.com  (no scheme)
✗ https://example.com:abc  (a port is made of digits)
```

## Why it is safe

- The pattern accepts `http` or `https`, then `://`.
- The host starts with an alphanumeric character and allows dots or hyphens; an optional port is bounded to at most five digits.
- The optional path is limited to non-space characters.
- The repeats are bounded by character classes and string anchors: no input makes the engine retry a long subject in many ways, and the analyzer's verdict is `safe (proven)`.

## How to validate a URL in PHP

`preg_match()` is all the check needs. The example also asks PHPRegex for its two answers on the pattern: whether it is valid, and whether a match on it can blow up in time. It uses the `Regex` facade of the `php-regex/regex-toolkit` package, whose install command the [cookbook](../cookbook.md) gives.

```php
use PHPRegex\Toolkit\Regex;

$url = 'https://example.com/path?query=1';
$pattern = '/^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?(\/[^\s]*)?$/i';

$regex = Regex::create();
$result = $regex->validate($pattern);
echo $result->isValid ? 'Valid pattern' : 'Invalid pattern';  // Valid pattern

$isMatch = preg_match($pattern, $url) === 1;
echo $isMatch ? 'Matches' : 'Does not match';                 // Matches
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own.
