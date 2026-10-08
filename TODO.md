# TODO

## Release by hand

1. **`SPLIT_TOKEN`** — give the fine-grained token *Workflows: read and
   write* as well as *Contents*: GitHub refuses a push that creates or
   changes a file under `.github/workflows/` without it, and every split
   package carries `close-pull-request.yml`. The first push of
   `regex-rector` and `regex-psalm` failed for that reason and was made over
   SSH; a change to that workflow file would fail every split the same way.
2. **The `v2.0.0` tag** — `yoeunes/regex-parser` and `php-regex/php-regex`
   read the same repository, so a `v2.0.0` tag on the monorepo becomes a
   stable 2.0.0 of both, and a bare `composer require yoeunes/regex-parser`
   would install 2.x. Mark `yoeunes/regex-parser` abandoned in favour of
   `php-regex/regex-toolkit`, or point it at a repository holding 1.x only,
   before the tag.
3. **Packagist** — check that every package receives its updates on each
   push (the GitHub integration on the Packagist account, or a webhook per
   repository): without them Packagist crawls a repository about once a
   week.

## Report the PCRE2 JIT crash upstream

Not filed yet. No issue about it existed on
[PCRE2Project/pcre2](https://github.com/PCRE2Project/pcre2/issues) when it was
found (September 2026). Once filed, link the issue from
[docs/concepts/pcre.md](docs/concepts/pcre.md#a-known-jit-crash) and from the
CHANGELOG entry.

Draft:

> **JIT: segfault on a backreference to a group set in a non-atomic lookahead, under a repeated branch reset**
>
> ```
> /(?|(\*)(*napla:(.+))|()(?=\S_(\2?)))+_/
>     *a_
> ```
>
> `pcre2test -jit` crashes on this subject (SIGSEGV); without `-jit` the
> answer is "No match". Found from testinput2 line 6281:
> `^(?|(\*)(*napla:\S*_(\2?+.+))|(\w)(?=\S*_(\2?+\1)))+_\2$` with
> `*a_cb2a1_a_1!_a_Z1a!_Z_Z`.
>
> Reproduced with 10.40, 10.42, 10.45, 10.47, 10.49 and main (ef110b8), built
> with the JIT, on arm64 (macOS) and x86_64 (Linux), and in PHP with
> `pcre.jit=1` (8.2 and 8.5 with 10.42, 8.4 with 10.49, the official
> `php:8.4-cli` image with 10.44).
>
> Every part is needed: the repeated branch reset `(?|...)+`, the non-atomic
> lookahead `(*napla:...)` that captures group 2 in the first branch, and the
> lookahead that captures `(\2?)` as group 2 in the second. Under lldb the
> fault is `EXC_BAD_ACCESS` in the JIT code, in the byte compare loop of the
> `\2` backreference, which seems to read through a stale capture pointer.

## Report upstream to Psalm

- Psalm 6.19's type combiner turns `numeric-string|'a'` into `numeric-string`
  (order-dependent); the Psalm plugin emits no `numeric-string` because of it.
- Psalm's `preg_match_all` stub types `MARK` wrongly under
  `PREG_OFFSET_CAPTURE`.
