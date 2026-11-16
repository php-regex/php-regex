CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * The explanations read the `U` flag and `(?U)`, which swap greed: `/a+/U`
   says "one or more times (as few as possible)", and `/a+?/U` no longer
   does, as `preg_match()` takes `a` from `aaa` with the one and `aaa` with
   the other.
 * `TextExplainer` and `AsciiTreeRenderer` stop at 8 MiB of output and throw a
   `ResourceLimitException` with the error code `regex.rendering.output_too_large`,
   so a short pattern whose nesting multiplies the indentation cannot exhaust memory.
 * `TextExplainer` and `AsciiTreeRenderer` write a comment, a callout string or a
   verb's name in display form: a control character is spelled out, so an explanation
   keeps one line per construct and sends the terminal no escape sequence.
 * `ConsoleHighlighter` spells a control character, a hidden character or an
   invalid byte of a token as an escape before colouring it.
