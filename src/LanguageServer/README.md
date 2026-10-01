<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Language Server" width="100%">
    </picture>
</p>

PHPRegex Language Server
========================

A Language Server for the regex patterns of PHP files: diagnostics, hovers, completions and code actions in any LSP editor.

The server reads PHP source over stdio, finds the regex literals in it — the patterns of `preg_*` calls and of wrapper calls such as `Preg::match()` — and answers in JSON-RPC, so every LSP editor can check patterns while you type.

Features
--------

- Diagnostics on open and on change: every pattern of the file is parsed and linted, and the findings are published to the editor.
- Hovers in plain English: hold the pointer on a pattern to read what it matches, token by token.
- Completions inside patterns: shorthands after a backslash, Unicode properties after `\p{`, POSIX classes after `[:`, groups after `(?`, flags after the closing delimiter.
- Code actions: one action adds the `u` flag a Unicode pattern lacks, one applies the optimizer's rewrite — `/[0-9]{1,}/` becomes `/\d+/`.
- One target per workspace: patterns are judged for one PHP and PCRE2 pair, resolved from the editor settings, `regex.json`, `composer.json` or the running PHP.

Installation
------------

```bash
composer require --dev php-regex/regex-language-server
```

Requires PHP 8.2+. The binary is installed as `vendor/bin/regex-lsp`; `--help` prints its options, `--version` the release it was built from.

Configuration
-------------

Every pattern of the workspace is judged for one target, a PHP and PCRE2 pair, resolved once at `initialize` and logged where the editor shows the server output (`Target: PHP 8.4, PCRE2 10.49 (running PHP)`).

| Option | Accepts | When absent |
|--------|---------|-------------|
| `initializationOptions.phpVersion` | `"8.2"`, `"8.2.4"` or `80200` | `phpVersion` in `regex.json`, then `composer.json`, then the running PHP |
| `initializationOptions.pcreVersion` | a PCRE2 release, `"10.42"` for instance | `pcreVersion` in `regex.json`, then the release the target PHP bundles |

`regex.json` (and `regex.dist.json`) sits at the root of the first workspace folder. A value the server cannot read is logged as a warning and the next source is used. Changing the target takes a server restart.

Usage
-----

A file opens, and a broken pattern is reported where it sits. This notification was captured from a live session:

```json
{"jsonrpc":"2.0","method":"textDocument/publishDiagnostics","params":{"uri":"file:///demo/Mailer.php","diagnostics":[
  {"range":{"start":{"line":2,"character":13},"end":{"line":2,"character":16}},"severity":1,"code":"regex.delimiter.unclosed",
   "source":"php-regex","message":"No closing delimiter \"/\" found. You opened with \"/\"; expected closing \"/\". Tip: escape \"/\" inside the pattern (\\/) or use a different delimiter, e.g. #[a-#."}]}}
```

Hovering the pattern `/^\w+@\w+\.\w+$/` returns this markdown (abridged):

````text
**Regex Pattern**

```
/^\w+@\w+\.\w+$/
```

**Explanation**

Regex matches
  Anchor: the beginning of a line
    Character Type: A word character: [a-zA-Z_0-9] (one or more times)
  …
  Anchor: the end of a line
````

Code actions are offered on the pattern they fix:

* `/\p{L}+/` without the `u` flag gets "Add /u flag for Unicode support", rewriting the literal to `'/\p{L}+/u'`.
* `/[0-9]{1,}/` gets "Apply regex optimization", rewriting the literal to `'/\d+/'`.

Integration
-----------

### VS Code

Any LSP client extension; `.vscode/settings.json`:

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

### PhpStorm

Install LSP4IJ from the JetBrains Marketplace, then Settings → Languages & Frameworks → Language Servers: a server definition named PHPRegex, command `vendor/bin/regex-lsp`, file mappings `*.php`.

### Neovim

`init.lua` with nvim-lspconfig:

```lua
local lspconfig = require('lspconfig')
local configs = require('lspconfig.configs')
if not configs.php_regex then
  configs.php_regex = {
    default_config = {
      cmd = { 'vendor/bin/regex-lsp' },
      filetypes = { 'php' },
      root_dir = lspconfig.util.root_pattern('composer.json', '.git'),
    },
  }
end
lspconfig.php_regex.setup({})
```

Vim, Emacs, Sublime Text, Helix and Zed are wired the same way; the [LSP guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/lsp.md#ide-configuration) holds each snippet.

Documentation
-------------

- [LSP guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/lsp.md) — editor configurations, supported methods, troubleshooting.
- [Diagnostics reference](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/diagnostics.md) — the codes the server publishes and how to read them.
- [Backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md) — what stays stable across releases.

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)
* [Changelog](CHANGELOG.md)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).
