CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `TextExplainer` and `AsciiTreeRenderer` write a comment, a callout string or a
   verb's name in display form: a control character is spelled out, so an explanation
   keeps one line per construct and sends the terminal no escape sequence.
 * `ConsoleHighlighter` spells a control character, a hidden character or an
   invalid byte of a token as an escape before colouring it.
