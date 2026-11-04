/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The PCRE2 trace shim behind the playground's step debugger: one
 * pcre2_match() compiled with PCRE2_AUTO_CALLOUT, every callout recorded,
 * the record returned as one JSON document. The same source is compiled
 * natively against the host's pcre2 (the test cell) and to WebAssembly
 * against the pinned PCRE2 release (build.sh).
 */

#ifndef PCRE2_TRACE_H
#define PCRE2_TRACE_H

#include <stddef.h>
#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

/*
 * Runs one pcre2_match with PCRE2_AUTO_CALLOUT and records every callout.
 * Returns a NUL-terminated JSON document allocated with malloc; the caller
 * releases it with pcre2_trace_free. Never returns NULL (an allocation
 * failure returns a static document with "fatal").
 *
 * compile_options  PCRE2_* bits as PHP passes them; AUTO_CALLOUT is OR-ed in
 * extra_options    PCRE2_EXTRA_* bits, set on the compile context
 * match_limit      pcre2_set_match_limit (PHP's pcre.backtrack_limit)
 * depth_limit      pcre2_set_depth_limit (PHP's pcre.recursion_limit)
 * step_cap         callouts recorded before recording stops; 0 = no cap
 *                  (the record also stops past 8 MiB of document)
 */
const char *pcre2_trace_run(const unsigned char *pattern, size_t pattern_length,
                            const unsigned char *subject, size_t subject_length,
                            uint32_t compile_options,
                            uint32_t extra_options,
                            uint32_t match_limit, uint32_t depth_limit,
                            uint32_t step_cap);

/* Releases a document returned by pcre2_trace_run (a static one is left). */
void pcre2_trace_free(const char *json);

/* The PCRE2 release the shim links, "10.44": pcre2_config(PCRE2_CONFIG_VERSION)
 * up to its first space. */
const char *pcre2_trace_version(void);

#ifdef __cplusplus
}
#endif

#endif
