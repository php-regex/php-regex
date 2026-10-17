CHANGELOG
=========

2.0
---

 * First release: `PregMatchToStringComparisonRector`, `PregReplaceToStrReplaceRector`,
   `PregSplitToExplodeRector` and the `RegexSetList::STRING_FUNCTIONS` set;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `EscapeLiteralBraceRector` and the `RegexSetList::PCRE_UPGRADE` set: a
   brace PCRE2 before 10.43 reads as text and PHP 8.4's PCRE2 as a quantifier
   is escaped, `/a{,3}/` to `/a\{,3}/`, for code that targets a PHP below 8.4.
   The rule judges every PHP from the one the code targets to the newest the
   library knows, with no configuration, and leaves a pattern any of them
   refuses. It also escapes the brace of `/a{65536/`, which PHP 8.0 and
   earlier refuse and PHP 8.1 reads as text.
 * `AlphabeticAssertionToClassicRector` and `EscapeNulByteRector`, in the
   `RegexSetList::PCRE_UPGRADE` set: `/(*pla:a)b/`, which PHP 7.3 refuses,
   becomes `/(?=a)b/`, and a NUL byte, which PHP 8.1 and earlier refuse,
   `\x00`, for code that targets such a PHP.
 * The rules of `RegexSetList::PCRE_UPGRADE` read the keys of the array
   `preg_replace_callback_array()` takes and the arguments a function or a
   static method marks with `#[RegexPattern]` or `#[Language('RegExp')]`.
   The package requires `phpstan/phpstan`, which Rector runs on, for the
   attributes of a parameter.
 * `PossessiveRepeatOnceRector`, in the `RegexSetList::PCRE_UPGRADE` set:
   `/(a|ab){1}+c/`, which PHP 7.3 and 7.4.0 to 7.4.5 read as `/(a|ab)c/` and
   7.4.6 and later as atomic, becomes `/(a|ab){1}c/` for code that targets
   such a PHP.
