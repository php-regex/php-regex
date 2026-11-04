/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The trace shim: see trace.h for the contract. Nothing here writes to
 * stdout or stderr; the document is built in a growing buffer and handed
 * back. The callout records a step while fewer than step_cap steps were
 * recorded (step_cap 0 = unlimited) and the recorded steps weigh less than
 * PCRE2_TRACE_RECORD_BYTES, and always counts; the ovector pairs below
 * capture_top are written only when they differ from the previously
 * recorded step's (always on the first step). The byte cap bounds the
 * document when every step rewrites hundreds of groups: the step cap
 * alone would let it grow past the module's memory.
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define PCRE2_CODE_UNIT_WIDTH 8

/* The recorded steps stop growing past this many bytes of document. */
#define PCRE2_TRACE_RECORD_BYTES (8u * 1024u * 1024u)
#include <pcre2.h>

#include "trace.h"

static const char FATAL[] = "{\"fatal\":\"out of memory\"}";

/* --- a growing byte buffer ----------------------------------------------- */

typedef struct {
    char *data;
    size_t length;
    size_t capacity;
    int failed;
} buffer;

static void buffer_init(buffer *b)
{
    b->data = NULL;
    b->length = 0;
    b->capacity = 0;
    b->failed = 0;
}

static int buffer_reserve(buffer *b, size_t extra)
{
    size_t needed;
    size_t capacity;
    char *grown;

    if (b->failed) {
        return 0;
    }
    needed = b->length + extra + 1;
    if (needed <= b->capacity) {
        return 1;
    }
    capacity = b->capacity ? b->capacity : 1024;
    while (capacity < needed) {
        capacity *= 2;
    }
    grown = (char *) realloc(b->data, capacity);
    if (grown == NULL) {
        b->failed = 1;

        return 0;
    }
    b->data = grown;
    b->capacity = capacity;

    return 1;
}

static void buffer_put(buffer *b, const char *text, size_t length)
{
    if (!buffer_reserve(b, length)) {
        return;
    }
    memcpy(b->data + b->length, text, length);
    b->length += length;
    b->data[b->length] = '\0';
}

static void buffer_puts(buffer *b, const char *text)
{
    buffer_put(b, text, strlen(text));
}

static void buffer_putc(buffer *b, char c)
{
    buffer_put(b, &c, 1);
}

static void buffer_put_int(buffer *b, long long value)
{
    char digits[32];
    int n = snprintf(digits, sizeof digits, "%lld", value);

    buffer_put(b, digits, (size_t) n);
}

static void buffer_put_uint(buffer *b, unsigned long long value)
{
    char digits[32];
    int n = snprintf(digits, sizeof digits, "%llu", value);

    buffer_put(b, digits, (size_t) n);
}

/* True when the bytes are well-formed UTF-8 (no overlong forms, no UTF-16 halves). */
static int utf8_valid(const unsigned char *s, size_t length)
{
    size_t i = 0;

    while (i < length) {
        unsigned char c = s[i];
        size_t n;
        unsigned long code;

        if (c < 0x80) {
            i++;
            continue;
        }
        if (c >= 0xc2 && c <= 0xdf) {
            n = 1;
            code = c & 0x1f;
        } else if (c >= 0xe0 && c <= 0xef) {
            n = 2;
            code = c & 0x0f;
        } else if (c >= 0xf0 && c <= 0xf4) {
            n = 3;
            code = c & 0x07;
        } else {
            return 0;
        }
        if (i + n >= length) {
            return 0;
        }
        for (size_t k = 1; k <= n; k++) {
            if ((s[i + k] & 0xc0) != 0x80) {
                return 0;
            }
            code = (code << 6) | (s[i + k] & 0x3f);
        }
        if ((n == 2 && code < 0x800) || (n == 3 && (code < 0x10000 || code > 0x10ffff)) || (code >= 0xd800 && code <= 0xdfff)) {
            return 0;
        }
        i += n + 1;
    }

    return 1;
}

/*
 * A JSON string: quotes, backslashes and controls escaped; bytes of a
 * well-formed UTF-8 text pass through, any other byte above 0x7f is written
 * as \u00hh so the document stays valid JSON whatever the pattern held.
 */
static void buffer_put_string(buffer *b, const unsigned char *s, size_t length)
{
    static const char hex[] = "0123456789abcdef";
    int utf8 = utf8_valid(s, length);

    buffer_putc(b, '"');
    for (size_t i = 0; i < length; i++) {
        unsigned char c = s[i];

        if (c == '"' || c == '\\') {
            buffer_putc(b, '\\');
            buffer_putc(b, (char) c);
        } else if (c < 0x20 || c == 0x7f || (c >= 0x80 && !utf8)) {
            char escaped[6] = {'\\', 'u', '0', '0', hex[c >> 4], hex[c & 0x0f]};

            buffer_put(b, escaped, 6);
        } else {
            buffer_putc(b, (char) c);
        }
    }
    buffer_putc(b, '"');
}

/* pcre2_get_error_message is ASCII; the buffer is sized for every message. */
static void buffer_put_error_message(buffer *b, int code)
{
    unsigned char message[256];
    int length = pcre2_get_error_message(code, message, sizeof message);

    if (length < 0) {
        length = 0;
    }
    buffer_put_string(b, message, (size_t) length);
}

static void buffer_put_offset(buffer *b, PCRE2_SIZE offset)
{
    buffer_put_uint(b, (unsigned long long) offset);
}

static void buffer_put_pair(buffer *b, PCRE2_SIZE start, PCRE2_SIZE end)
{
    if (start == PCRE2_UNSET || end == PCRE2_UNSET) {
        buffer_puts(b, "null");

        return;
    }
    buffer_putc(b, '[');
    buffer_put_offset(b, start);
    buffer_putc(b, ',');
    buffer_put_offset(b, end);
    buffer_putc(b, ']');
}

/* --- the callout --------------------------------------------------------- */

typedef struct {
    buffer *out;
    uint32_t step_cap;
    unsigned long long steps_total;
    unsigned long long recorded;
    PCRE2_SIZE *previous;      /* the ovector of the last recorded step */
    uint32_t previous_top;     /* its capture_top; 0 before the first step */
    uint32_t pairs;            /* pairs the previous array can hold */
} recorder;

static int capture_changed(const recorder *r, const pcre2_callout_block *block)
{
    uint32_t top = block->capture_top;

    if (r->recorded == 0 || top != r->previous_top || top > r->pairs) {
        return 1;
    }
    for (uint32_t i = 0; i < 2 * top; i++) {
        if (block->offset_vector[i] != r->previous[i]) {
            return 1;
        }
    }

    return 0;
}

static int record(pcre2_callout_block *block, void *data)
{
    recorder *r = (recorder *) data;
    buffer *b;

    r->steps_total++;
    if (r->step_cap != 0 && r->recorded >= r->step_cap) {
        return 0;
    }
    b = r->out;
    if (b->length >= PCRE2_TRACE_RECORD_BYTES) {
        return 0;
    }
    if (r->recorded > 0) {
        buffer_putc(b, ',');
    }
    buffer_puts(b, "{\"p\":");
    buffer_put_offset(b, block->pattern_position);
    buffer_puts(b, ",\"len\":");
    buffer_put_offset(b, block->next_item_length);
    buffer_puts(b, ",\"s\":");
    buffer_put_offset(b, block->current_position);
    buffer_puts(b, ",\"n\":");
    buffer_put_uint(b, block->callout_number);
    buffer_puts(b, ",\"flags\":");
    buffer_put_uint(b, block->callout_flags);
    buffer_puts(b, ",\"start\":");
    buffer_put_offset(b, block->start_match);
    buffer_puts(b, ",\"top\":");
    buffer_put_uint(b, block->capture_top);
    if (capture_changed(r, block)) {
        uint32_t top = block->capture_top;
        uint32_t kept = top <= r->pairs ? top : r->pairs;

        buffer_puts(b, ",\"cap\":[");
        for (uint32_t i = 0; i < top; i++) {
            if (i > 0) {
                buffer_putc(b, ',');
            }
            buffer_put_pair(b, block->offset_vector[2 * i], block->offset_vector[2 * i + 1]);
        }
        buffer_putc(b, ']');
        for (uint32_t i = 0; i < 2 * kept; i++) {
            r->previous[i] = block->offset_vector[i];
        }
        r->previous_top = top;
    }
    buffer_putc(b, '}');
    r->recorded++;

    return 0;
}

/* --- the run ------------------------------------------------------------- */

static void put_compile_error(buffer *b, int code, PCRE2_SIZE offset)
{
    buffer_puts(b, "{\"code\":");
    buffer_put_int(b, code);
    buffer_puts(b, ",\"offset\":");
    buffer_put_offset(b, offset);
    buffer_puts(b, ",\"message\":");
    buffer_put_error_message(b, code);
    buffer_putc(b, '}');
}

static void put_outcome(buffer *b, int rc, pcre2_match_data *match_data)
{
    PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
    uint32_t count = pcre2_get_ovector_count(match_data);
    PCRE2_SPTR mark = pcre2_get_mark(match_data);
    uint32_t pairs = rc > 0 ? (uint32_t) rc : (rc == 0 ? count : 0);

    buffer_puts(b, "{\"rc\":");
    buffer_put_int(b, rc);
    buffer_puts(b, ",\"ovector\":[");
    for (uint32_t i = 0; i < pairs; i++) {
        if (i > 0) {
            buffer_putc(b, ',');
        }
        buffer_put_pair(b, ovector[2 * i], ovector[2 * i + 1]);
    }
    buffer_puts(b, "],\"error\":");
    if (rc == -1) {
        buffer_puts(b, "null");
    } else if (rc > 0) {
        buffer_puts(b, "null");
    } else if (rc == 0) {
        buffer_puts(b, "{\"code\":0,\"message\":\"the ovector of the match data is too small\"}");
    } else {
        buffer_puts(b, "{\"code\":");
        buffer_put_int(b, rc);
        buffer_puts(b, ",\"message\":");
        buffer_put_error_message(b, rc);
        buffer_putc(b, '}');
    }
    buffer_puts(b, ",\"mark\":");
    if (mark == NULL) {
        buffer_puts(b, "null");
    } else {
        buffer_put_string(b, mark, strlen((const char *) mark));
    }
    buffer_putc(b, '}');
}

const char *pcre2_trace_run(const unsigned char *pattern, size_t pattern_length,
                            const unsigned char *subject, size_t subject_length,
                            uint32_t compile_options,
                            uint32_t extra_options,
                            uint32_t match_limit, uint32_t depth_limit,
                            uint32_t step_cap)
{
    buffer out;
    pcre2_compile_context *compile_context = NULL;
    pcre2_match_context *match_context = NULL;
    pcre2_match_data *match_data = NULL;
    pcre2_code *code = NULL;
    recorder r;
    int error_code = 0;
    PCRE2_SIZE error_offset = 0;
    uint32_t capture_count = 0;
    int rc;

    buffer_init(&out);
    buffer_puts(&out, "{\"pcre2\":\"");
    buffer_puts(&out, pcre2_trace_version());
    buffer_puts(&out, "\",\"compile\":{\"error\":");

    compile_context = pcre2_compile_context_create(NULL);
    if (compile_context == NULL) {
        out.failed = 1;
    } else {
        pcre2_set_compile_extra_options(compile_context, extra_options);
        code = pcre2_compile(pattern, pattern_length, compile_options | PCRE2_AUTO_CALLOUT, &error_code, &error_offset, compile_context);
    }

    if (code == NULL && !out.failed) {
        put_compile_error(&out, error_code, error_offset);
        buffer_puts(&out, "},\"steps\":[],\"steps_total\":0,\"truncated\":false,\"outcome\":null}");
        pcre2_compile_context_free(compile_context);
        if (out.failed) {
            free(out.data);

            return FATAL;
        }

        return out.data;
    }
    buffer_puts(&out, "null},\"steps\":[");

    r.out = &out;
    r.step_cap = step_cap;
    r.steps_total = 0;
    r.recorded = 0;
    r.previous = NULL;
    r.previous_top = 0;
    r.pairs = 0;

    if (!out.failed) {
        pcre2_pattern_info(code, PCRE2_INFO_CAPTURECOUNT, &capture_count);
        r.pairs = capture_count + 1;
        r.previous = (PCRE2_SIZE *) malloc(sizeof(PCRE2_SIZE) * 2 * r.pairs);
        match_context = pcre2_match_context_create(NULL);
        match_data = pcre2_match_data_create_from_pattern(code, NULL);
        if (r.previous == NULL || match_context == NULL || match_data == NULL) {
            out.failed = 1;
        }
    }

    if (!out.failed) {
        pcre2_set_callout(match_context, record, &r);
        pcre2_set_match_limit(match_context, match_limit);
        pcre2_set_depth_limit(match_context, depth_limit);
        rc = pcre2_match(code, subject, subject_length, 0, 0, match_data, match_context);

        buffer_puts(&out, "],\"steps_total\":");
        buffer_put_uint(&out, r.steps_total);
        buffer_puts(&out, ",\"truncated\":");
        buffer_puts(&out, r.steps_total > r.recorded ? "true" : "false");
        buffer_puts(&out, ",\"outcome\":");
        put_outcome(&out, rc, match_data);
        buffer_putc(&out, '}');
    }

    free(r.previous);
    pcre2_match_data_free(match_data);
    pcre2_match_context_free(match_context);
    pcre2_code_free(code);
    pcre2_compile_context_free(compile_context);

    if (out.failed) {
        free(out.data);

        return FATAL;
    }

    return out.data;
}

void pcre2_trace_free(const char *json)
{
    if (json != NULL && json != FATAL) {
        free((void *) json);
    }
}

const char *pcre2_trace_version(void)
{
    static char release[32] = "";

    if (release[0] == '\0') {
        unsigned char version[64];
        size_t i;

        if (pcre2_config(PCRE2_CONFIG_VERSION, version) < 0) {
            version[0] = '\0';
        }
        for (i = 0; i + 1 < sizeof release && version[i] != '\0' && version[i] != ' '; i++) {
            release[i] = (char) version[i];
        }
        release[i] = '\0';
    }

    return release;
}
