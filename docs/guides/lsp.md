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

### 1. Install PHPRegex

```bash
composer require --dev php-regex/regex-language-server
```

### 2. Locate the LSP Server

The LSP server is available at:

```bash
vendor/bin/regex-lsp
```

Or via the PHAR:

```bash
regex-lsp
```

### 3. Configure Your IDE

See the IDE-specific sections below for configuration instructions.

---

## Features

### Real-time Diagnostics

The LSP server analyzes PHP files and reports regex issues as you type:

| Diagnostic Type | Description |
|-----------------|-------------|
| Parse Errors | Invalid regex syntax |
| Validation Errors | PCRE compatibility issues |
| Unicode Warnings | Missing `/u` flag for Unicode features |
| Style Issues | Anti-patterns and best practice violations |
| Performance Hints | Lint warnings on shapes that backtrack, as nested quantifiers; the ReDoS verdict itself comes from `regex analyze`, `regex lint --redos` or PHPStan |

**Example diagnostics:**

```
Warning: Shorthand "\w" matches only ASCII without /u flag.
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

## IDE Configuration

### VS Code

1. Install the **PHP Intelephense** or **phpactor** extension (or any LSP client extension)

2. Create `.vscode/settings.json`:

```json
{
  "lsp.servers": {
    "php-regex": {
      "command": ["vendor/bin/regex-lsp"],
      "filetypes": ["php"]
    }
  }
}
```

**Alternative: Using a generic LSP client**

Install [vscode-languageclient](https://marketplace.visualstudio.com/items?itemName=AlanWalk.ls-server) or create a custom extension:

```json
{
  "languageServerExample.trace.server": "verbose",
  "languageServerExample.serverPath": "vendor/bin/regex-lsp"
}
```

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

1. Install the [PHPStan plugin](https://plugins.jetbrains.com/plugin/12754-phpstan) for PhpStorm
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

3. Test it directly:
   ```bash
   echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | vendor/bin/regex-lsp
   ```

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
| `regex.lint.charclass.single` | Information | `[a]` is `a` (style, off by default) |
| `regex.lint.literal.multipleSpaces` | Information | a run of literal spaces, ` {2}` (style, off by default) |
| `regex.lint.quantifier.lazyToClass` | Information | `".*?"` where `"[^"\n]*"` reads the run at once (perf, off by default) |
| other `regex.lint.*` | Warning | The [lint rules](../reference.md#quick-reference-table) |

---

## Learn More

- **[CLI Guide](cli.md)** - Command-line usage
- **[Diagnostics Reference](../reference/diagnostics.md)** - All diagnostic codes
- **[Regex Tutorial](../tutorial/README.md)** - Learn regex patterns
- **[PCRE Reference](../concepts/pcre.md)** - PCRE compatibility

---

## Contributing

Found a bug or want to add a feature? Contributions are welcome!

1. Fork the repository
2. Create a feature branch
3. Submit a pull request

---

Previous: [CLI Guide](cli.md) | Next: [Diagnostics Reference](../reference/diagnostics.md)
