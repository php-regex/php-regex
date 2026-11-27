---
description: "PHP warns, then matches without its JIT: a pattern of thousands of capturing groups. The wordings by release, the count measured, and pcre.jit=0."
head_title: "\"JIT compilation failed\" in PHP: no more memory"
parent: /reference/diagnostics/
engine_family: "The JIT"
engine_order: 2
quick_fix: "pcre.jit=0, or groups that do not capture"
quick_fix_order: 24
engine:
  short: "JIT compilation failed"
  message: "JIT compilation failed: no more memory"
  also:
    - "JIT compilation failed: feature is not supported by the JIT compiler"
  from: warning
  repeat:
    delimiter: "/"
    item: "(a)"
    count: 6000
  subject: "a"
  outcome: goes on
  source: jit-compilation-failed.txt
---
# JIT compilation failed

PHP compiled the pattern, went to hand it to its JIT, and the JIT returned an error. PHP prints the warning and goes on: the pattern is cached, and the match runs with the interpreter.

```php
$pattern = '/'.str_repeat('(a)', 6000).'/'; // 6000 capturing groups
var_dump(preg_match($pattern, str_repeat('a', 6000))); // int(1)
// preg_match(): JIT compilation failed: feature is not supported by the JIT compiler
```

The warning comes first, then the `int(1)`. Nothing is lost but speed: the call returns what the pattern says, `$matches` is filled, and `preg_last_error()` still says `PREG_NO_ERROR`.

## Why PHP prints it

PCRE2 can compile a pattern a second time, to machine code, so that the match runs faster: that second compiler is the JIT, and PHP uses it by default (`pcre.jit` is `1`). When the JIT refuses the pattern, PHP takes the text PCRE2 gives the error and prints it after `JIT compilation failed: `:

```c
} else {
    pcre2_get_error_message(rc, error, sizeof(error));
    php_error_docref(NULL, E_WARNING, "JIT compilation failed: %s", error);
    pcre_handle_exec_error(PCRE2_ERROR_INTERNAL);
}
```

Two texts follow the colon, one per release.

**`JIT compilation failed: no more memory`** is what PHP 7.3.0 to 7.3.10 printed when the JIT could not get the memory it needs. PHP 7.3.11 gave that failure a message of its own, with its fix: [Allocation of JIT memory failed](allocation-of-jit-memory-failed.md).

**`JIT compilation failed: feature is not supported by the JIT compiler`** is what the PCRE2 release 10.49 prints for a pattern whose JIT stack frame is over its limit. The JIT keeps what a match needs on a frame, and each capturing group takes a slot on it: the pattern of the example, with 6000 of them, is over. Measured here, on a PHP 8.4.28 linked with that release: 2 728 capturing groups compile, 2 729 do not. The engines PHP 7.3 to 8.3 bundle have the same frame of 64 KiB, and answer the lack of memory for a pattern over it: the other message. Those PHP 8.4 and later bundle give the JIT a frame of 1 MiB, read in their sources: this pattern would not reach it, the engine refusing a pattern of about 12 000 such groups as too large.

## How to fix it

**A group that only groups does not capture.** Written `(?:…)`, it takes no slot on the frame. The example compiles with the JIT once its groups stop capturing:

```php
$pattern = '/'.str_repeat('(?:a)', 6000).'/'; // 6000 groups, none capturing
var_dump(preg_match($pattern, str_repeat('a', 6000))); // int(1)
```

**Or turn the JIT off.** The interpreter matches the same strings, slower on a long subject, and the warning goes with the JIT. The setting is `pcre.jit`, and it can be changed anywhere:

```php
ini_set('pcre.jit', '0'); // before the first preg_*() call of the process
var_dump(ini_get('pcre.jit')); // string(1) "0"
```

In `php.ini`, for a whole installation: `pcre.jit = 0`. On the command line, for one program: `php -d pcre.jit=0 script.php`.

## Where it hides

- **A pattern built in a loop**, a capturing group added at each iteration: the frame grows with the iterations.
- **Alternatives wrapped one around the other**, each in its capturing group.
- **The warning repeats.** Nothing is turned off: the next pattern of as many groups warns again. The same pattern, kept in the cache, warns once.
- **The state of the JIT** reads in what `php -i` prints of PCRE: `PCRE JIT Support => enabled`, and `pcre.jit => On`.

## What PHPRegex reports

Nothing for this pattern: it is sound, and the JIT of the host is not something the library reads. A pattern of more groups still is refused by the engine itself, as [regular expression is too large](regular-expression-is-too-large.md), and the library names that under `regex.pattern.too_large`.

No link opens the playground on this one: the tool takes a pattern of 2000 bytes at most, and the example is longer.

## Related

- [Allocation of JIT memory failed](allocation-of-jit-memory-failed.md): the message of the same failure since PHP 7.3.11.
- [JIT stack limit exhausted](jit-stack-limit-exhausted.md): the JIT, at match time, running out of stack.
- [Parentheses are too deeply nested](parentheses-are-too-deeply-nested.md): another limit a generated pattern meets.
- [Regular expression is too large](regular-expression-is-too-large.md): the engine's own size limit.
- [Capture That Never Captures Text](../rules/capture-that-never-captures-text.md): the rule that finds a group nothing reads.
