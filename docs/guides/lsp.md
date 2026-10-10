---
description: "Point any LSP editor at the PHPRegex language server: install it, configure VS Code, PhpStorm, Neovim or Zed, pick its target PHP and PCRE2, and debug it."
---

# LSP Integration Guide

This guide covers PHPRegex's Language Server Protocol (LSP) server and how to integrate it with your IDE for real-time regex analysis.

---

## Overview

The PHPRegex LSP server provides:

- **Real-time diagnostics** - Parse errors, validation issues, and lint warnings
- **Hover information** - Pattern explanation on mouse hover
- **Code actions** - Quick fixes for common issues
- **Completions** - Regex syntax suggestions and documentation

---

## Quick Start

The server requires PHP 8.2 or later.

{% include install-prerelease.html package="php-regex/regex-language-server" %}

The server binary is `vendor/bin/regex-lsp` (the monorepo install provides it
too: Composer links it from `src/LanguageServer/bin/regex-lsp`). Run it with
`--help` for its options, `--version` for the release it was built from:

```bash
vendor/bin/regex-lsp --help
```

### Configure Your IDE

See the IDE-specific sections below for configuration instructions.

---

## Features

### Real-time Diagnostics

The LSP server analyzes PHP files and reports regex issues as you type:

| Diagnostic Type | Description |
|-----------------|-------------|
| Parse Errors | Invalid regex syntax |
| Validation Errors | PCRE compatibility issues |
| Unicode Errors | Missing `/u` flag: a property or an escape above U+FF fails as an error |
| Style Issues | Anti-patterns and best practice violations, as ASCII-only shorthands without `/u` — information level, off by default |
| Performance Hints | Lint warnings on shapes that backtrack, as nested quantifiers; the ReDoS verdict itself comes from `regex analyze`, `regex lint --redos` or PHPStan |

**Example diagnostics:**

```
Info: Shorthand "\w" matches only ASCII without /u flag.
Error: Without the /u flag, Unicode property "\p{L}" only covers the first 256 code points.
Error: Unicode escape "\x{100}" requires /u flag for code points > U+FF.
```

### Hover Information

Hover over any regex pattern to see:

- The complete pattern with syntax highlighting
- A plain-English explanation of what it matches
- Flag documentation

**Example:**

```
**Regex Pattern**

`/^[a-z]+@[a-z]+\.[a-z]+$/i`

**Explanation**

Start of string
  One or more characters from: a-z
  Literal '@'
  One or more characters from: a-z
  Literal '.'
  One or more characters from: a-z
End of string (case-insensitive)
```

### Code Actions

Quick fixes appear when issues are detected:

| Code Action | Description |
|-------------|-------------|
| Add `/u` flag | Fix Unicode-related warnings |
| Apply optimization | Simplify or improve the pattern |

### Completions

Context-aware completions for:

- **Character classes** - `\d`, `\w`, `\s`, `\h`, `\v`, etc.
- **Anchors** - `^`, `$`, `\b`, `\A`, `\Z`, `\z`, etc.
- **Quantifiers** - `+`, `*`, `?`, `{n}`, `{n,m}`, etc.
- **Groups** - `(?:...)`, `(?=...)`, `(?!...)`, `(?>...)`, etc.
- **Unicode properties** - `\p{L}`, `\p{N}`, `\p{Script=Latin}`, etc.
- **POSIX classes** - `[:alpha:]`, `[:digit:]`, `[:space:]`, etc.
- **Flags** - `i`, `m`, `s`, `x`, `u`, etc.

---

## Target PHP and PCRE2

Whether a pattern compiles depends on the PCRE2 release and, for a few rules,
on the PHP version. The server judges every pattern of the workspace for one
target, chosen once, when the editor sends `initialize`:

1. `initializationOptions.phpVersion` and `initializationOptions.pcreVersion`
   sent by the editor;
2. `phpVersion` and `pcreVersion` in `regex.json` (and `regex.dist.json`) at
   the root folder;
3. `composer.json` at the root folder: `config.platform.php` if set, else the
   lowest version `require.php` allows;
4. the PHP running the server.

The root folder is the first entry of `workspaceFolders`, else `rootUri`.
Each version is chosen on its own: without a PCRE2 release, the server uses
the one the target PHP bundles (10.40 for PHP 8.2, 10.42 for 8.3, 10.44 for
8.4 and 8.5), or the PCRE2 of the running PHP when the target is the running
PHP.

```json
{
  "initializationOptions": {
    "phpVersion": "8.2",
    "pcreVersion": "10.40"
  }
}
```

`phpVersion` takes `"8.2"`, `"8.2.4"` or `80200`; `pcreVersion` takes a
release such as `"10.42"`. A value the server cannot read is logged as a
warning and the next source is used.

The server logs the target once through `window/logMessage`, where most
editors show the language server output:

```text
Target: PHP 8.2, PCRE2 10.40 (initializationOptions)
```

What it noticed on the way, such as a `composer.json` without `require.php`,
comes in messages of its own. Changing the target takes a restart of the
server.

---

## Functions Marked `#[RegexPattern]`

A parameter marked with the attribute `PHPRegex\Parser\Attribute\RegexPattern`,
or with PhpStorm's `#[Language('RegExp')]`, makes its function or static method
a pattern function, as for `regex lint` (see
[the CLI guide](cli.md#patterns-behind-a-wrapper)): the string literal passed at that
argument is checked like the pattern of a `preg_*()` call.

```php
namespace App\Support;

use PHPRegex\Parser\Attribute\RegexPattern;

final class Str
{
    public static function matches(string $subject, #[RegexPattern] string $regex): bool
    {
        return 1 === preg_match($regex, $subject);
    }
}

Str::matches($input, '/(a/'); // reported: missing closing parenthesis
```

The server finds the declarations in two places:

- the PHP files of the workspace, read once at `initialize`: those under
  `paths` in `regex.json` (the root folder when unset), but those under an
  `exclude` entry (`vendor` when unset) and templates (`.blade.php`,
  `.tpl.php`, `.twig.php`). Only a file that names
  `PHPRegex\Parser\Attribute` or `JetBrains\PhpStorm\Language` is tokenized;
  the scan stops after 20,000 PHP files and logs a warning, so a larger
  workspace narrows `paths` or `exclude`;
- the open documents, read on every change: an open document stands for its
  file, saved or not, and when its declarations change, the other open
  documents are checked again.

A saved document (`textDocument/didSave`) and a file the editor reports as
created, changed or deleted (`workspace/didChangeWatchedFiles`, when the
editor is set to send it) are read again. A file changed outside the editor
that it does not report keeps its declarations until the server restarts.

The calls are read as `regex lint` reads them: function calls and static
calls, their names resolved through the namespace and the `use` imports; an
unqualified call in the function's own namespace, `grep()` in
`namespace App`, is read, as PHP calls `App\grep()` first. An instance call
(`$str->matches(...)`), a call through `self::` or `static::`, and an argument
that is not one string literal (a concatenation, a variable, a named
argument) are not read. `extraction.functions` in `regex.json` is not read by
the server.

---

## IDE Configuration

### VS Code

VS Code has no setting that points at an arbitrary language server: the
supported path is a small extension built on the
[vscode-languageclient](https://www.npmjs.com/package/vscode-languageclient)
npm package. The official
[Language Server Extension Guide](https://code.visualstudio.com/api/language-extensions/language-server-extension-guide)
walks through the whole process; for PHPRegex, the client boils down to:

```ts
// src/extension.ts
import * as vscode from 'vscode';
import { LanguageClient, TransportKind } from 'vscode-languageclient/node';

const server = {
  command: 'vendor/bin/regex-lsp', // resolved from the workspace root
  transport: TransportKind.stdio,
};

export function activate(context: vscode.ExtensionContext): void {
  const client = new LanguageClient(
    'php-regex',
    'PHPRegex',
    { run: server, debug: server },
    { documentSelector: [{ scheme: 'file', language: 'php' }] },
  );

  context.subscriptions.push(client.start());
}
```

Installing a PHP editor extension such as Intelephense or phpactor is not an
alternative: those are language servers of their own, not clients that host
others, so they cannot run `regex-lsp` for you.

### PhpStorm / IntelliJ IDEA

Use the [LSP4IJ](https://plugins.jetbrains.com/plugin/23257-lsp4ij) plugin by Red Hat for LSP support:

1. Install LSP4IJ from the JetBrains Marketplace

2. Go to **Settings → Languages & Frameworks → Language Servers**

3. Click **+** to add a new server definition:
   - **Name:** PHPRegex
   - **Command:** `vendor/bin/regex-lsp`
   - **File Mappings:** `*.php`

4. The server will now provide diagnostics, hover, and code actions for regex patterns

**Alternative: PHPStan Integration**

PHPRegex also integrates with PHPStan (see [the PHPStan guide](phpstan.md)):

1. Install the [PHPStan plugin](https://plugins.jetbrains.com/plugin/12754-phpstan--psalm--generics) for PhpStorm
2. Configure PHPStan in **Settings → PHP → Quality Tools → PHPStan**
3. Enable real-time inspection

This provides the same regex diagnostics through PHPStan's analysis

### Neovim

#### Using nvim-lspconfig

Add to your `init.lua`:

```lua
local lspconfig = require('lspconfig')
local configs = require('lspconfig.configs')

-- Define the php-regex LSP server
if not configs.php_regex then
  configs.php_regex = {
    default_config = {
      cmd = { 'vendor/bin/regex-lsp' },
      filetypes = { 'php' },
      root_dir = lspconfig.util.root_pattern('composer.json', '.git'),
      settings = {},
    },
  }
end

-- Enable the server
lspconfig.php_regex.setup({
  on_attach = function(client, bufnr)
    -- Your on_attach function
  end,
})
```

#### Using coc.nvim

Add to `coc-settings.json`:

```json
{
  "languageserver": {
    "php-regex": {
      "command": "vendor/bin/regex-lsp",
      "filetypes": ["php"],
      "rootPatterns": ["composer.json", ".git"]
    }
  }
}
```

### Vim (with vim-lsp)

Add to your `.vimrc`:

```vim
if executable('vendor/bin/regex-lsp')
    au User lsp_setup call lsp#register_server({
        \ 'name': 'php-regex',
        \ 'cmd': {server_info->['vendor/bin/regex-lsp']},
        \ 'allowlist': ['php'],
        \ })
endif
```

### Emacs (with lsp-mode)

Add to your Emacs config:

```elisp
(require 'lsp-mode)

(add-to-list 'lsp-language-id-configuration '(php-mode . "php"))

(lsp-register-client
 (make-lsp-client
  :new-connection (lsp-stdio-connection '("vendor/bin/regex-lsp"))
  :major-modes '(php-mode)
  :server-id 'php-regex))
```

### Sublime Text (with LSP package)

1. Install the [LSP](https://packagecontrol.io/packages/LSP) package

2. Add to **Preferences → Package Settings → LSP → Settings**:

```json
{
  "clients": {
    "php-regex": {
      "enabled": true,
      "command": ["vendor/bin/regex-lsp"],
      "selector": "source.php"
    }
  }
}
```

### Helix

Add to `~/.config/helix/languages.toml`:

```toml
[[language]]
name = "php"
language-servers = ["intelephense", "php-regex"]

[language-server.php-regex]
command = "vendor/bin/regex-lsp"
```
{: data-file="~/.config/helix/languages.toml" }

### Zed

Add to your settings:

```json
{
  "lsp": {
    "php-regex": {
      "binary": {
        "path": "vendor/bin/regex-lsp"
      }
    }
  },
  "languages": {
    "PHP": {
      "language_servers": ["intelephense", "php-regex"]
    }
  }
}
```

---

## LSP Protocol Details

### Supported Methods

| Method | Description |
|--------|-------------|
| `initialize` | Server capabilities negotiation |
| `initialized` | Initialization complete |
| `shutdown` | Graceful shutdown request |
| `exit` | Server exit |
| `textDocument/didOpen` | Document opened |
| `textDocument/didChange` | Document changed |
| `textDocument/didClose` | Document closed |
| `textDocument/didSave` | Document saved: its declarations are read again |
| `workspace/didChangeWatchedFiles` | Files changed outside the editor: their declarations are read again |
| `textDocument/hover` | Hover information |
| `textDocument/codeAction` | Code actions (quick fixes) |
| `textDocument/completion` | Completion suggestions |
| `textDocument/publishDiagnostics` | Push diagnostics to client |

### Server Capabilities

```json
{
  "capabilities": {
    "textDocumentSync": {
      "openClose": true,
      "change": 1,
      "save": { "includeText": true }
    },
    "hoverProvider": true,
    "codeActionProvider": {
      "codeActionKinds": ["quickfix", "refactor.rewrite"]
    },
    "completionProvider": {
      "triggerCharacters": ["\\", "[", "(", "/"],
      "resolveProvider": false
    }
  }
}
```

---

## Troubleshooting

### Server Not Starting

1. Verify the binary exists:

   ```bash
   ls -la vendor/bin/regex-lsp
   ```

2. Check it's executable:

   ```bash
   chmod +x vendor/bin/regex-lsp
   ```

3. Test it directly. The server speaks LSP over stdio, where a message is a
   `Content-Length` header followed by its JSON body — a bare `echo` prints
   nothing: the server reads headers, finds no `Content-Length`, and exits
   without a message to answer. Frame the message:

   ```bash
   MSG='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"capabilities":{},"rootUri":null}}'
   printf 'Content-Length: %d\r\n\r\n%s' "$(printf '%s' "$MSG" | wc -c)" "$MSG" \
     | vendor/bin/regex-lsp
   ```

   The server answers with its capabilities, then logs its target, and exits
   when the input ends:

   ```text
   Content-Length: 369

   {"jsonrpc":"2.0","id":1,"result":{"capabilities":{"textDocumentSync":{"openClose":true,"change":1,"save":{"includeText":true}},"hoverProvider":true,"codeActionProvider":{"codeActionKinds":["quickfix","refactor.rewrite"]},"completionProvider":{"triggerCharacters":["\\","[","(","/"],"resolveProvider":false}},"serverInfo":{"name":"php-regex-lsp","version":"2.0.0-DEV"}}}
   Content-Length: 124

   {"jsonrpc":"2.0","method":"window/logMessage","params":{"type":3,"message":"Target: PHP 8.4.26, PCRE2 10.49 (running PHP)"}}
   ```

   The `Target:` line is the PHP and PCRE2 release the server judges for (see
   [Target PHP and PCRE2](#target-php-and-pcre2)). Silence here means the
   framing is wrong, not that the server is broken.

### No Diagnostics Appearing

1. Ensure the file contains `preg_*` function calls
2. Check that the file is saved (some editors require saved files)
3. Verify the server is running:
   ```bash
   ps aux | grep regex-lsp
   ```

### Diagnostics Not Updating

The server uses full document sync. If you see stale diagnostics:

1. Save the file
2. Close and reopen the file
3. Restart the LSP server

### High CPU Usage

If the server uses excessive CPU:

1. Exclude large files or directories
2. Check for infinite loops in patterns

### Connection Issues

For stdio-based connections:

1. Ensure no output is written to stdout before the server starts
2. Check that stderr is not redirected to stdout
3. Verify the Content-Length header format

---

## Advanced Configuration

### Custom Regex Detection

The LSP server automatically detects regex patterns in:

- `preg_match()` and `preg_match_all()`
- `preg_replace()` and `preg_replace_callback()`
- `preg_split()`
- `preg_grep()`
- `preg_filter()`
- wrapper calls such as `Preg::match()`
- the calls to the [functions marked `#[RegexPattern]`](#functions-marked-regexpattern)

Both single-quoted and double-quoted strings are supported.

### Performance Tuning

For large codebases:

1. Use workspace folders to limit the scope
2. Configure file watching exclusions in your IDE
3. Consider disabling real-time diagnostics for non-PHP files

---

## Diagnostic Codes

Each lint rule's severity is sent as the LSP diagnostic severity: `critical` and `error`
as Error, `warning` as Warning, and `style`, `perf` and `info` as Information, the same
mapping as `regex lint` (see [Severity in Each Format](../reference/diagnostics.md#severity-in-each-format)).
No diagnostic is sent as Hint, which editors tend to show faintly or not at all.

| Code | Severity | Description |
|------|----------|-------------|
| `regex.<area>.<problem>` | Error | A pattern PCRE refuses, with its [error code](../reference/diagnostics.md#error-codes), such as `regex.group.unclosed` |
| `regex.lint.unicode.shorthandWithoutU` | Information | `\w`, `\d`, `\s` without `/u` |
| `regex.lint.unicode.propertyWithoutU` | Error | `\p{L}` without `/u` |
| `regex.lint.unicode.bracedHexWithoutU` | Error | `\x{100}` without `/u` |
| `regex.lint.unicode.multibyteInClassWithoutU` | Error | `[é]` without `/u` |
| `regex.lint.unicode.quantifiedMultibyteWithoutU` | Error | `é+` without `/u` |
| `regex.lint.group.quantifiedCapture` | Information, Warning for a named group | `(a)+` keeps only the last iteration |
| `regex.lint.group.alwaysEmptyCapture` | Warning | `a+(a*)`: `$1` is always empty |
| `regex.lint.charclass.single` | Information | `[a]` is `a` (style, off by default) |
| `regex.lint.literal.multipleSpaces` | Information | a run of literal spaces, ` {2}` (style, off by default) |
| `regex.lint.quantifier.uselessLazy` | Information | `(a+?)b` matches as `(a+)b` (style, off by default) |
| `regex.lint.lookaround.edgeQuantifier` | Information | `(?=a{2,6})` asserts what `(?=a{2})` asserts (perf, off by default) |
| `regex.lint.quantifier.lazyToClass` | Information | `".*?"` where `"[^"\n]*"` reads the run at once (perf, off by default) |
| other `regex.lint.*` | Warning | The [lint rules](../reference/rules.md#quick-reference-table) |

---

## Learn More

- **[CLI Guide](cli.md)** - Command-line usage
- **[Diagnostics Reference](../reference/diagnostics.md)** - All diagnostic codes
- **[PCRE Reference](../concepts/pcre.md)** - PCRE compatibility

New to regex? Start with [the tutorial](../tutorial/README.md).

---

## Contributing

Found a bug or want to add a feature? Contributions are welcome!

1. Fork the repository
2. Create a feature branch
3. Submit a pull request
