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
 * An invalid pattern, a refusal or a limit is answered as a flagged tool
   result carrying the CLI's error envelope, never as a protocol error.
