---
description: "PHP prints it once, then matches without its JIT: the engine could not get executable memory, or a pattern needs too large a frame. pcre.jit=0, or the memory."
head_title: "\"Allocation of JIT memory failed\" in PHP"
parent: /reference/diagnostics/
engine_family: "The JIT"
engine_order: 1
quick_fix: "Turn the JIT off: pcre.jit=0"
quick_fix_order: 23
engine:
  short: "Allocation of JIT memory failed"
  message: "Allocation of JIT memory failed, PCRE JIT will be disabled. This is likely caused by security restrictions. Either grant PHP permission to allocate executable memory, or set pcre.jit=0"
  also:
    - "JIT compilation failed: no more memory"
  from: warning
  pattern: "/a/"
  subject: "a"
  outcome: goes on
  source: allocation-of-jit-memory-failed.txt
---
# Allocation of JIT memory failed

PHP compiled the pattern, went to hand it to its JIT, and the JIT could not get what it needs. PHP prints the warning once, turns its JIT off for the rest of the process, and matches every pattern with the interpreter from then on.

```php
// The first preg_*() call of a process that may not hold executable memory
var_dump(preg_match('/^page-(\d+)$/', 'page-42')); // int(1)
// preg_match(): Allocation of JIT memory failed, PCRE JIT will be disabled. This is likely caused by security restrictions. Either grant PHP permission to allocate executable memory, or set pcre.jit=0
```

The warning comes first, then the `int(1)`: the call is not what failed. It returns what the pattern says, `$matches` is filled, and no error is recorded: `preg_last_error()` still says `PREG_NO_ERROR`.

## Why PHP prints it

PCRE2 can compile a pattern a second time, to machine code, so that the match runs faster: that second compiler is the JIT, and PHP uses it by default (`pcre.jit` is `1`). Machine code has to live in memory the process is allowed to execute, and the JIT asks the system for such memory.

When the JIT reports that it could not get the memory (`PCRE2_ERROR_NOMEMORY`), PHP prints this message and sets its own JIT switch off. The lines stand in the sources of every release from PHP 7.3.11 to 8.6.0:

```c
} else if (rc == PCRE2_ERROR_NOMEMORY) {
    php_error_docref(NULL, E_WARNING,
        "Allocation of JIT memory failed, PCRE JIT will be disabled. "
        "This is likely caused by security restrictions. "
        "Either grant PHP permission to allocate executable memory, or set pcre.jit=0");
    PCRE_G(jit) = 0;
}
```

**Before PHP 7.3.11, the same failure said `JIT compilation failed: no more memory`.** The engine's own words for the error code, with no advice: [JIT compilation failed](jit-compilation-failed.md) follows that wording.

**"Likely caused by security restrictions":** that is PHP's diagnosis, and the usual one. A process may be forbidden to hold executable memory: an application signed with the hardened runtime of macOS, which does not allow it unless it asks; a sandbox that forbids it. The installer of Composer printed the earlier wording on macOS at the end of 2018: a PHP 7.3.0 to 7.3.10 is the only one that has it.

**A pattern can also lead there.** The JIT keeps what it needs for a match on a stack frame, and that frame has a limit: 64 KiB in the engines PHP 7.3 to 8.3 bundle. A pattern that needs more — thousands of capturing groups, each one its slot on the frame — makes the JIT give up with the same error code, and PHP prints this message for it.

## How to fix it

**Give PHP executable memory**, when the host is yours: lift the restriction that forbids it to the process.

**Or turn the JIT off.** The interpreter matches the same strings, slower on a long subject. The setting is `pcre.jit`, and it can be changed anywhere:

```php
// Before the first preg_*() call of the process
ini_set('pcre.jit', '0');
var_dump(ini_get('pcre.jit')); // string(1) "0"
```

```php
// On the command line, for one program: the installer of Composer again
// php -d pcre.jit=0 composer.phar install
```

In `php.ini`, for a whole installation: `pcre.jit = 0`.

**A pattern of thousands of capturing groups**: a group that only groups takes no slot on the frame when it does not capture: `(?:…)` where the parentheses are not read in `$matches`. The [rule of the capture nothing reads](../rules/capture-that-never-captures-text.md) finds them in a code base.

## Where it hides

- **Once.** The warning marks the first pattern the process compiled. Every call after it is silent, so a log shows the message a single time, and the JIT stays off until the process ends.
- **An installer, a migration, a queue worker**: a process started where the web server's rules do not apply, in an environment of its own.
- **A pattern built in a loop**, with a capturing group added at each iteration: the frame grows with the iterations until the JIT refuses it.
- **The state of the JIT** reads in what `php -i` prints of PCRE: `PCRE JIT Support => enabled` on a build that has it.

## What PHPRegex reports

Nothing for this pattern: it is sound, and the JIT of the host is not something the library reads. For a pattern too large for the engine itself, the limit on the size of a pattern speaks first, as `regex.pattern.too_large`.

No link opens the playground on this one: its PHP runs with the JIT off, as the note under the tool says.

## Related

- [JIT compilation failed](jit-compilation-failed.md): the other messages of the JIT, and the wordings before PHP 7.3.11.
- [JIT stack limit exhausted](jit-stack-limit-exhausted.md): the JIT, at match time, running out of stack.
- [Parentheses are too deeply nested](parentheses-are-too-deeply-nested.md): another limit a generated pattern meets.
- [Regular expression is too large](regular-expression-is-too-large.md): the engine's own size limit.
- [Capture That Never Captures Text](../rules/capture-that-never-captures-text.md): the rule that finds a group nothing reads.
