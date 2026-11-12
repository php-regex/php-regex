CHANGELOG
=========

2.0
---

 * First release as its own package; see the
   [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * An MCP server over stdio, `regex mcp` or `vendor/bin/regex-mcp`, serving
   five read-only tools: `regex_validate`, `regex_explain`, `regex_format`,
   `regex_transpile` and `regex_redos`. Each tool answers a text and the
   structured document the CLI prints for the same question with `--json`.
 * Every tool takes `php_version` and `pcre_version`; the server's
   `--php-version`, `--pcre-version` and `--locale` are the defaults.
 * `php_version`, as a tool argument and as the server's `--php-version`,
   takes a Composer constraint (`">=8.1"`, `"^8.1 <8.7"`) as well as a single
   version. The tool's answer is computed for the lowest version, and the
   pattern is checked at every later version where one of the library's
   rules changes. The server reads no file from its working directory.
 * Every structured answer ends with `target`, the PHP versions judged
   (`php_target` for `regex_transpile`, whose `target` is the dialect), and
   `range`, the verdict of each version on the pattern. The output schemas
   declare both.
 * Over a range, the text answer says what the later versions find in a
   pattern the lowest accepts, in the words of `regex lint`: an `Error:`
   line for the lowest version that refuses it, else a `Warning:` line for
   the lowest that reads it otherwise or matches it otherwise, then a
   `Note:` line for each change the target's resolution made, as a range
   clipped to the PHP versions on PCRE2. The answer stays a success: the
   lowest version accepts the pattern.
 * An invalid pattern, a refusal or a limit is answered as a flagged tool
   result carrying the CLI's error envelope, never as a protocol error.
