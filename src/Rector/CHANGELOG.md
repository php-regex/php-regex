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
