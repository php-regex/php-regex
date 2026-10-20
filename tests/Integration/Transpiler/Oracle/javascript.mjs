// Runs transpiled patterns in the JavaScript engine of the running Node.
//
// Reads a JSON batch on stdin:
//   {"cases": [{"pattern": "...", "flags": "...", "whole": false,
//               "subjects": [["text", startInCodeUnits], ...]}, ...]}
// and writes one result per case on stdout:
//   {"error": null | "message",
//    "results": [null | [[start, end] | null, ...], ...],   (UTF-16 code units)
//    "unassigned": [true | false, ...]}
//
// "whole" compiles the pattern as the HTML pattern attribute does,
// "^(?:pattern)$" under the given flags, and reports [[0, length]] for a
// value it accepts. A subject holding a code point this engine's Unicode
// tables leave unassigned is flagged, so that a table older than PCRE2's
// is not read as a divergence.

import { readFileSync } from 'node:fs';

const unassigned = /\p{Cn}/u;
const batch = JSON.parse(readFileSync(0, 'utf8'));
const output = [];

for (const testCase of batch.cases) {
    let regex;
    try {
        regex = testCase.whole
            ? new RegExp('^(?:' + testCase.pattern + ')$', testCase.flags)
            : new RegExp(testCase.pattern, testCase.flags + 'dg');
    } catch (error) {
        output.push({ error: String(error.message), results: [], unassigned: [] });
        continue;
    }

    const results = [];
    const flags = [];
    for (const [subject, start] of testCase.subjects) {
        flags.push(unassigned.test(subject));
        if (testCase.whole) {
            results.push(regex.test(subject) ? [[0, subject.length]] : null);
            continue;
        }

        regex.lastIndex = start;
        const match = regex.exec(subject);
        results.push(null === match ? null : match.indices.map((span) => (undefined === span ? null : [span[0], span[1]])));
    }

    output.push({ error: null, results, unassigned: flags });
}

process.stdout.write(JSON.stringify(output));
