/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The trace shim as a native command, for the test cell that compares the
 * shim with pcre2test and preg_match() on the host's PCRE2:
 *
 *   pcre2-trace <compile_options> <extra_options> <match_limit> <depth_limit>
 *               <step_cap> <pattern-file> <subject-file>
 *
 * Decimal integers; the pattern and the subject are read as raw bytes from
 * the two files; the trace JSON goes to stdout and the exit code is 0.
 *
 *   pcre2-trace --version
 *   pcre2-trace --headers
 *
 * print the PCRE2 release the shim links, and the release of the headers it
 * was compiled against; the two differ only on a host that pairs one
 * install's headers with another's library. Usage errors and unreadable
 * files go to stderr with exit code 2.
 */

#include <errno.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

/* For PCRE2_MAJOR and PCRE2_MINOR, the release of the headers. */
#define PCRE2_CODE_UNIT_WIDTH 8
#include <pcre2.h>

#include "trace.h"

static int read_file(const char *path, unsigned char **bytes, size_t *length)
{
    FILE *file = fopen(path, "rb");
    unsigned char *data = NULL;
    size_t size = 0;
    size_t capacity = 0;

    if (file == NULL) {
        fprintf(stderr, "pcre2-trace: cannot read %s: %s\n", path, strerror(errno));

        return 0;
    }
    for (;;) {
        size_t got;

        if (size == capacity) {
            unsigned char *grown;

            capacity = capacity ? capacity * 2 : 4096;
            grown = (unsigned char *) realloc(data, capacity);
            if (grown == NULL) {
                fprintf(stderr, "pcre2-trace: out of memory reading %s\n", path);
                free(data);
                fclose(file);

                return 0;
            }
            data = grown;
        }
        got = fread(data + size, 1, capacity - size, file);
        size += got;
        if (got == 0) {
            break;
        }
    }
    if (ferror(file)) {
        fprintf(stderr, "pcre2-trace: cannot read %s: %s\n", path, strerror(errno));
        free(data);
        fclose(file);

        return 0;
    }
    fclose(file);
    if (data == NULL) {
        data = (unsigned char *) malloc(1);
        if (data == NULL) {
            fprintf(stderr, "pcre2-trace: out of memory reading %s\n", path);

            return 0;
        }
    }
    *bytes = data;
    *length = size;

    return 1;
}

static int read_u32(const char *text, uint32_t *value)
{
    char *end = NULL;
    unsigned long long parsed;

    errno = 0;
    parsed = strtoull(text, &end, 10);
    if (errno != 0 || end == text || *end != '\0' || parsed > UINT32_MAX) {
        fprintf(stderr, "pcre2-trace: not a 32-bit decimal: %s\n", text);

        return 0;
    }
    *value = (uint32_t) parsed;

    return 1;
}

int main(int argc, char **argv)
{
    uint32_t numbers[5];
    unsigned char *pattern = NULL;
    unsigned char *subject = NULL;
    size_t pattern_length = 0;
    size_t subject_length = 0;
    const char *json;

    if (argc == 2 && strcmp(argv[1], "--version") == 0) {
        puts(pcre2_trace_version());

        return 0;
    }
    if (argc == 2 && strcmp(argv[1], "--headers") == 0) {
        printf("%d.%02d\n", PCRE2_MAJOR, PCRE2_MINOR);

        return 0;
    }
    if (argc != 8) {
        fprintf(stderr, "usage: pcre2-trace <compile_options> <extra_options> <match_limit> <depth_limit> <step_cap> <pattern-file> <subject-file>\n       pcre2-trace --version\n       pcre2-trace --headers\n");

        return 2;
    }
    for (int i = 0; i < 5; i++) {
        if (!read_u32(argv[1 + i], &numbers[i])) {
            return 2;
        }
    }
    if (!read_file(argv[6], &pattern, &pattern_length)) {
        return 2;
    }
    if (!read_file(argv[7], &subject, &subject_length)) {
        free(pattern);

        return 2;
    }

    json = pcre2_trace_run(pattern, pattern_length, subject, subject_length, numbers[0], numbers[1], numbers[2], numbers[3], numbers[4]);
    fputs(json, stdout);
    putchar('\n');
    pcre2_trace_free(json);
    free(pattern);
    free(subject);

    return 0;
}
