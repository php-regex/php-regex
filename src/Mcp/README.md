<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=1">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=1">
        <img src="art/banner.png?v=1" alt="PHPRegex MCP" width="100%">
    </picture>
</p>

<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-mcp"><img src="https://img.shields.io/packagist/dt/php-regex/regex-mcp.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-mcp"><img src="https://img.shields.io/packagist/php-v/php-regex/regex-mcp.svg" alt="Supported PHP Version Badge"></a>
</p>

PHPRegex MCP
============

An MCP server for coding agents: validate, explain, format, transpile and judge PCRE patterns for ReDoS, read-only, over stdio.

A coding agent that writes a `preg_match()` call can ask this server whether the pattern compiles on the project's PHP, what it means, how it lays out over lines, how it reads in JavaScript, HTML or Python, and whether it backtracks catastrophically. Every answer comes from the library that judges patterns for a PHP version and the PCRE2 it bundles, and every tool is read-only.

Documentation: [php-regex.com](https://php-regex.com) — the [MCP guide](https://php-regex.com/guides/mcp/) wires the server into Claude Code, Claude Desktop, Cursor, Zed and VS Code.

Features
--------

- Five tools, each a question about one pattern written as in PHP source (`/…/flags`, delimiters included): `regex_validate`, `regex_explain`, `regex_format`, `regex_transpile` and `regex_redos`.
- Every tool answers a text for a client that reads prose, and a structured document for one that reads data: the document `regex <command> --json` prints for the same question, one contract for the CLI and the server.
- An invalid pattern, a construct a dialect cannot express, a limit of the library: a flagged tool result carrying the CLI's error envelope, so the agent reads the message and fixes the pattern. A protocol error is for the protocol only: an unknown tool, an argument the schema refuses.
- Every tool takes `php_version` and `pcre_version`; the server's `--php-version`, `--pcre-version` and `--locale` are the defaults; without them, the running PHP.
- Every tool is read-only and idempotent, and says so to the client; a client may call them without asking.
- The `regex_redos` tool has a confirmed mode that replays the witness through the engine on this machine, bounded by the library's own caps.

Installation
------------

```bash
composer require --dev php-regex/regex-mcp
```

Requires PHP 8.1 or newer with the `fileinfo` extension (and `mbstring`, which the parser needs). The binary is installed as `vendor/bin/regex-mcp`; `--help` prints its options, `--version` the release it was built from. The [regex.phar](https://php-regex.com/guides/cli/) carries the server too: `php regex.phar mcp` starts it with nothing else installed.

Usage
-----

Start the server as a client does, over stdin and stdout:

```bash
vendor/bin/regex-mcp
vendor/bin/regex-mcp --php-version=8.2
vendor/bin/regex mcp --php-version=8.2 --pcre-version=10.40
```

A client configuration names the command. Claude Code:

```bash
claude mcp add php-regex -- /path/to/project/vendor/bin/regex-mcp --php-version=8.2
```

Claude Desktop, Cursor and the others take the same command in their JSON settings; the [MCP guide](https://php-regex.com/guides/mcp/) holds each snippet. Name the binary by its absolute path: a desktop client starts the server with no project directory and no promise about `PATH`.

The tools
---------

| Tool | Arguments | Answers |
|------|-----------|---------|
| `regex_validate` | `pattern` | whether the target PHP compiles the pattern: the error, its byte offset, a hint. An invalid pattern is the answer, not a failure |
| `regex_explain` | `pattern` | the pattern in plain language, construct by construct |
| `regex_format` | `pattern`, `width` (default 80), `compact` | the pattern laid out over lines under `x`, or compacted to one line; the result matches exactly what the pattern given matches |
| `regex_transpile` | `pattern`, `target` (`js`, `html`, `python`), `python_version` | the pattern in the dialect, with every note on what was rewritten and every warning on what matches differently; a construct the dialect cannot express is refused with its reason |
| `regex_redos` | `pattern`, `mode` (`theoretical`, `confirmed`), `threshold` | severity, complexity, the witness that pumps the backtracking, the hotspots, the recommendations |

Every tool also takes `php_version` and `pcre_version`. The structured answer of each tool is the document the CLI prints: `regex validate --json`, `regex explain --json`, `regex format --json`, `regex transpile --json`, and the `redos` member of `regex analyze --json`, all described in the [JSON output reference](https://php-regex.com/reference/json-output/).

A tool call with `/^(a+)+$/` answers, as text:

```text
Exponential backtracking (proven)
Unbounded quantifier detected. May cause backtracking on non-matching input. …
```

and, as structured content, the ReDoS analysis object: `severity`, `complexity`, `witness` (`prefix`, `pump`, `suffix`), `hotspots` and the rest.

Integration
-----------

The server is one class, started by the CLI's `regex mcp` and by `vendor/bin/regex-mcp` alike:

```php
use PHPRegex\Mcp\Application;

exit((new Application())->run(['php_version' => '8.2']));
```

The tools run without the protocol: `ToolCatalog::v1()` lists them, and each one answers a `ToolResult` from an array of arguments, which is how they are tested.

```php
use PHPRegex\Mcp\ToolCatalog;

$result = ToolCatalog::v1(['php_version' => '8.2'])->get('regex_validate')->tool->execute(['pattern' => '/a+/']);
$result->text;       // "Valid."
$result->structured; // ['pattern' => '/a+/', 'validation' => [...]]
$result->isError;    // false
```

The protocol is spoken by the official PHP SDK, [mcp/sdk](https://github.com/modelcontextprotocol/php-sdk), through one class of this package; the SDK is experimental, pinned to one minor, and a change of SDK is a change of that class alone.

Documentation
-------------

- [MCP guide](https://php-regex.com/guides/mcp/) — client configurations, the tools, troubleshooting.
- [JSON output reference](https://php-regex.com/reference/json-output/) — the documents the tools answer.
- [Backward compatibility promise](https://php-regex.com/reference/backward-compatibility/) — what stays stable across releases.

Resources
---------

* [Upgrading from 1.x](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md) — moving from `yoeunes/regex-parser` 1.3 to 2.0, every change that needs one on your side
* [Documentation](https://php-regex.com/docs/)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)
* [Changelog](CHANGELOG.md)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

The server depends on [mcp/sdk](https://packagist.org/packages/mcp/sdk), the official Model Context Protocol SDK for PHP, which is released under the Apache License 2.0; the license is permissive and the code of this package stays MIT.
