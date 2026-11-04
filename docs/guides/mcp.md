---
description: "Give a coding agent the regex judgement of PHPRegex over the Model Context Protocol: install the server, wire it into Claude Code, Claude Desktop, Cursor, Zed or VS Code, read what each tool answers, and debug it."
---

# MCP Server Guide

This guide covers the PHPRegex MCP server, which serves the library's read-only questions about a pattern to any client of the Model Context Protocol: a coding agent that writes a `preg_match()` call can ask whether the pattern compiles on your PHP, what it means, how it lays out, how it reads in JavaScript, HTML or Python, and whether it backtracks catastrophically.

<div class="page-filter">
  <label class="visually-hidden" for="page-filter-input">Filter the MCP guide</label>
  <input type="search" id="page-filter-input" data-unit="sections" placeholder="Filter by section — e.g. install, Claude Code, tools, target" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>Nothing matches that filter — try a shorter fragment, or press Escape to see the whole page again.</p>

---

## Overview

The server speaks the Model Context Protocol over stdin and stdout, one JSON document per line, and serves five tools:

| Tool | Question |
|------|----------|
| `regex_validate` | Does the target PHP compile this pattern? With the error, its byte offset and a hint when it does not |
| `regex_explain` | What does this pattern match, in plain language? |
| `regex_format` | How does this pattern lay out over lines, or compact to one? |
| `regex_transpile` | How does this pattern read in JavaScript, in an HTML `pattern` attribute, in Python, and what is lost on the way? |
| `regex_redos` | Does this pattern backtrack catastrophically? With the witness input, the hotspots and the recommendations |

Every tool is read-only and idempotent, and tells the client so. Every tool takes the pattern as written in PHP source, delimiters and flags included (`/^\d+$/i`), and judges it for a PHP version and the PCRE2 release it bundles.

---

## Quick Start

The server requires PHP 8.1 or later with the `fileinfo` extension, and `mbstring`, which the parser needs.

{% include install.html package="php-regex/regex-mcp" dev=true %}

The binary is `vendor/bin/regex-mcp`; `--help` prints its options, `--version` the release it was built from:

```bash
vendor/bin/regex-mcp --help
```

The [phar](cli.md) carries the server too: `php regex.phar mcp` runs it, one download for any client, with nothing installed in the project. Wherever the sections below name `vendor/bin/regex-mcp`, the phar's command works the same way, as does `vendor/bin/regex mcp`.

Then name the command in your client's configuration, as the next section shows for each client.

---

## Client Configuration

Name the binary by its absolute path: a desktop client starts the server with no project directory and no promise about `PATH`. The `--php-version` option makes every answer a verdict for your project's PHP.

### Claude Code

```bash
claude mcp add php-regex -- /path/to/project/vendor/bin/regex-mcp --php-version=8.2
```

Or in the project's `.mcp.json`, shared with the team:

```json
{
  "mcpServers": {
    "php-regex": {
      "command": "/path/to/project/vendor/bin/regex-mcp",
      "args": ["--php-version=8.2"]
    }
  }
}
```

### Claude Desktop

In `claude_desktop_config.json` (Settings, Developer, Edit Config):

```json
{
  "mcpServers": {
    "php-regex": {
      "command": "php",
      "args": ["/path/to/regex.phar", "mcp", "--php-version=8.2"]
    }
  }
}
```

### Cursor

In `.cursor/mcp.json` at the project root, or in the global settings:

```json
{
  "mcpServers": {
    "php-regex": {
      "command": "/path/to/project/vendor/bin/regex-mcp",
      "args": ["--php-version=8.2"]
    }
  }
}
```

### Zed

In `settings.json`, under `context_servers`:

```json
{
  "context_servers": {
    "php-regex": {
      "command": {
        "path": "/path/to/project/vendor/bin/regex-mcp",
        "args": ["--php-version=8.2"]
      }
    }
  }
}
```

### VS Code

In `.vscode/mcp.json`:

```json
{
  "servers": {
    "php-regex": {
      "type": "stdio",
      "command": "/path/to/project/vendor/bin/regex-mcp",
      "args": ["--php-version=8.2"]
    }
  }
}
```

---

## The Tools

Each tool answers two things: a text, for a client that reads prose, and a structured document, for one that reads data. The document is the one the CLI prints for the same question with `--json`, so the [JSON output reference](../reference/json-output.md) describes every key.

### `regex_validate`

| Argument | Meaning |
|----------|---------|
| `pattern` | The pattern as written in PHP source, delimiters and flags included |
| `php_version`, `pcre_version` | The target to judge for; see [Target PHP and PCRE2](#target-php-and-pcre2) |

The document of `regex validate --json`: the pattern and its [validation](../reference/json-output.md#validation-validation) object. An invalid pattern is the answer, not a failure of the tool: `is_valid` is false, `error` says why, `offset` where, `hint` how to fix it.

```text
Expected ) at end of input (found eof).
```

### `regex_explain`

| Argument | Meaning |
|----------|---------|
| `pattern` | The pattern |
| `php_version`, `pcre_version` | The target |

The document of `regex explain --json`: the pattern and its explanation, the text the CLI prints.

### `regex_format`

| Argument | Meaning |
|----------|---------|
| `pattern` | The pattern |
| `width` | The width a line aims at, indent included; 80 by default |
| `compact` | `true` to write the pattern on one line, without `x`, layout or comments |
| `php_version`, `pcre_version` | The target |

The document of `regex format --json`: the pattern laid out or compacted, whether it changed, and the reason when it was given back as written. The result matches exactly what the pattern given matches.

### `regex_transpile`

| Argument | Meaning |
|----------|---------|
| `pattern` | The pattern |
| `target` | `js`, `html` or `python` |
| `python_version` | The Python release the pattern runs on, as `3.11`, which carries atomic groups |
| `php_version`, `pcre_version` | The target |

The document of `regex transpile --json`: the pattern in the dialect, as a literal and as a constructor call, the notes on what was rewritten to match the same, the warnings on what matches differently. A construct the dialect cannot express is refused with its reason, never approximated:

```text
Atomic groups are not supported in JavaScript.
```

### `regex_redos`

| Argument | Meaning |
|----------|---------|
| `pattern` | The pattern |
| `mode` | `theoretical` (default) reads the pattern; `confirmed` also replays the witness through the engine on this machine, under the library's own caps |
| `threshold` | The lowest severity confirmed mode replays: `low`, `medium`, `high` or `critical` |
| `php_version`, `pcre_version` | The target |

The `redos` member of the document `regex analyze --json` prints, the [ReDoS analysis](../reference/json-output.md#redos-analysis-redos_analysis) object: `severity`, `complexity`, the `witness` that pumps the backtracking (`prefix`, `pump`, `suffix`), the `hotspots` in the pattern, the `recommendations`, and in confirmed mode the `confirmation` with its samples. The text reads the headline then the recommendations:

```text
Exponential backtracking (proven)
Nested unbounded quantifiers detected. This allows exponential backtracking. …
```

The [ReDoS guide](redos.md) explains the verdicts and their guarantees.

---

## Target PHP and PCRE2

Every answer is a verdict for one PHP version and one PCRE2 release, resolved in this order:

1. `php_version` and `pcre_version` in the tool call;
2. `--php-version`, `--pcre-version` and `--locale` given to `regex-mcp` or `regex mcp`;
3. the PHP running the server, and the PCRE2 it bundles.

A PHP of 7.2 or earlier is refused: it ships PCRE1, which the library does not judge. The server reads no `regex.json` and no `composer.json`: a client started from another directory would read the wrong project's, so the target is what the command line and the call say.

---

## Errors

An invalid pattern, a construct a dialect cannot express, a width below one, a target the library does not judge for: each is answered as a flagged tool result (`isError`), whose text is the message and whose structured content is the CLI's [error envelope](../reference/json-output.md#the-error-envelope), with the pattern's validation when the pattern is what failed. The agent reads the message and fixes the pattern.

A protocol error is for the protocol only: an unknown tool name, or an argument the tool's schema refuses (an unknown key, a `mode` outside `theoretical` and `confirmed`), is answered as a JSON-RPC error by the server. A tool that fails for another reason is answered as an internal error and the server keeps serving.

---

## Troubleshooting

### The server does not start

Run the command the client runs, by hand, and send it one line:

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"shell","version":"0"}}}' | vendor/bin/regex-mcp
```

One JSON line comes back, with `"serverInfo":{"name":"php-regex"}`. A message about `mcp/sdk` instead means the SDK is not installed where the command runs: `composer require --dev php-regex/regex-mcp` installs it beside the package, and the phar carries it.

### Stdout must stay clean

The protocol is stdout: a byte of anything else there ends the session. The server sends PHP's warnings and its own log to stderr, where every client shows them. A PHP extension or an `auto_prepend_file` that prints on start-up breaks the channel: run the command by hand, as above, and read what comes before the first `{`.

### The answers judge the wrong PHP

Without `--php-version`, the server judges for the PHP that runs it, which is the one the client started, not the project's: name the project's PHP on the command line, or in each call.

---

## Related Pages

- **[CLI guide](cli.md)** — the commands whose documents the tools answer
- **[JSON output reference](../reference/json-output.md)** — every key of every document
- **[ReDoS guide](redos.md)** — what the verdicts guarantee
- **[LSP guide](lsp.md)** — the same judgement in an editor
