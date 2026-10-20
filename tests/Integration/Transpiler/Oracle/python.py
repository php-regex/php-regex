# Runs transpiled patterns in the re module of the running Python.
#
# Reads a JSON batch on stdin:
#   {"cases": [{"pattern": "...", "flags": "imsx", "whole": false,
#               "subjects": [["text", startInCodePoints], ...]}, ...]}
# and writes one result per case on stdout:
#   {"error": null | "message",
#    "results": [null | [[start, end] | null, ...], ...],   (code points)
#    "unassigned": [true | false, ...]}
#
# A subject holding a code point this Python's Unicode database leaves
# unassigned is flagged, so that a database older than PCRE2's is not read
# as a divergence.

import json
import re
import sys
import unicodedata
import warnings

warnings.simplefilter('ignore')

FLAGS = {'a': re.ASCII, 'i': re.IGNORECASE, 'm': re.MULTILINE, 's': re.DOTALL, 'x': re.VERBOSE}


def unassigned(subject):
    return any(unicodedata.category(character) == 'Cn' for character in subject)


def run(case):
    flags = 0
    for letter in case['flags']:
        flags |= FLAGS[letter]
    try:
        regex = re.compile(case['pattern'], flags)
    except (re.error, OverflowError, RecursionError, ValueError) as error:
        return {'error': str(error), 'results': [], 'unassigned': []}

    results = []
    for subject, start in case['subjects']:
        match = regex.search(subject, start)
        if match is None:
            results.append(None)
            continue
        results.append([None if match.span(group) == (-1, -1) else list(match.span(group)) for group in range(regex.groups + 1)])

    return {'error': None, 'results': results, 'unassigned': [unassigned(subject) for subject, _ in case['subjects']]}


batch = json.loads(sys.stdin.buffer.read())
sys.stdout.write(json.dumps([run(case) for case in batch['cases']]))
